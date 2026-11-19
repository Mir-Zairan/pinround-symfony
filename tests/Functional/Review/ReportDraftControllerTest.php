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

        $this->client->request('POST', self::LIST.'/'.$draft.'/send', $this->formFields($draft, 1));

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

        $this->client->request('POST', self::LIST.'/'.$draft.'/send', $this->formFields($draft, 1));

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

    #[Test]
    public function aPictureStillProcessingRefusesSendAndKeepsTheDraft(): void
    {
        $draft = $this->addDraft('Mia', 'Total is doubled on the cart', [['media_id' => $this->addMedia('pending'), 'title' => null, 'summary' => null, 'pins' => []]]);
        $this->logIn($this->client, $this->people['Mia']);

        $this->client->request('POST', self::LIST.'/'.$draft.'/send', $this->formFields($draft, 1));

        self::assertResponseStatusCodeSame(303);
        self::assertSame(self::LIST.'/'.$draft, $this->client->getResponse()->headers->get('Location'));
        $page = $this->client->followRedirect();
        self::assertSame(RoundRefused::MEDIA_STILL_PROCESSING, $page->filter('.toast-region [data-toast-message-value]')->attr('data-toast-message-value'));
        self::assertSame(1, $this->draftCount());
        self::assertSame(0, $this->rowCountOf('SELECT count(*) FROM tkt_ticket'), 'The ticket made before the refusal is rolled back.');
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
        self::assertSame('2', $page->filter('form.login__form input[name=version]')->attr('value'));
        self::assertSame('Total is doubled at checkout', $page->filter('input[name=title]')->attr('value'));
        self::assertSame('Seen twice.', $page->filter('textarea[name=detail]')->text());
        self::assertSame('The sum line.', $page->filter('textarea[name="screenshots[0][summary]"]')->text());
        self::assertSame(0, $page->filter('input[name="screenshots[0][remove]"][checked]')->count());
        self::assertSame($fresh, $this->rowOf('SELECT title, detail, screenshots::text AS screenshots, version FROM rvw_report_draft WHERE id = ?', [$draft]));
    }

    #[Test]
    public function sendFromAnOlderPageOrAnExpiredFormMakesNoTicketAndKeepsTheDraft(): void
    {
        $draft = $this->addDraft('Mia', 'Total is doubled on the cart', []);
        $this->logIn($this->client, $this->people['Mia']);

        $refusals = [
            ReportDraftRefused::DRAFT_CHANGED => $this->formFields($draft, 2),
            ReportDraftController::FORM_EXPIRED => [ReportDraftController::CSRF_FIELD => 'expired', ReportDraftController::VERSION_FIELD => '1'],
        ];
        foreach ($refusals as $reason => $fields) {
            $this->client->request('POST', self::LIST.'/'.$draft.'/send', $fields);
            self::assertResponseStatusCodeSame(303, $reason);
            self::assertSame(self::LIST.'/'.$draft, $this->client->getResponse()->headers->get('Location'), $reason);
            $page = $this->client->followRedirect();
            self::assertResponseStatusCodeSame(200, $reason);
            self::assertSame($reason, $page->filter('.toast-region [data-toast-message-value]')->attr('data-toast-message-value'));
            self::assertSame(['Total is doubled on the cart', 1], array_values($this->rowOf('SELECT title, version FROM rvw_report_draft WHERE id = ?', [$draft])), $reason);
            self::assertSame(0, $this->rowCountOf('SELECT count(*) FROM tkt_ticket'), $reason);
        }
    }

    #[Test]
    public function aSecondSendOfTheSameDraftIsNotFoundAndMakesNoSecondTicket(): void
    {
        $draft = $this->addDraft('Mia', 'Total is doubled on the cart', []);
        $this->logIn($this->client, $this->people['Mia']);
        $fields = $this->formFields($draft, 1);

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
        $parameters = [$this->acmeFoods->toString(), $draftId, $this->people[$owner]->id()->toString(), $title, json_encode($screenshots, \JSON_THROW_ON_ERROR)];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO rvw_report_draft (organization_id, id, owner_id, project_key, title, detail, screenshots, version, created_at, updated_at)
             VALUES (?, ?, ?, 'SHOP', ?, '', CAST(? AS jsonb), 1, '2026-11-05 08:00:00+00', '2026-11-05 08:00:00+00')",
            $parameters,
        ));

        return $draftId;
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
