<?php

declare(strict_types=1);

namespace App\Tests\Functional\Review;

use App\Identity\Domain\User;
use App\Review\Api\ReportDraftRefused;
use App\Review\Api\RoundRefused;
use App\Review\UI\ReportDraftController;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use App\Tests\Functional\Web\IssuesCsrfTokens;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * The "Report drafts" pages (P06.T1 step 5, ADR-0039, ADR-0040). Mia has no role on Shop, as anyone may report a
 * problem (ADR-0038); Lena leads Shop, Omar is a member, Sara is an admin. Drafts are written straight into
 * rvw_report_draft, as create_report leaves them. The clock is frozen on 6 Nov 2026.
 */
final class ReportDraftControllerTest extends WebTestCase
{
    use CreatesAcmeFoodsTeams;
    use CreatesTeams;
    use Factories;
    use IssuesCsrfTokens;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }
    use SeedsTicketRounds;

    private const string NOW = '2026-11-06 12:00:00 UTC';
    private const string LIST = '/report-drafts';
    /** With the draft's number, 01 to 21; Omar's ids sort above Mia's. */
    private const string MIAS_PAGED_DRAFT = '0197a3c0-0000-7000-8000-0000000d00%02d';
    private const string OMARS_PAGED_DRAFT = '0197a3c0-0000-7000-8000-0000000e00%02d';

    private KernelBrowser $client;

    /** @var array<string, User> */
    private array $people;

    /** Read by SeedsTicketRounds. */
    protected string $shopProject;
    private string $stepId;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTPS' => 'on']);
        $this->client->disableReboot();
        self::getContainer()->set('clock', new MockClock(new \DateTimeImmutable(self::NOW)));
        $this->people = $this->createAcmeFoodsPeople(null);
        $this->shopProject = $this->addProject($this->shop, 'SHOP', 'Shop');
        $this->stepId = Uuid::v7()->toRfc4122();
        $organizationId = $this->acmeFoods->toString();
        $todoStepId = $this->stepId;
        $backlogStepId = Uuid::v7()->toRfc4122();
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId, $todoStepId, $backlogStepId): int {
            $migrator->executeStatement("INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, 'Backlog', 'todo', 'backlog', 'core', 0)", [$organizationId, $backlogStepId]);
            $migrator->executeStatement("INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, 'To do', 'todo', 'todo', 'core', 1)", [$organizationId, $todoStepId]);

            return 0;
        });
    }

    protected function tearDown(): void
    {
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->committedAs(new OrganizationId($organizationId), static fn (Connection $migrator): int|string => $migrator->executeStatement('DELETE FROM rvw_report_draft WHERE organization_id = ?', [$organizationId]));
        }
        $this->removeSeededRounds();
        $this->removeCommittedProjects();
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function theListShowsOnlyTheOwnersDraftsAndNobodyElseOpensOne(): void
    {
        $miasDraft = $this->addDraft('Mia', 'Total is doubled on the cart', []);
        $this->addDraft('Omar', 'Omar own draft', []);

        $this->logIn($this->client, $this->people['Mia']);
        $list = $this->client->request('GET', self::LIST);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Total is doubled on the cart'], $list->filter('.report-drafts__card h2')->each(static fn ($heading): string => $heading->text()));
        self::assertSame(0, $list->filter('.empty-state')->count());

        $this->logIn($this->client, $this->people['Lena']);
        $empty = $this->client->request('GET', self::LIST);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(0, $empty->filter('.report-drafts__card')->count());
        self::assertSame('You have no report drafts yet. To make one, pick “A new report” in the browser extension, or ask Claude Code to report a problem. Both need an API token that may prepare report drafts.', $empty->filter('.empty-state p')->text());
        self::assertSame('/me/tokens', $empty->filter('.empty-state__action a')->attr('href'));

        $this->logIn($this->client, $this->people['Mia']);
        $this->client->request('GET', self::LIST.'/'.$miasDraft);
        self::assertResponseStatusCodeSame(200);

        foreach (['Omar', 'Lena', 'Sara', 'Raj'] as $person) {
            $this->logIn($this->client, $this->people[$person]);
            $this->client->request('GET', self::LIST.'/'.$miasDraft);
            self::assertResponseStatusCodeSame(404, $person);
            self::assertStringContainsString(ReportDraftRefused::NOT_FOUND, (string) $this->client->getResponse()->getContent(), $person);
        }
        $this->client->request('GET', self::LIST.'/not-a-uuid');
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Mia's draft n is changed at minute n / 3, so they tie in threes, and drafts 1 and 2, on both sides of the page
     * break, tie to the microsecond; ids rise with n, so the order is draft 21 down to draft 1. They are inserted out
     * of that order. Omar's drafts sit at the same times with higher ids and would come between them if the owner
     * were not in the query.
     */
    #[Test]
    public function theListShowsTwentyDraftsAndShowMoreOpensTheRest(): void
    {
        foreach ([11, 2, 20, 5, 17, 1, 14, 8, 21, 3, 12, 19, 6, 15, 9, 4, 18, 10, 13, 16, 7] as $number) {
            $updatedAt = \sprintf('2026-11-05 08:%02d:00.5+00', intdiv($number, 3));
            $screenshots = 21 === $number ? [self::screenshotOf(Uuid::v7()->toRfc4122()), self::screenshotOf(Uuid::v7()->toRfc4122())] : [];
            $this->addDraftAt('Mia', \sprintf(self::MIAS_PAGED_DRAFT, $number), 'Draft '.$number, $updatedAt, $screenshots);
            if (0 === $number % 5) {
                $this->addDraftAt('Omar', \sprintf(self::OMARS_PAGED_DRAFT, $number), 'Omar draft '.$number, $updatedAt, []);
            }
        }
        $this->logIn($this->client, $this->people['Mia']);

        $first = $this->client->request('GET', self::LIST);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(array_map(static fn (int $number): string => 'Draft '.$number, range(21, 2)), $first->filter('.report-drafts__card h2')->each(static fn ($heading): string => $heading->text()));
        self::assertSame(['SHOP · 2 screenshots', 'SHOP · 0 screenshots'], [$first->filter('.report-drafts__card p')->eq(0)->text(), $first->filter('.report-drafts__card p')->eq(1)->text()]);
        $more = $first->filter('a[rel=next]');
        self::assertSame(['Show more'], $more->each(static fn ($link): string => trim($link->text())));
        $secondDraftId = \sprintf(self::MIAS_PAGED_DRAFT, 2);
        self::assertSame(self::LIST.'?'.ReportDraftController::AFTER_QUERY.'='.new \DateTimeImmutable('2026-11-05 08:00:00.5+00')->format('U.u').'.'.$secondDraftId, $more->attr('href'));

        $second = $this->client->request('GET', $more->attr('href'));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Draft 1'], $second->filter('.report-drafts__card h2')->each(static fn ($heading): string => $heading->text()));
        self::assertSame(0, $second->filter('a[rel=next]')->count(), 'Nothing follows draft 1.');
    }

    #[Test]
    public function aMalformedListCursorIsNotFound(): void
    {
        $this->addDraft('Mia', 'Total is doubled on the cart', []);
        $this->logIn($this->client, $this->people['Mia']);
        $draftId = \sprintf(self::MIAS_PAGED_DRAFT, 1);

        $malformed = [
            'empty' => '',
            'words' => 'next',
            'no microseconds' => '1762329600.'.$draftId,
            'short microseconds' => '1762329600.5.'.$draftId,
            'uppercase id' => '1762329600.500000.'.strtoupper($draftId),
            'trailing newline' => "1762329600.500000.{$draftId}\n",
        ];
        foreach ($malformed as $case => $after) {
            $this->client->request('GET', self::LIST, [ReportDraftController::AFTER_QUERY => $after]);
            self::assertResponseStatusCodeSame(404, $case);
            self::assertStringContainsString(ReportDraftController::LIST_PAGE_NOT_FOUND, (string) $this->client->getResponse()->getContent(), $case);
        }
        $this->client->request('GET', self::LIST, [ReportDraftController::AFTER_QUERY => ['1762329600.500000.'.$draftId]]);
        self::assertResponseStatusCodeSame(404, 'A list of cursors');

        $page = $this->client->request('GET', self::LIST, [ReportDraftController::AFTER_QUERY => '99999999999.000000.'.$draftId]);
        self::assertResponseStatusCodeSame(200, 'A well-formed cursor of no draft starts the page at its place.');
        self::assertSame(['Total is doubled on the cart'], $page->filter('.report-drafts__card h2')->each(static fn ($heading): string => $heading->text()));
        $page = $this->client->request('GET', self::LIST, [ReportDraftController::AFTER_QUERY => '0.000000.'.$draftId]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(0, $page->filter('.report-drafts__card')->count());
        self::assertSame('No more report drafts.', $page->filter('.empty-state p')->text());
        self::assertSame(0, $page->filter('.empty-state__action')->count(), 'A later page does not explain how to make the first one.');
    }

    #[Test]
    public function sendMakesTheTicketWithRoundZeroAndTheDraftIsGone(): void
    {
        $first = $this->addMedia('ready');
        $second = $this->addMedia('ready');
        $draft = $this->addDraft('Mia', 'Total is doubled on the cart', [
            ['media_id' => $first, 'title' => 'Case B', 'summary' => 'The total shows twice the sum.', 'pins' => [['x_pct' => 10.5, 'y_pct' => 20.0, 'note' => 'Total'], ['x_pct' => 60.0, 'y_pct' => 70.25, 'note' => 'Sum']]],
            ['media_id' => $second, 'title' => null, 'summary' => null, 'pins' => []],
        ]);
        $this->logIn($this->client, $this->people['Mia']);

        $this->client->request('POST', self::LIST.'/'.$draft.'/send', [...$this->formFields($draft, 1), 'title' => 'Total is doubled on the cart']);

        self::assertResponseStatusCodeSame(303);
        self::assertSame('/t/SHOP-1', $this->client->getResponse()->headers->get('Location'));
        $page = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSame(\sprintf(ReportDraftController::SENT, 'SHOP-1'), $page->filter('.toast-region [data-toast-message-value]')->attr('data-toast-message-value'));
        $ticket = $this->rowOf('SELECT id, title, reporter_id, assignee_id FROM tkt_ticket WHERE key = ?', ['SHOP-1']);
        self::assertSame(['Total is doubled on the cart', $this->people['Mia']->id()->toString(), null], [$ticket['title'], $ticket['reporter_id'], $ticket['assignee_id']]);
        self::assertIsString($ticket['id']);
        $rounds = $this->roundRowsOf($ticket['id']);
        self::assertCount(1, $rounds);
        self::assertSame(['report', 0], [$rounds[0]['kind'], $rounds[0]['number']]);
        $roundId = $rounds[0]['id'];
        self::assertIsString($roundId);
        self::assertSame([[$first, 'Case B', 'The total shows twice the sum.'], [$second, null, null]], array_map(static fn (array $row): array => [$row['media_id'], $row['title'], $row['summary']], $this->rowsOf('SELECT media_id, title, summary FROM rvw_screenshot WHERE round_id = ? ORDER BY position', [$roundId])));
        self::assertSame([[1, '10.50', '20.00', 'Total'], [2, '60.00', '70.25', 'Sum']], array_map(static fn (array $row): array => [$row['number'], $row['x_pct'], $row['y_pct'], $row['note']], $this->pinRowsOf($roundId)));
        self::assertSame(0, $this->draftCount());
    }

    /**
     * Send makes the ticket as Report a problem does (TicketCommands::report): a security word makes it team-only and
     * risky, and an empty detail with no pin is little info, so Shop's lead, Lena, gets the check.
     */
    #[Test]
    public function aSentDraftGetsItsCheckReasonsAndTeamOnlyVisibilityFromItsWords(): void
    {
        $draft = $this->addDraft('Mia', 'Password shown in the address bar', []);
        $this->logIn($this->client, $this->people['Mia']);

        $this->client->request('POST', self::LIST.'/'.$draft.'/send', [...$this->formFields($draft, 1), 'title' => 'Password shown in the address bar']);

        self::assertResponseStatusCodeSame(303);
        self::assertSame(['team', '{info,risk}', $this->shop->toString()], array_values($this->rowOf('SELECT visibility, check_reasons::text AS reasons, team_id FROM tkt_ticket WHERE key = ?', ['SHOP-1'])));
        self::assertSame([$this->people['Lena']->id()->toString(), 'check'], array_values($this->rowOf('SELECT turn.turn_user_id, turn.kind FROM ins_ticket_turn turn JOIN tkt_ticket t ON t.organization_id = turn.organization_id AND t.id = turn.ticket_id WHERE t.key = ?', ['SHOP-1'])));
    }

    #[Test]
    public function nobodyButTheOwnerCanSendDeleteOrSaveAndNothingChanges(): void
    {
        $draft = $this->addDraft('Mia', 'Total is doubled on the cart', []);

        foreach (['Omar', 'Lena', 'Sara'] as $person) {
            $this->logIn($this->client, $this->people[$person]);
            foreach (['/send', '/delete', ''] as $action) {
                $this->client->request('POST', self::LIST.'/'.$draft.$action, [...$this->formFields($draft, 1), 'title' => 'Changed by '.$person]);
                self::assertResponseStatusCodeSame(404, $person.$action);
            }
        }

        self::assertSame(['Total is doubled on the cart', 1], array_values($this->rowOf('SELECT title, version FROM rvw_report_draft WHERE id = ?', [$draft])));
        self::assertSame(0, $this->rowCountOf('SELECT count(*) FROM tkt_ticket'));
    }

    /**
     * Send saves the form before it sends, so a refusal after the save rolls the save back too, and the form shows
     * what was typed, so nothing typed is lost.
     */
    #[Test]
    public function aPictureStillProcessingRefusesSendKeepsTheDraftAndTheTypedText(): void
    {
        $draft = $this->addDraft('Mia', 'Total is doubled on the cart', [['media_id' => $this->addMedia('pending'), 'title' => null, 'summary' => null, 'pins' => []]]);
        $this->logIn($this->client, $this->people['Mia']);

        $page = $this->client->request('POST', self::LIST.'/'.$draft.'/send', [...$this->formFields($draft, 1), 'title' => 'Total is doubled at checkout', 'screenshots' => [['summary' => 'Still loading']]]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(RoundRefused::MEDIA_STILL_PROCESSING, $page->filter('#report-draft-error')->text());
        self::assertSame('Total is doubled at checkout', $page->filter('input[name=title]')->attr('value'));
        self::assertSame('Still loading', $page->filter('textarea[name="screenshots[0][summary]"]')->text());
        self::assertSame(['Total is doubled on the cart', 1], array_values($this->rowOf('SELECT title, version FROM rvw_report_draft WHERE id = ?', [$draft])));
        self::assertSame(0, $this->rowCountOf('SELECT count(*) FROM tkt_ticket'), 'The ticket made before the refusal is rolled back.');
    }

    #[Test]
    public function sendSavesWhatTheFormHoldsAndTheTicketHasIt(): void
    {
        $media = $this->addMedia('ready');
        $draft = $this->addDraft('Mia', 'Total is doubled on the cart', [['media_id' => $media, 'title' => 'Cart', 'summary' => null, 'pins' => [['x_pct' => 10.0, 'y_pct' => 20.0, 'note' => 'Total']]]]);
        $this->logIn($this->client, $this->people['Mia']);

        $this->client->request('POST', self::LIST.'/'.$draft.'/send', [
            ...$this->formFields($draft, 1),
            'title' => 'Total is doubled at checkout',
            'detail' => 'Seen twice.',
            'screenshots' => [['title' => 'Checkout', 'summary' => 'The sum line.', 'pins' => ['Sum shown twice']]],
        ]);

        self::assertResponseStatusCodeSame(303);
        self::assertSame('/t/SHOP-1', $this->client->getResponse()->headers->get('Location'));
        $ticket = $this->rowOf('SELECT id, title, description FROM tkt_ticket WHERE key = ?', ['SHOP-1']);
        self::assertSame(['Total is doubled at checkout', 'Seen twice.'], [$ticket['title'], $ticket['description']]);
        self::assertIsString($ticket['id']);
        $rounds = $this->roundRowsOf($ticket['id']);
        self::assertCount(1, $rounds);
        $roundId = $rounds[0]['id'];
        self::assertIsString($roundId);
        self::assertSame([[$media, 'Checkout', 'The sum line.']], array_map(static fn (array $row): array => [$row['media_id'], $row['title'], $row['summary']], $this->rowsOf('SELECT media_id, title, summary FROM rvw_screenshot WHERE round_id = ? ORDER BY position', [$roundId])));
        self::assertSame(['Sum shown twice'], array_map(static fn (array $row): mixed => $row['note'], $this->pinRowsOf($roundId)));
        self::assertSame(0, $this->draftCount());
    }

    #[Test]
    public function sendIsTheEditFormsSecondButtonAndSaveComesFirst(): void
    {
        $draft = $this->addDraft('Mia', 'Total is doubled on the cart', []);
        $this->logIn($this->client, $this->people['Mia']);

        $page = $this->client->request('GET', self::LIST.'/'.$draft);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            [['Save changes', null], ['Send', self::LIST.'/'.$draft.'/send']],
            $page->filter('form.report-draft__form button[type=submit]')->each(static fn ($button): array => [trim($button->text()), $button->attr('formaction')]),
        );
        self::assertSame(0, $page->filter('form[action$="/send"]')->count(), 'No form of its own posts Send without the fields.');
    }

    #[Test]
    public function theDraftPageAndItsRefusalUseTheirOwnClassesNotTheLoginOrSecurityPages(): void
    {
        $draft = $this->addDraft('Mia', 'Total is doubled on the cart', [['media_id' => $this->addMedia('ready'), 'title' => null, 'summary' => null, 'pins' => []]]);
        $this->logIn($this->client, $this->people['Mia']);

        $shown = $this->client->request('GET', self::LIST.'/'.$draft);
        self::assertResponseStatusCodeSame(200);
        $refused = $this->client->request('POST', self::LIST.'/'.$draft, [...$this->formFields($draft, 1), 'title' => 'Total doubled', 'screenshots' => [['summary' => str_repeat('s', 601)]]]);
        self::assertResponseStatusCodeSame(422);

        foreach (['shown' => $shown, 'refused' => $refused] as $state => $page) {
            self::assertSame(1, $page->filter('.report-draft > a.report-draft__back[href="'.self::LIST.'"]')->count(), $state);
            self::assertSame(1, $page->filter('.report-draft > h1.report-draft__title')->count(), $state);
            self::assertSame(1, $page->filter('form.report-draft__form label.form-field > input.form-field__input[name=title]')->count(), $state);
            self::assertSame(0, $page->filter('[class*="login__"], [class~="security"], [class*="security__"]')->count(), $state);
            self::assertSame(0, $page->filter('link[href*="login"], link[href*="security"]')->count(), $state);
        }
        self::assertSame(ReportDraftRefused::PICTURE_SUMMARY_TOO_LONG, $refused->filter('p.report-draft__error[role=alert]')->text());
    }

    #[Test]
    public function aSummaryOverItsCapIsRefusedTheFormKeepsTheTextAndNothingIsSaved(): void
    {
        $draft = $this->addDraft('Mia', 'Total is doubled on the cart', [['media_id' => $this->addMedia('ready'), 'title' => null, 'summary' => null, 'pins' => []]]);
        $this->logIn($this->client, $this->people['Mia']);
        $tooLong = str_repeat('s', 601);

        $page = $this->client->request('POST', self::LIST.'/'.$draft, [...$this->formFields($draft, 1), 'title' => 'Total doubled', 'detail' => 'More', 'screenshots' => [['summary' => $tooLong]]]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(ReportDraftRefused::PICTURE_SUMMARY_TOO_LONG, $page->filter('#report-draft-error')->text());
        self::assertSame('Total doubled', $page->filter('input[name=title]')->attr('value'));
        self::assertSame($tooLong, $page->filter('textarea[name="screenshots[0][summary]"]')->text());
        self::assertSame(['Total is doubled on the cart', 1], array_values($this->rowOf('SELECT title, version FROM rvw_report_draft WHERE id = ?', [$draft])));
    }

    #[Test]
    public function aSaveFromAnOlderPageOrAnExpiredFormChangesNothing(): void
    {
        $draft = $this->addDraft('Mia', 'Total is doubled on the cart', []);
        $this->logIn($this->client, $this->people['Mia']);

        $this->client->request('POST', self::LIST.'/'.$draft, [...$this->formFields($draft, 2), 'title' => 'From an older tab']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString(ReportDraftRefused::DRAFT_CHANGED, (string) $this->client->getResponse()->getContent());

        $this->client->request('POST', self::LIST.'/'.$draft, [ReportDraftController::CSRF_FIELD => 'expired', ReportDraftController::VERSION_FIELD => '1', 'title' => 'Expired form']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString(ReportDraftController::FORM_EXPIRED, (string) $this->client->getResponse()->getContent());

        self::assertSame(['Total is doubled on the cart', 1], array_values($this->rowOf('SELECT title, version FROM rvw_report_draft WHERE id = ?', [$draft])));
    }

    /**
     * One tab saves version 1 to 2 and removes the first picture; a tab still on version 1 then saves. Its posted text
     * and Remove tick, matched by position, would land on the second picture, so the form shows the fresh draft only.
     */
    #[Test]
    public function aStaleSaveShowsTheFreshDraftWithoutThePostedValues(): void
    {
        $first = $this->addMedia('ready');
        $second = $this->addMedia('ready');
        $draft = $this->addDraft('Mia', 'Total is doubled on the cart', [
            ['media_id' => $first, 'title' => 'Cart', 'summary' => 'The cart page.', 'pins' => []],
            ['media_id' => $second, 'title' => 'Sum', 'summary' => 'The sum line.', 'pins' => []],
        ]);
        $this->logIn($this->client, $this->people['Mia']);
        $this->client->request('POST', self::LIST.'/'.$draft, [...$this->formFields($draft, 1), 'title' => 'Total is doubled at checkout', 'detail' => 'Seen twice.', 'screenshots' => [['remove' => '1']]]);
        self::assertResponseStatusCodeSame(303);
        $fresh = $this->rowOf('SELECT title, detail, screenshots::text AS screenshots, version FROM rvw_report_draft WHERE id = ?', [$draft]);
        self::assertSame(2, $fresh['version']);

        $page = $this->client->request('POST', self::LIST.'/'.$draft, [...$this->formFields($draft, 1), 'title' => 'From an older tab', 'detail' => 'Older detail', 'screenshots' => [['summary' => 'About the cart page', 'remove' => '1']]]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(ReportDraftRefused::DRAFT_CHANGED, $page->filter('#report-draft-error')->text());
        self::assertSame('2', $page->filter('form.report-draft__form input[name=version]')->attr('value'));
        self::assertSame('Total is doubled at checkout', $page->filter('input[name=title]')->attr('value'));
        self::assertSame('Seen twice.', $page->filter('textarea[name=detail]')->text());
        self::assertSame('The sum line.', $page->filter('textarea[name="screenshots[0][summary]"]')->text());
        self::assertSame(0, $page->filter('input[name="screenshots[0][remove]"][checked]')->count());
        self::assertSame($fresh, $this->rowOf('SELECT title, detail, screenshots::text AS screenshots, version FROM rvw_report_draft WHERE id = ?', [$draft]));
    }

    /**
     * Each Send posts an edited title; none of them saves it or makes a ticket. A stale version shows the fresh draft,
     * as a stale Save does; the others keep what was typed.
     */
    #[Test]
    public function sendFromAnOlderPageAnExpiredFormOrWithABlankTitleSavesNothingAndMakesNoTicket(): void
    {
        $draft = $this->addDraft('Mia', 'Total is doubled on the cart', []);
        $this->logIn($this->client, $this->people['Mia']);

        $refusals = [
            ReportDraftRefused::DRAFT_CHANGED => [[...$this->formFields($draft, 2), 'title' => 'From an older tab'], 'Total is doubled on the cart'],
            ReportDraftController::FORM_EXPIRED => [[ReportDraftController::CSRF_FIELD => 'expired', ReportDraftController::VERSION_FIELD => '1', 'title' => 'From an expired form'], 'From an expired form'],
            ReportDraftRefused::TITLE_BLANK => [[...$this->formFields($draft, 1), 'title' => '   '], ''],
        ];
        foreach ($refusals as $reason => [$fields, $shownTitle]) {
            $page = $this->client->request('POST', self::LIST.'/'.$draft.'/send', $fields);
            self::assertResponseStatusCodeSame(422, $reason);
            self::assertSame($reason, $page->filter('#report-draft-error')->text());
            self::assertSame($shownTitle, trim((string) $page->filter('input[name=title]')->attr('value')), $reason);
            self::assertSame('1', $page->filter('form.report-draft__form input[name=version]')->attr('value'), $reason);
            self::assertSame(['Total is doubled on the cart', 1], array_values($this->rowOf('SELECT title, version FROM rvw_report_draft WHERE id = ?', [$draft])), $reason);
            self::assertSame(0, $this->rowCountOf('SELECT count(*) FROM tkt_ticket'), $reason);
        }
    }

    #[Test]
    public function aSecondSendOfTheSameDraftIsNotFoundAndMakesNoSecondTicket(): void
    {
        $draft = $this->addDraft('Mia', 'Total is doubled on the cart', []);
        $this->logIn($this->client, $this->people['Mia']);
        $fields = [...$this->formFields($draft, 1), 'title' => 'Total is doubled on the cart'];

        $this->client->request('POST', self::LIST.'/'.$draft.'/send', $fields);
        self::assertResponseStatusCodeSame(303);
        self::assertSame('/t/SHOP-1', $this->client->getResponse()->headers->get('Location'));

        $this->client->request('POST', self::LIST.'/'.$draft.'/send', $fields);
        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString(ReportDraftRefused::NOT_FOUND, (string) $this->client->getResponse()->getContent());
        self::assertSame(1, $this->rowCountOf('SELECT count(*) FROM tkt_ticket'));
        self::assertSame(0, $this->draftCount());
    }

    #[Test]
    public function theOwnerSavesThenDeletesTheirDraft(): void
    {
        $draft = $this->addDraft('Mia', 'Total is doubled on the cart', []);
        $this->logIn($this->client, $this->people['Mia']);

        $this->client->request('POST', self::LIST.'/'.$draft, [...$this->formFields($draft, 1), 'title' => ' Total is doubled at checkout ', 'detail' => 'Seen twice.']);
        self::assertResponseStatusCodeSame(303);
        self::assertSame(['Total is doubled at checkout', 'Seen twice.', 2], array_values($this->rowOf('SELECT title, detail, version FROM rvw_report_draft WHERE id = ?', [$draft])));

        $this->client->request('POST', self::LIST.'/'.$draft.'/delete', $this->formFields($draft, 2));
        self::assertResponseStatusCodeSame(303);
        self::assertSame(self::LIST, $this->client->getResponse()->headers->get('Location'));
        self::assertSame(0, $this->draftCount());
    }

    /**
     * @param list<array{media_id: string, title: ?string, summary: ?string, pins: list<array{x_pct: float, y_pct: float, note: string}>}> $screenshots
     *
     * @return string the draft's id
     */
    private function addDraft(string $owner, string $title, array $screenshots): string
    {
        $draftId = Uuid::v7()->toRfc4122();
        $this->addDraftAt($owner, $draftId, $title, '2026-11-05 08:00:00+00', $screenshots);

        return $draftId;
    }

    /**
     * @param list<array{media_id: string, title: ?string, summary: ?string, pins: list<array{x_pct: float, y_pct: float, note: string}>}> $screenshots
     */
    private function addDraftAt(string $owner, string $draftId, string $title, string $updatedAt, array $screenshots): void
    {
        $parameters = [$this->acmeFoods->toString(), $draftId, $this->people[$owner]->id()->toString(), $title, json_encode($screenshots, \JSON_THROW_ON_ERROR), $updatedAt];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO rvw_report_draft (organization_id, id, owner_id, project_key, title, detail, screenshots, version, created_at, updated_at)
             VALUES (?, ?, ?, 'SHOP', ?, '', CAST(? AS jsonb), 1, '2026-11-05 07:00:00+00', ?)",
            $parameters,
        ));
    }

    /**
     * @return array{media_id: string, title: null, summary: null, pins: array{}}
     */
    private static function screenshotOf(string $mediaId): array
    {
        return ['media_id' => $mediaId, 'title' => null, 'summary' => null, 'pins' => []];
    }

    /**
     * @return array<string, string>
     */
    private function formFields(string $draftId, int $version): array
    {
        return [
            ReportDraftController::CSRF_FIELD => $this->csrfTokenFor($this->client, ReportDraftController::csrfIdOf($draftId)),
            ReportDraftController::VERSION_FIELD => (string) $version,
        ];
    }

    private function draftCount(): int
    {
        return $this->rowCountOf('SELECT count(*) FROM rvw_report_draft');
    }

    private function rowCountOf(string $sql): int
    {
        $count = null;
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($sql, &$count): int {
            $count = $migrator->fetchOne($sql);

            return 0;
        });
        self::assertIsInt($count);

        return $count;
    }

    /**
     * @param list<string> $parameters
     *
     * @return list<array<string, mixed>>
     */
    private function rowsOf(string $sql, array $parameters): array
    {
        $rows = [];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($sql, $parameters, &$rows): int {
            $rows = $migrator->fetchAllAssociative($sql, $parameters);

            return 0;
        });

        return $rows;
    }

    /**
     * @param list<string> $parameters
     *
     * @return array<string, mixed>
     */
    private function rowOf(string $sql, array $parameters): array
    {
        $row = [];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($sql, $parameters, &$row): int {
            $row = $migrator->fetchAssociative($sql, $parameters);

            return 0;
        });
        self::assertIsArray($row);

        return $row;
    }
}
