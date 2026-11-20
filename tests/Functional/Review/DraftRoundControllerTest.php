<?php

declare(strict_types=1);

namespace App\Tests\Functional\Review;

use App\Identity\Domain\User;
use App\Media\UI\Controller\UploadIntentController;
use App\Media\UI\Controller\UploadStatusController;
use App\Review\Api\ReportDraftRefused;
use App\Review\Api\RoundRefused;
use App\Review\Domain\DraftHasNoScreenshots;
use App\Review\UI\Component\DraftRoundEditor;
use App\Review\UI\Component\RoundsSection;
use App\Review\UI\Controller\RefusedWithToast;
use App\Review\UI\Controller\RoundController;
use App\Review\UI\DraftRoundController;
use App\Review\UI\RoundsController;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use App\Tests\Functional\Web\IssuesCsrfTokens;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * The private draft round's buttons on Acme Foods' ticket SHOP-1 (P05.T8, ADR-0017): start, share and delete, through
 * the real firewall, voters, CSRF and database. Omar is the ticket's assignee, Lena leads the Shop team, Mia is a member
 * of it who reported the ticket, Raj is a manager who views the team, and Sara is an admin. The clock is fixed on
 * 6 Nov 2026, before today: a clock ahead of the login would end the session as idle.
 */
final class DraftRoundControllerTest extends WebTestCase
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
    /** NOW as DBAL reads a timestamptz column back. */
    private const string NOW_AS_STORED = '2026-11-06 12:00:00+00';
    private const string SHARED_AT = '2026-11-05 09:00:00+00';
    private const string FRAME = '/t/SHOP-1/rounds';
    private const string START = '/t/SHOP-1/rounds/draft';
    private const string TICKET_PAGE = '/t/SHOP-1#ticket-rounds';
    private const string ROUNDS = 'turbo-frame#ticket-rounds';
    private const array TURBO_FRAME_HEADERS = ['HTTP_TURBO_FRAME' => RoundsSection::FRAME_ID];
    private const array TURBO_FORM_HEADERS = ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml'];
    private const string PRIVATE_HEADLINE = 'Draft round · only you can see this';
    private const string OMAR_DRAFT_SHOT = 'Omar own draft shot';
    private const string MIA_DRAFT_SHOT = 'Mia own draft shot';
    private const string MIA_DRAFT_NOTE = 'Mia private note';
    private const string MIA_DRAFT_SUMMARY = 'What the tester saw';
    /** Who may see SHOP-1 but may not start, share or delete Omar's draft. */
    private const array OTHERS = ['Lena', 'Mia', 'Raj', 'Sara'];

    private KernelBrowser $client;

    /** @var array<string, User> */
    private array $people;

    private string $shopProject;
    private string $stepId;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTPS' => 'on']);
        $this->client->disableReboot();
        self::getContainer()->set('clock', new MockClock(new \DateTimeImmutable(self::NOW)));
        $this->people = $this->createAcmeFoodsPeople('member');
        $this->addTeamRole($this->people['Raj'], $this->acmeFoods, $this->shop, 'viewer');
        $this->shopProject = $this->addProject($this->shop, 'SHOP', 'Shop');
        $this->stepId = Uuid::v7()->toRfc4122();
        $organizationId = $this->acmeFoods->toString();
        $stepId = $this->stepId;
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, 'To do', 'todo', 'todo', 'core', 1)",
            [$organizationId, $stepId],
        ));
    }

    /**
     * Mia becomes SHOP-1's tester, the ticket goes to a Testing step, and the turn read model says it is her turn, as
     * the move to Testing would leave it.
     */
    private function putInTestingWithMiasTurn(string $ticketId): void
    {
        $organizationId = $this->acmeFoods->toString();
        $testingStepId = Uuid::v7()->toRfc4122();
        $miaId = $this->people['Mia']->id()->toString();
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId, $testingStepId, $miaId, $ticketId): int {
            $migrator->executeStatement(
                "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, 'Testing', 'check', 'test', 'core', 5)",
                [$organizationId, $testingStepId],
            );
            $migrator->executeStatement('UPDATE tkt_ticket SET step_id = ?, tester_id = ? WHERE id = ?', [$testingStepId, $miaId, $ticketId]);
            $migrator->executeStatement(
                "INSERT INTO ins_ticket_turn (organization_id, ticket_id, team_id, project_id, grp, turn_user_id, turn_label, kind, base_score, waiting_since, visibility, reporter_id, assignee_id, tester_id)
                 SELECT organization_id, id, team_id, project_id, 'check', tester_id, NULL, 'test', 10, '2026-11-06 11:00:00+00', visibility, reporter_id, assignee_id, tester_id FROM tkt_ticket WHERE id = ?",
                [$ticketId],
            );

            return 0;
        });
    }

    protected function tearDown(): void
    {
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->committedAs(new OrganizationId($organizationId), static fn (Connection $migrator): int|string => $migrator->executeStatement('DELETE FROM med_upload_intent WHERE organization_id = ?', [$organizationId]));
            $this->committedAs(new OrganizationId($organizationId), static fn (Connection $migrator): int|string => $migrator->executeStatement('DELETE FROM rvw_report_draft WHERE organization_id = ?', [$organizationId]));
        }
        $this->removeSeededRounds();
        $this->removeCommittedProjects();
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function theAssigneeStartsOneDraftFromTheFrameAndASecondStartIsRefusedWithItsReason(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $this->logIn($this->client, $this->people['Omar']);
        $frame = $this->frame();
        $start = $frame->filter(self::ROUNDS.' form.rounds__start');
        self::assertSame('Start a draft round', $start->filter('button[type=submit]')->text());
        self::assertSame(self::START, $start->attr('action'));
        self::assertSame('_top', $start->attr('data-turbo-frame'));

        $this->client->submit($start->form());

        self::assertSame(DraftRoundController::DRAFT_STARTED, $this->toastAfterTheRedirect());
        $drafts = $this->roundRowsOf($ticketId);
        self::assertCount(1, $drafts);
        self::assertSame(['draft', $this->people['Omar']->id()->toString(), null, null, 'open'], [$drafts[0]['kind'], $drafts[0]['owner_id'], $drafts[0]['number'], $drafts[0]['shared_at'], $drafts[0]['state']]);

        $this->client->request('POST', self::START, [DraftRoundController::CSRF_FIELD => $this->csrfTokenFor($this->client, DraftRoundController::csrfIdOf('SHOP-1'))], [], self::TURBO_FORM_HEADERS);
        self::assertSame(RoundRefused::DRAFT_EXISTS, $this->toastOfTheRefusal());
        $this->client->request('POST', self::START, [DraftRoundController::CSRF_FIELD => $this->csrfTokenFor($this->client, DraftRoundController::csrfIdOf('SHOP-1'))]);
        self::assertSame(RoundRefused::DRAFT_EXISTS, $this->toastAfterTheRedirect());
        self::assertSame($drafts, $this->roundRowsOf($ticketId));

        $frame = $this->frame();
        self::assertCount(0, $frame->filter(self::ROUNDS.' form.rounds__start'), 'No second start beside the own draft.');
        self::assertSame([self::PRIVATE_HEADLINE], $frame->filter(self::ROUNDS.' .round-card__headline')->each(static fn (Crawler $headline): string => $headline->text()));
        self::assertCount(0, $frame->filter(self::ROUNDS.' .round-card__status'), 'An empty draft has no "0 of 0".');
        self::assertSame(DraftRoundEditor::EMPTY_NOTE, $frame->filter(self::ROUNDS.' .round-card__empty')->text());
        self::assertSame('Add screenshot', $frame->filter(self::ROUNDS.' .draft-round-editor button.draft-round-editor__add')->text(), 'An empty draft still offers the way to add one.');
    }

    #[Test]
    public function theOwnersDraftCardHoldsTheEditorWithEachScreenshotsTextsVerdictToggleRemoveAndPins(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $shared = $this->addRound($ticketId, 1, 'draft', 'Omar', self::SHARED_AT);
        $this->addScreenshot($shared, $this->addMedia('ready'), 0, 'Shared shot', null);
        $draft = $this->addRound($ticketId, null, 'draft', 'Omar', null);
        $first = $this->addScreenshot($draft, $this->addMedia('ready'), 0, 'First <b>shot</b>', 'What the total shows', 'looks_right');
        $this->addPin($first, 1, '10.00', '20.00', 'Note A', 'Omar');
        $this->addPin($first, 2, '60.00', '70.00', 'Note B', 'Omar');
        $second = $this->addScreenshot($draft, $this->addMedia('ready'), 1, null, null);
        $this->logIn($this->client, $this->people['Omar']);

        $frame = $this->frame();

        self::assertCount(1, $frame->filter(self::ROUNDS.' .draft-round-editor'), 'Only the unshared draft has the editor.');
        self::assertCount(0, $frame->filter('#round-'.$shared.' .draft-round-editor, #round-'.$shared.' button'), 'A shared round is read-only.');
        $editor = $frame->filter('#round-'.$draft.' .draft-round-editor');
        self::assertCount(1, $editor);
        self::assertSame('1 of 2 look right', $editor->filter('.round-card__status')->text());
        self::assertCount(0, $editor->filter('.round-card__empty'), 'The empty note is for an empty draft only.');
        self::assertSame('Add screenshot', $editor->filter('button.draft-round-editor__add')->text());
        $body = $editor->filter('.draft-round-editor__body');
        self::assertSame('round', $body->attr('data-presigned-upload-target-kind-value'));
        self::assertSame($draft, $body->attr('data-presigned-upload-target-id-value'));
        $sections = $editor->filter('section[data-screenshot-id]');
        self::assertSame([$first, $second], $sections->each(static fn (Crawler $section): string => (string) $section->attr('data-screenshot-id')));

        $one = $sections->eq(0);
        self::assertSame('First <b>shot</b>', $one->filter('input[data-field=title]')->attr('value'));
        self::assertSame('120', $one->filter('input[data-field=title]')->attr('maxlength'));
        self::assertSame('What the total shows', $one->filter('textarea[data-field=summary]')->text());
        self::assertSame('600', $one->filter('textarea[data-field=summary]')->attr('maxlength'));
        self::assertStringNotContainsString('First <b>shot</b>', (string) $this->client->getResponse()->getContent(), 'A title is text, never markup.');
        self::assertSame(['true', 'false'], $one->filter('[role="group"] button[aria-pressed]')->each(static fn (Crawler $chip): string => (string) $chip->attr('aria-pressed')));
        self::assertSame(['', 'not_right'], $one->filter('[role="group"] button[aria-pressed]')->each(static fn (Crawler $chip): string => (string) $chip->attr('data-live-verdict-param')), 'The pressed chip sends an empty verdict, which clears it.');
        self::assertSame(['setVerdict', 'setVerdict'], $one->filter('[role="group"] button[aria-pressed]')->each(static fn (Crawler $chip): string => (string) $chip->attr('data-live-action-param')));
        self::assertSame([$first, $first], $one->filter('[role="group"] button[aria-pressed]')->each(static fn (Crawler $chip): string => (string) $chip->attr('data-live-screenshot-id-param')));
        self::assertSame('Remove screenshot', $one->filter('.draft-shot__remove')->text());
        self::assertSame('removeScreenshot', $one->filter('.draft-shot__remove')->attr('data-live-action-param'));
        self::assertSame($first, $one->filter('.draft-shot__remove')->attr('data-live-screenshot-id-param'));
        self::assertSame(['Note A', 'Note B'], $one->filter('textarea.pin-list__note')->each(static fn (Crawler $note): string => $note->text()));
        self::assertCount(2, $one->filter('button.pin'));
        self::assertCount(1, $one->filter('.pin-placer[data-controller=pin-placer][data-live-ignore] button[data-pin-add]'));
        self::assertSame(DraftRoundEditor::NOTE_PLACEHOLDER, $one->filter('textarea.pin-list__note')->attr('placeholder'));

        $two = $sections->eq(1);
        self::assertSame(['false', 'false'], $two->filter('[role="group"] button[aria-pressed]')->each(static fn (Crawler $chip): string => (string) $chip->attr('aria-pressed')));
        self::assertSame(['looks_right', 'not_right'], $two->filter('[role="group"] button[aria-pressed]')->each(static fn (Crawler $chip): string => (string) $chip->attr('data-live-verdict-param')));
        self::assertSame('', $two->filter('input[data-field=title]')->attr('value'));
        self::assertCount(0, $two->filter('textarea.pin-list__note'));
        self::assertSame(['Share with the team now', 'Make it a new ticket', 'Delete draft'], $frame->filter('#round-'.$draft.' form button')->each(static fn (Crawler $button): string => $button->text()), 'Share, Make it a new ticket and Delete stay.');
    }

    /**
     * ADR-0044: in Testing it is Mia's turn, so the tester may start her own private draft; the lead may not, as it is
     * not hers.
     */
    #[Test]
    public function theTesterWhoseTurnItIsStartsHerOwnDraftAndTheLeadIsStillRefused(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $this->putInTestingWithMiasTurn($ticketId);

        $this->logIn($this->client, $this->people['Lena']);
        self::assertCount(0, $this->frame()->filter(self::ROUNDS.' form.rounds__start'), 'Not Lena\'s turn.');
        $this->client->request('POST', self::START, [DraftRoundController::CSRF_FIELD => $this->csrfTokenFor($this->client, DraftRoundController::csrfIdOf('SHOP-1'))]);
        self::assertSame(DraftRoundController::ONLY_THE_ASSIGNEE_OR_TURN_STARTS, $this->toastAfterTheRedirect());
        self::assertSame([], $this->roundRowsOf($ticketId));

        $this->logIn($this->client, $this->people['Mia']);
        $start = $this->frame()->filter(self::ROUNDS.' form.rounds__start');
        self::assertSame('Start a draft round', $start->filter('button[type=submit]')->text());

        $this->client->submit($start->form());

        self::assertSame(DraftRoundController::DRAFT_STARTED, $this->toastAfterTheRedirect());
        $drafts = $this->roundRowsOf($ticketId);
        self::assertCount(1, $drafts);
        self::assertSame(['draft', $this->people['Mia']->id()->toString(), null, null, 'open'], [$drafts[0]['kind'], $drafts[0]['owner_id'], $drafts[0]['number'], $drafts[0]['shared_at'], $drafts[0]['state']]);

        $this->logIn($this->client, $this->people['Omar']);
        $frame = $this->frame();
        self::assertSame([], $frame->filter(self::ROUNDS.' .round-card__headline')->each(static fn (Crawler $headline): string => $headline->text()), 'Omar does not see Mia\'s unshared draft.');
        self::assertCount(1, $frame->filter(self::ROUNDS.' form.rounds__start'), 'The assignee may still start his own.');
    }

    #[Test]
    public function noOneButTheAssigneeSeesTheStartButtonOrMayStartADraftWhileItIsNobodyElsesTurn(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $ben = $this->otherOrganizationsAdmin();

        foreach (self::OTHERS as $person) {
            $this->logIn($this->client, $this->people[$person]);
            self::assertCount(0, $this->frame()->filter(self::ROUNDS.' form.rounds__start, .rounds__start-draft'), $person);

            $this->client->request('POST', self::START, [DraftRoundController::CSRF_FIELD => $this->csrfTokenFor($this->client, DraftRoundController::csrfIdOf('SHOP-1'))]);

            self::assertSame(DraftRoundController::ONLY_THE_ASSIGNEE_OR_TURN_STARTS, $this->toastAfterTheRedirect(), $person.' may see the ticket, so the denial is a toast, not a 404.');
            self::assertSame([], $this->roundRowsOf($ticketId), $person);

            $this->client->request('POST', self::START, [], [], self::TURBO_FORM_HEADERS);

            self::assertSame(DraftRoundController::ONLY_THE_ASSIGNEE_OR_TURN_STARTS, $this->toastOfTheRefusal(), $person.': the voter runs before the form token is read.');
            self::assertSame([], $this->roundRowsOf($ticketId), $person);
        }

        $this->logIn($this->client, $ben);
        $this->client->request('POST', self::START, [DraftRoundController::CSRF_FIELD => $this->csrfTokenFor($this->client, DraftRoundController::csrfIdOf('SHOP-1'))]);
        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString(RoundsController::NOT_FOUND, (string) $this->client->getResponse()->getContent());
        self::assertSame([], $this->roundRowsOf($ticketId));
    }

    #[Test]
    public function aLoggedOutVisitorIsSentToLogInAndStartsSharesAndDeletesNothing(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $draft = $this->addRound($ticketId, null, 'draft', 'Omar', null);
        $before = $this->roundRowsOf($ticketId);

        foreach ([self::START, $this->shareUrl($draft), $this->deleteUrl($draft), $this->newTicketUrl($draft)] as $url) {
            $this->client->request('POST', $url, [DraftRoundController::CSRF_FIELD => 'any']);

            self::assertResponseStatusCodeSame(302);
            self::assertStringEndsWith('/login', (string) $this->client->getResponse()->headers->get('Location'));
        }
        self::assertSame($before, $this->roundRowsOf($ticketId));
    }

    #[Test]
    public function anotherPersonGetsNotFoundOnShareAndDeleteOfAnUnsharedDraftSeesNoCardAndTheDraftStaysUnchanged(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $draft = $this->addRound($ticketId, null, 'draft', 'Omar', null);
        $this->addScreenshot($draft, $this->addMedia('ready'), 0, self::OMAR_DRAFT_SHOT, null);
        $before = $this->roundRowsOf($ticketId);
        self::assertCount(1, $before);
        $ben = $this->otherOrganizationsAdmin();

        foreach (self::OTHERS as $person) {
            $this->logIn($this->client, $this->people[$person]);
            $this->frame();
            $content = (string) $this->client->getResponse()->getContent();
            self::assertStringNotContainsString(self::PRIVATE_HEADLINE, $content, $person);
            self::assertStringNotContainsString(self::OMAR_DRAFT_SHOT, $content, $person);
            self::assertStringNotContainsString($draft, $content, $person);
            self::assertCount(0, $this->client->getCrawler()->filter('.draft-round-editor, .draft-round-editor__add, .round-card'), $person.' sees no draft card and no editor.');

            foreach ([$this->shareUrl($draft), $this->deleteUrl($draft), $this->newTicketUrl($draft)] as $url) {
                $this->client->request('POST', $url, [DraftRoundController::CSRF_FIELD => $this->csrfTokenFor($this->client, DraftRoundController::csrfIdOf('SHOP-1'))]);

                self::assertResponseStatusCodeSame(404, $person.' '.$url);
                self::assertStringContainsString(RoundRefused::NOT_FOUND, (string) $this->client->getResponse()->getContent());
                self::assertSame($before, $this->roundRowsOf($ticketId), $person.' '.$url);
            }
        }

        $this->logIn($this->client, $ben);
        foreach ([$this->shareUrl($draft), $this->deleteUrl($draft), $this->newTicketUrl($draft)] as $url) {
            $this->client->request('POST', $url, [DraftRoundController::CSRF_FIELD => $this->csrfTokenFor($this->client, DraftRoundController::csrfIdOf('SHOP-1'))]);
            self::assertResponseStatusCodeSame(404);
            self::assertSame($before, $this->roundRowsOf($ticketId));
        }
    }

    #[Test]
    public function aViewerWhoOwnsASharedRoundGetsNotFoundOnShareAndDeleteBecauseTheRoundVoterDeniesEdit(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        // Without the voter, the command would answer its "shared already" refusal here, not NOT_FOUND.
        $rajsRound = $this->addRound($ticketId, 1, 'draft', 'Raj', self::SHARED_AT);
        $before = $this->roundRowsOf($ticketId);
        self::assertCount(1, $before);
        $this->logIn($this->client, $this->people['Raj']);

        foreach ([$this->shareUrl($rajsRound), $this->deleteUrl($rajsRound), $this->newTicketUrl($rajsRound)] as $url) {
            $this->client->request('POST', $url, [DraftRoundController::CSRF_FIELD => $this->csrfTokenFor($this->client, DraftRoundController::csrfIdOf('SHOP-1'))], [], self::TURBO_FORM_HEADERS);

            self::assertResponseStatusCodeSame(404, $url);
            self::assertStringContainsString(RoundRefused::NOT_FOUND, (string) $this->client->getResponse()->getContent(), $url);
            self::assertSame($before, $this->roundRowsOf($ticketId), $url);
        }
    }

    #[Test]
    public function anotherPersonsDraftOrAnUnknownRoundIsNotFoundBeforeTheFormTokenIsRead(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $draft = $this->addRound($ticketId, null, 'draft', 'Omar', null);
        $before = $this->roundRowsOf($ticketId);
        self::assertCount(1, $before);

        foreach (self::OTHERS as $person) {
            $this->logIn($this->client, $this->people[$person]);
            foreach ([$draft, Uuid::v7()->toRfc4122()] as $roundId) {
                foreach ([$this->shareUrl($roundId), $this->deleteUrl($roundId), $this->newTicketUrl($roundId)] as $url) {
                    foreach ([[], [DraftRoundController::CSRF_FIELD => 'not-the-token']] as $form) {
                        $this->client->request('POST', $url, $form, [], self::TURBO_FORM_HEADERS);

                        $content = (string) $this->client->getResponse()->getContent();
                        self::assertResponseStatusCodeSame(404, $person.' '.$url);
                        self::assertStringContainsString(RoundRefused::NOT_FOUND, $content, $person.' '.$url);
                        self::assertStringNotContainsString(RefusedWithToast::FORM_EXPIRED, $content, $person.' '.$url);
                        self::assertSame($before, $this->roundRowsOf($ticketId), $person.' '.$url);
                    }
                }
            }
        }
    }

    /**
     * ADR-0044, P13.T1: Mia's draft on SHOP-1 in Testing, opened by its own URLs as someone else would type them. The
     * round page it would get once shared, share, delete, "Make it a new ticket", the editor's live actions with the props Mia's page signed,
     * an upload to it and its picture's upload state all answer as for a round that does not exist, and nothing is
     * stored. Mia's shared report stays readable for each of them, so the refusals are about the draft, not the ticket.
     */
    #[Test]
    public function theTestersUnsharedDraftIsNotFoundAtEveryUrlForTheAssigneeTheLeadTheAdminAndAnotherOrganization(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $this->putInTestingWithMiasTurn($ticketId);
        $report = $this->addRound($ticketId, 0, 'report', 'Mia', self::SHARED_AT);
        $this->addScreenshot($report, $this->addMedia('ready'), 0, 'Mia report shot', null);
        $draft = $this->addRound($ticketId, null, 'draft', 'Mia', null);
        $draftPicture = $this->addMedia('ready', 'Mia');
        $draftShot = $this->addScreenshot($draft, $draftPicture, 0, self::MIA_DRAFT_SHOT, self::MIA_DRAFT_SUMMARY, 'not_right');
        $this->addPin($draftShot, 1, '10.00', '20.00', self::MIA_DRAFT_NOTE, 'Mia');
        $ben = $this->otherOrganizationsAdmin();
        $picturesToAdd = ['Omar' => $this->addMedia('ready', 'Omar'), 'Lena' => $this->addMedia('ready', 'Lena'), 'Sara' => $this->addMedia('ready', 'Sara')];
        $picturesToAdd['Ben'] = $picturesToAdd['Omar'];

        $this->logIn($this->client, $this->people['Mia']);
        $miasEditor = $this->frame()->filter('#round-'.$draft.' .draft-round-editor');
        self::assertCount(1, $miasEditor, 'Mia sees her own draft.');
        $miasProps = (string) $miasEditor->attr('data-live-props-value');
        self::assertNotSame('', $miasProps);
        $this->client->request('GET', '/media/'.$draftPicture.'/status');
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['status' => 'ready'], $this->jsonAnswer(), 'Mia reads the state of her draft\'s picture.');
        $before = $this->draftState($ticketId, $report, $draft);
        self::assertNotSame([], $before['pins']);

        foreach (['Omar', 'Lena', 'Sara', 'Ben'] as $person) {
            $this->logIn($this->client, 'Ben' === $person ? $ben : $this->people[$person]);
            // Ben may not see SHOP-1 at all, so he is refused at the ticket before any round is read.
            $roundPageRefusal = 'Ben' === $person ? RoundsController::NOT_FOUND : RoundController::NOT_FOUND;
            $draftRefusal = 'Ben' === $person ? RoundsController::NOT_FOUND : RoundRefused::NOT_FOUND;
            if ('Ben' !== $person) {
                self::assertSame(['Round 0 · Report · by Mia · 0 pins'], $this->frame()->filter(self::ROUNDS.' .round-card__headline')->each(static fn (Crawler $headline): string => $headline->text()), $person);
                $this->assertHoldsNothingOfMiasDraft($person.' in the Rounds section', $draft);
                $this->client->request('GET', '/t/SHOP-1/rounds/0');
                self::assertResponseStatusCodeSame(200, $person.' opens the shared report.');
            }

            foreach (['/t/SHOP-1/rounds/1', '/t/SHOP-1/rounds/1/focus'] as $roundPage) {
                $this->client->request('GET', $roundPage);
                self::assertResponseStatusCodeSame(404, $person.' '.$roundPage);
                self::assertStringContainsString($roundPageRefusal, (string) $this->client->getResponse()->getContent(), $person.' '.$roundPage);
                $this->assertHoldsNothingOfMiasDraft($person.' '.$roundPage, $draft);
            }

            foreach ([$this->shareUrl($draft), $this->deleteUrl($draft), $this->newTicketUrl($draft)] as $url) {
                $this->client->request('POST', $url, [DraftRoundController::CSRF_FIELD => $this->csrfTokenFor($this->client, DraftRoundController::csrfIdOf('SHOP-1'))]);
                self::assertResponseStatusCodeSame(404, $person.' '.$url);
                self::assertStringContainsString($draftRefusal, (string) $this->client->getResponse()->getContent(), $person.' '.$url);
            }

            foreach ([
                'addScreenshot' => ['mediaId' => $picturesToAdd[$person]],
                'removeScreenshot' => ['screenshotId' => $draftShot],
                'editScreenshotText' => ['screenshotId' => $draftShot, 'title' => 'Hijacked', 'summary' => 'Hijacked'],
                'setVerdict' => ['screenshotId' => $draftShot, 'verdict' => 'looks_right'],
                'setPins' => ['screenshotId' => $draftShot, 'pins' => []],
            ] as $action => $args) {
                $this->postLiveAction($this->client, $miasProps, $action, $args);
                self::assertResponseStatusCodeSame(404, $person.' '.$action);
                // The draft's id is in the props the request sent, which the test error page shows back.
                $this->assertHoldsNothingOfMiasDraft($person.' '.$action, null);
            }

            $this->beginUploadTo($draft);
            self::assertResponseStatusCodeSame(403, $person.' uploads to the draft.');
            $refusalOfTheDraft = $this->jsonAnswer();
            $this->beginUploadTo(Uuid::v7()->toRfc4122());
            self::assertResponseStatusCodeSame(403, $person.' uploads to an unknown round.');
            self::assertSame(['message' => UploadIntentController::NOT_ALLOWED], $refusalOfTheDraft, $person);
            self::assertSame($this->jsonAnswer(), $refusalOfTheDraft, $person.': the draft is refused as a round that does not exist.');

            $this->client->request('GET', '/media/'.$draftPicture.'/status');
            self::assertResponseStatusCodeSame(404, $person.' reads the state of the draft\'s picture.');
            self::assertSame(['message' => UploadStatusController::NOTHING_UPLOADED], $this->jsonAnswer(), $person);

            self::assertSame($before, $this->draftState($ticketId, $report, $draft), $person.': nothing changes.');
        }
    }

    #[Test]
    public function sharingTurnsTheDraftIntoTheNextNumberedRoundReadOnlyForItsOwnerAndSeenByTheLead(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $this->addRound($ticketId, 0, 'report', 'Mia', self::SHARED_AT);
        $draft = $this->addRound($ticketId, null, 'draft', 'Omar', null);
        $this->addScreenshot($draft, $this->addMedia('ready'), 0, self::OMAR_DRAFT_SHOT, null, 'looks_right');
        $this->logIn($this->client, $this->people['Omar']);
        $share = $this->frame()->filter('#round-'.$draft.' form.round-card__form')->reduce(static fn (Crawler $form): bool => 'Share with the team now' === $form->filter('button')->text());
        self::assertCount(1, $share);

        $this->client->submit($share->form());

        self::assertSame('Shared with the team as round 1.', $this->toastAfterTheRedirect());
        $shared = $this->roundRow($ticketId, $draft);
        self::assertSame(1, $shared['number']);
        self::assertSame(self::NOW_AS_STORED, $shared['shared_at']);
        self::assertSame(2, $shared['version'], 'The seeded draft is at version 1.');

        $frame = $this->frame();
        $card = $frame->filter('#round-'.$draft);
        self::assertSame('Round 1 · self-check by Omar', $card->filter('.round-card__headline')->text());
        self::assertSame('1 of 1 look right', $card->filter('.round-card__status')->text());
        self::assertCount(0, $card->filter('form, .claude-code-box, .round-card__private'), 'A shared round is read-only.');
        self::assertCount(1, $frame->filter(self::ROUNDS.' form.rounds__start'), 'Omar has no unshared draft any more, so he may start one.');

        $this->logIn($this->client, $this->people['Lena']);
        self::assertSame(
            ['Round 0 · Report · by Mia · 0 pins', 'Round 1 · self-check by Omar'],
            $this->frame()->filter(self::ROUNDS.' .round-card__headline')->each(static fn (Crawler $headline): string => $headline->text()),
        );
        self::assertStringContainsString(self::OMAR_DRAFT_SHOT, (string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function deletingTheDraftTakesItOffTheFrameAndASecondDeleteIsNotFound(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $draft = $this->addRound($ticketId, null, 'draft', 'Omar', null);
        $this->addScreenshot($draft, $this->addMedia('ready'), 0, self::OMAR_DRAFT_SHOT, null);
        $this->logIn($this->client, $this->people['Omar']);
        $delete = $this->frame()->filter('#round-'.$draft.' form.round-card__form')->reduce(static fn (Crawler $form): bool => 'Delete draft' === $form->filter('button')->text());
        self::assertCount(1, $delete);
        $form = $delete->form();

        $this->client->submit($form);

        self::assertSame(DraftRoundController::DRAFT_DELETED, $this->toastAfterTheRedirect());
        $deleted = $this->roundRow($ticketId, $draft);
        self::assertSame(self::NOW_AS_STORED, $deleted['deleted_at']);
        self::assertSame('closed', $deleted['state']);
        self::assertNull($deleted['shared_at']);
        $frame = $this->frame();
        self::assertCount(0, $frame->filter(self::ROUNDS.' .round-card'));
        self::assertStringNotContainsString(self::OMAR_DRAFT_SHOT, (string) $this->client->getResponse()->getContent());
        self::assertCount(1, $frame->filter(self::ROUNDS.' form.rounds__start'), 'Its owner may start a new draft.');

        $this->client->submit($form);

        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString(RoundRefused::NOT_FOUND, (string) $this->client->getResponse()->getContent());
        self::assertSame($deleted, $this->roundRow($ticketId, $draft));
    }

    #[Test]
    public function theOwnerSharingOrDeletingADraftSharedAlreadyGetsTheRefusalAsAToastAndNothingChanges(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $shared = $this->addRound($ticketId, 1, 'draft', 'Omar', self::SHARED_AT);
        $before = $this->roundRowsOf($ticketId);
        $this->logIn($this->client, $this->people['Omar']);
        $token = $this->csrfTokenFor($this->client, DraftRoundController::csrfIdOf('SHOP-1'));

        $this->client->request('POST', $this->shareUrl($shared), [DraftRoundController::CSRF_FIELD => $token], [], self::TURBO_FORM_HEADERS);
        self::assertSame('This draft round is shared already.', $this->toastOfTheRefusal());
        $this->client->request('POST', $this->deleteUrl($shared), [DraftRoundController::CSRF_FIELD => $token], [], self::TURBO_FORM_HEADERS);
        self::assertSame('This draft round is shared, so it no longer changes.', $this->toastOfTheRefusal());

        self::assertSame($before, $this->roundRowsOf($ticketId));
    }

    /**
     * @return iterable<string, array{string, string, string|null, string}> who shares their draft, who owns the open
     *                                                                      round 1, whose turn it is (null for no turn
     *                                                                      row), and the toast
     */
    public static function sharesWhileRoundOneIsOpen(): iterable
    {
        yield 'Mia\'s findings round waits for Omar, the assignee, whatever the turn says' => ['Lena', 'Mia', 'Mia', 'Round 1 is still open. Omar gives a verdict first.'];
        yield 'Omar\'s self-check round waits for Mia, whose turn it is' => ['Omar', 'Omar', 'Mia', 'Round 1 is still open. Mia gives a verdict first.'];
        yield 'Sara, an admin of no team whose turn it is, is named though the ticket does not list her' => ['Omar', 'Omar', 'Sara', 'Round 1 is still open. Sara gives a verdict first.'];
        yield 'Mia, whose turn it is, is told to give her verdict' => ['Mia', 'Omar', 'Mia', 'Round 1 is still open. Give your verdict first.'];
        yield 'no turn row names nobody, as Review does' => ['Omar', 'Omar', null, 'Round 1 is still open. Wait for its verdict before you share.'];
    }

    /**
     * ADR-0048, P14.T5: one open shared round per ticket. "Share with the team now" on a draft card while round 1 is
     * open is a toast that names who gives the verdict, and the draft stays private and unnumbered.
     */
    #[Test]
    #[DataProvider('sharesWhileRoundOneIsOpen')]
    public function sharingWhileAnotherRoundIsOpenSaysWhoGivesTheVerdictAndKeepsTheDraftPrivate(string $sharer, string $openRoundOwner, ?string $turnPerson, string $expectedToast): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $openRound = $this->addRound($ticketId, 1, 'draft', $openRoundOwner, self::SHARED_AT);
        $this->addScreenshot($openRound, $this->addMedia('ready', $openRoundOwner), 0, 'Shared shot', null);
        $draft = $this->addRound($ticketId, null, 'draft', $sharer, null);
        $this->addScreenshot($draft, $this->addMedia('ready', $sharer), 0, 'Draft shot to share', null);
        if (null !== $turnPerson) {
            $this->addTurnRowOf($ticketId, $turnPerson);
        }
        $before = $this->roundRowsOf($ticketId);
        self::assertCount(2, $before);
        $this->logIn($this->client, $this->people[$sharer]);

        $this->client->request('POST', $this->shareUrl($draft), [DraftRoundController::CSRF_FIELD => $this->csrfTokenFor($this->client, DraftRoundController::csrfIdOf('SHOP-1'))], [], self::TURBO_FORM_HEADERS);

        self::assertSame($expectedToast, $this->toastOfTheRefusal());
        self::assertSame($before, $this->roundRowsOf($ticketId));
        $unshared = $this->roundRow($ticketId, $draft);
        self::assertSame(['open', null, null], [$unshared['state'], $unshared['number'], $unshared['shared_at']]);
        self::assertSame('open', $this->roundRow($ticketId, $openRound)['state'], 'The refused share closes nothing.');
    }

    #[Test]
    public function sharingADraftWithNoScreenshotSaysToAddOneAndKeepsTheDraftPrivate(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $this->addTurnRowOf($ticketId, 'Mia');
        $draft = $this->addRound($ticketId, null, 'draft', 'Omar', null);
        $before = $this->roundRowsOf($ticketId);
        self::assertCount(1, $before);
        $this->logIn($this->client, $this->people['Omar']);

        $this->client->request('POST', $this->shareUrl($draft), [DraftRoundController::CSRF_FIELD => $this->csrfTokenFor($this->client, DraftRoundController::csrfIdOf('SHOP-1'))], [], self::TURBO_FORM_HEADERS);

        self::assertSame(DraftHasNoScreenshots::REASON, $this->toastOfTheRefusal());
        self::assertSame($before, $this->roundRowsOf($ticketId));
        self::assertNull($this->roundRow($ticketId, $draft)['number']);
    }

    #[Test]
    public function aFormWithoutAValidTokenStartsSharesAndDeletesNothing(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $this->logIn($this->client, $this->people['Omar']);

        $this->client->request('POST', self::START, [], [], self::TURBO_FORM_HEADERS);
        self::assertSame(RefusedWithToast::FORM_EXPIRED, $this->toastOfTheRefusal());
        self::assertSame([], $this->roundRowsOf($ticketId));

        $draft = $this->addRound($ticketId, null, 'draft', 'Omar', null);
        $before = $this->roundRowsOf($ticketId);
        $this->client->request('POST', $this->shareUrl($draft), [DraftRoundController::CSRF_FIELD => 'not-the-token'], [], self::TURBO_FORM_HEADERS);
        self::assertSame(RefusedWithToast::FORM_EXPIRED, $this->toastOfTheRefusal());
        $this->client->request('POST', $this->deleteUrl($draft), [DraftRoundController::CSRF_FIELD => $this->csrfTokenFor($this->client, 'another_form')]);
        self::assertSame(RefusedWithToast::FORM_EXPIRED, $this->toastAfterTheRedirect());
        $this->client->request('POST', $this->newTicketUrl($draft), [DraftRoundController::CSRF_FIELD => 'not-the-token'], [], self::TURBO_FORM_HEADERS);
        self::assertSame(RefusedWithToast::FORM_EXPIRED, $this->toastOfTheRefusal());
        self::assertSame($before, $this->roundRowsOf($ticketId));
        self::assertSame([], $this->reportDraftRows());
    }

    /**
     * ADR-0044, P13.T2 step 3: Mia's draft on SHOP-1 in Testing, with its pictures and pins seeded out of order, and
     * Omar's own draft beside it, which must stay as it is.
     */
    #[Test]
    public function makeItANewTicketMovesTheTestersDraftIntoHerReportDraftInOrderAndMakesNoTicket(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $this->putInTestingWithMiasTurn($ticketId);
        $draft = $this->addRound($ticketId, null, 'draft', 'Mia', null);
        $secondPicture = $this->addMedia('ready', 'Mia');
        $firstPicture = $this->addMedia('ready', 'Mia');
        $second = $this->addScreenshot($draft, $secondPicture, 1, 'Cart total', null, 'not_right');
        $first = $this->addScreenshot($draft, $firstPicture, 0, null, self::MIA_DRAFT_SUMMARY, 'looks_right');
        $this->addPin($first, 2, '40.25', '60.50', 'Second pin note', 'Mia');
        $this->addPin($first, 1, '12.50', '20.75', self::MIA_DRAFT_NOTE, 'Mia');
        $this->addPin($second, 1, '80.00', '5.25', 'Total doubled', 'Mia');
        $omarsDraft = $this->addRound($ticketId, null, 'draft', 'Omar', null);
        $this->addScreenshot($omarsDraft, $this->addMedia('ready', 'Omar'), 0, self::OMAR_DRAFT_SHOT, null);
        $omarsDraftBefore = [$this->roundRow($ticketId, $omarsDraft), $this->screenshotRowsOf($omarsDraft)];
        $ticketsBefore = $this->ticketCount();
        self::assertSame(1, $ticketsBefore);
        self::assertSame([], $this->reportDraftRows());
        $picturesBefore = $this->mediaRowsOf([$firstPicture, $secondPicture]);
        self::assertCount(2, $picturesBefore);
        $draftScreenshotsBefore = $this->screenshotRowsOf($draft);

        $this->logIn($this->client, $this->people['Mia']);
        $button = $this->frame()->filter('#round-'.$draft.' form.round-card__form')->reduce(static fn (Crawler $form): bool => 'Make it a new ticket' === $form->filter('button')->text());
        self::assertCount(1, $button);
        self::assertSame($this->newTicketUrl($draft), $button->attr('action'));
        self::assertSame('_top', $button->attr('data-turbo-frame'));
        $form = $button->form();
        $outboxBefore = $this->outboxMessages();
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(303);
        $reportDrafts = $this->reportDraftRows();
        self::assertCount(1, $reportDrafts);
        $reportDraft = $reportDrafts[0];
        self::assertIsString($reportDraft['id']);
        self::assertSame('/report-drafts/'.$reportDraft['id'], $this->client->getResponse()->headers->get('Location'));
        self::assertSame(
            [$this->people['Mia']->id()->toString(), 'SHOP', 'Cart total', 'Found while testing SHOP-1', 1, self::NOW_AS_STORED, self::NOW_AS_STORED],
            [$reportDraft['owner_id'], $reportDraft['project_key'], $reportDraft['title'], $reportDraft['detail'], $reportDraft['version'], $reportDraft['created_at'], $reportDraft['updated_at']],
            'The first title among the pictures is the report\'s; the detail names the ticket it was found on.',
        );
        self::assertSame(
            [
                [$firstPicture, null, self::MIA_DRAFT_SUMMARY, [[12.5, 20.75, self::MIA_DRAFT_NOTE], [40.25, 60.5, 'Second pin note']]],
                [$secondPicture, 'Cart total', null, [[80.0, 5.25, 'Total doubled']]],
            ],
            self::picturesOf($reportDraft['screenshots']),
            'The same pictures, by position, each with its pins by number.',
        );

        $page = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSame(DraftRoundController::MOVED_TO_REPORT_DRAFT, $page->filter('.toast-region [data-toast-message-value]')->attr('data-toast-message-value'));
        $content = (string) $this->client->getResponse()->getContent();
        foreach ([self::MIA_DRAFT_NOTE, 'Second pin note', 'Total doubled', self::MIA_DRAFT_SUMMARY] as $moved) {
            self::assertStringContainsString($moved, $content, 'The report draft page shows what moved.');
        }
        $images = $page->filter('.report-draft__picture img.screenshot__image')->each(static fn (Crawler $image): string => (string) $image->attr('src'));
        self::assertCount(2, $images, 'Both pictures show, none as a placeholder.');
        self::assertStringContainsString('media/'.$firstPicture.'/view.webp', $images[0]);
        self::assertStringContainsString('media/'.$secondPicture.'/view.webp', $images[1]);
        self::assertSame($picturesBefore, $this->mediaRowsOf([$firstPicture, $secondPicture]), 'The pictures stay in Media as they were.');
        self::assertSame($draftScreenshotsBefore, $this->screenshotRowsOf($draft), 'The deleted draft keeps pointing to them; nothing is removed.');
        self::assertSame($outboxBefore, $this->outboxMessages(), 'Nothing is queued: no delete of a picture, nor anything else.');

        $closed = $this->roundRow($ticketId, $draft);
        self::assertSame(['closed', self::NOW_AS_STORED, null], [$closed['state'], $closed['deleted_at'], $closed['shared_at']]);
        self::assertSame($ticketsBefore, $this->ticketCount(), 'No ticket until Send.');
        self::assertSame($omarsDraftBefore, [$this->roundRow($ticketId, $omarsDraft), $this->screenshotRowsOf($omarsDraft)]);
        $frame = $this->frame();
        self::assertCount(0, $frame->filter('#round-'.$draft), 'The round draft is closed.');
        self::assertCount(1, $frame->filter(self::ROUNDS.' form.rounds__start'), 'Mia may start a new draft while it is her turn.');

        $this->client->submit($form);

        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString(RoundRefused::NOT_FOUND, (string) $this->client->getResponse()->getContent());
        self::assertSame($reportDrafts, $this->reportDraftRows(), 'A second click makes no second report draft.');
        self::assertSame($closed, $this->roundRow($ticketId, $draft));
        self::assertSame($ticketsBefore, $this->ticketCount());
    }

    #[Test]
    public function makeItANewTicketOnADraftOfElevenScreenshotsIsRefusedAndNothingChanges(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $report = $this->addRound($ticketId, 0, 'report', 'Mia', self::SHARED_AT);
        $draft = $this->addRound($ticketId, null, 'draft', 'Omar', null);
        for ($position = 0; $position < 11; ++$position) {
            $shot = $this->addScreenshot($draft, $this->addMedia('ready', 'Omar'), $position, 'Picture '.($position + 1), null);
            $this->addPin($shot, 1, '10.00', '20.00', 'Pin on picture '.($position + 1), 'Omar');
        }
        $before = $this->draftState($ticketId, $report, $draft);
        self::assertCount(11, $before['screenshots']);
        $this->logIn($this->client, $this->people['Omar']);
        $token = $this->csrfTokenFor($this->client, DraftRoundController::csrfIdOf('SHOP-1'));

        $this->client->request('POST', $this->newTicketUrl($draft), [DraftRoundController::CSRF_FIELD => $token], [], self::TURBO_FORM_HEADERS);

        self::assertSame('A report holds at most 10 screenshots.', $this->toastOfTheRefusal());
        self::assertSame($before, $this->draftState($ticketId, $report, $draft));

        $this->client->request('POST', $this->newTicketUrl($draft), [DraftRoundController::CSRF_FIELD => $token]);

        self::assertSame(ReportDraftRefused::TOO_MANY_SCREENSHOTS, $this->toastAfterTheRedirect());
        self::assertSame($before, $this->draftState($ticketId, $report, $draft));
    }

    /**
     * Without the voter, the command would answer its "shared already" refusal as a toast here, not NOT_FOUND.
     */
    #[Test]
    public function makeItANewTicketOnTheOwnersSharedDraftIsNotFoundAndNothingChanges(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $shared = $this->addRound($ticketId, 1, 'draft', 'Omar', self::SHARED_AT);
        $this->addScreenshot($shared, $this->addMedia('ready', 'Omar'), 0, self::OMAR_DRAFT_SHOT, null);
        $before = $this->roundRowsOf($ticketId);
        $this->logIn($this->client, $this->people['Omar']);

        $this->client->request('POST', $this->newTicketUrl($shared), [DraftRoundController::CSRF_FIELD => $this->csrfTokenFor($this->client, DraftRoundController::csrfIdOf('SHOP-1'))], [], self::TURBO_FORM_HEADERS);

        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString(RoundRefused::NOT_FOUND, (string) $this->client->getResponse()->getContent());
        self::assertSame($before, $this->roundRowsOf($ticketId));
        self::assertSame([], $this->reportDraftRows());
    }

    #[Test]
    public function aMalformedOrUnknownRoundIdOrARoundOfAnotherTicketIsNotFound(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $otherTicketId = $this->addTicket('SHOP-2', 'all', 2);
        $otherTicketsDraft = $this->addRound($otherTicketId, null, 'draft', 'Omar', null);
        $before = $this->roundRowsOf($otherTicketId);
        $this->logIn($this->client, $this->people['Omar']);
        $token = $this->csrfTokenFor($this->client, DraftRoundController::csrfIdOf('SHOP-1'));

        foreach (['not-a-uuid', Uuid::v7()->toRfc4122(), strtoupper(Uuid::v7()->toRfc4122()), $otherTicketsDraft] as $roundId) {
            foreach ([$this->shareUrl($roundId), $this->deleteUrl($roundId), $this->newTicketUrl($roundId)] as $url) {
                $this->client->request('POST', $url, [DraftRoundController::CSRF_FIELD => $token]);

                self::assertResponseStatusCodeSame(404, $url);
                self::assertStringContainsString(RoundRefused::NOT_FOUND, (string) $this->client->getResponse()->getContent());
            }
        }
        self::assertSame($before, $this->roundRowsOf($otherTicketId));
        self::assertSame([], $this->roundRowsOf($ticketId));
    }

    #[Test]
    public function theOwnersUnsharedDraftAloneHasItsButtonsAndTheClaudeCodeBoxWithTheTicketsKey(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $this->addRound($ticketId, 1, 'draft', 'Omar', self::SHARED_AT);
        $draft = $this->addRound($ticketId, null, 'draft', 'Omar', null);
        $this->logIn($this->client, $this->people['Omar']);

        $frame = $this->frame();

        self::assertCount(2, $frame->filter(self::ROUNDS.' .round-card'));
        $boxes = $frame->filter(self::ROUNDS.' .claude-code-box');
        self::assertCount(1, $boxes);
        self::assertCount(1, $frame->filter('#round-'.$draft.' .claude-code-box'));
        self::assertSame('For Claude Code', $boxes->filter('h4')->text());
        $prompt = $boxes->filter('textarea.claude-code-box__prompt[readonly][data-copy-text-target="source"]');
        self::assertCount(1, $prompt, 'The line is a read-only field, so copy-text can copy its value and select it.');
        self::assertSame('Read my pinround notes for SHOP-1 and fix what is marked “Not right yet”.', $prompt->text());
        self::assertSame('Line to give Claude Code', $prompt->attr('aria-label'));
        $copyBlock = $boxes->filter('[data-controller="copy-text"]');
        self::assertCount(1, $copyBlock);
        $controls = $copyBlock->filter('[data-copy-text-target="controls"]');
        self::assertNotNull($controls->attr('hidden'), 'The Copy button comes hidden; copy-text shows it only where the browser can copy.');
        self::assertSame('copy-text#copy', $controls->filter('button')->attr('data-action'));
        self::assertSame('button', $controls->filter('button')->attr('type'), 'Copy never submits a form.');
        self::assertCount(1, $controls->filter('[role="status"][data-copy-text-target="status"]'));
        $tools = $boxes->filter('.claude-code-box__tools')->text();
        foreach (['get_round_notes', 'add_screenshot', 'set_verdict'] as $tool) {
            self::assertStringContainsString($tool, $tools);
        }
        self::assertSame('To connect Claude Code, make a token on the API tokens page.', $boxes->filter('.claude-code-box__tokens')->text());
        $tokensLink = $boxes->filter('.claude-code-box__tokens a');
        self::assertSame(['API tokens', '/me/tokens', '_top'], [$tokensLink->text(), $tokensLink->attr('href'), $tokensLink->attr('data-turbo-frame')], 'The link leaves the rounds frame for the whole page.');
        self::assertSame(
            ['Add screenshot', 'Share with the team now', 'Make it a new ticket', 'Delete draft', 'Copy line'],
            $frame->filter(self::ROUNDS.' button')->each(static fn (Crawler $button): string => $button->text()),
            'Only the private draft has buttons; the start button is gone beside it.',
        );
        self::assertSame('Delete this draft round? Its screenshots, pins and notes go too.', $frame->filter('#round-'.$draft.' form.round-card__form:has(.round-card__delete)')->attr('data-turbo-confirm'), 'Deleting asks first.');
        self::assertNull($frame->filter('#round-'.$draft.' form:has(.round-card__share)')->attr('data-turbo-confirm'), 'Sharing does not ask.');
        self::assertSame('Claude Code can read these notes and verdicts and add screenshots. Tools: get_round_notes, add_screenshot, set_verdict.', $tools, 'The tools exist now: no "Planned" or "Not built yet", and no repository path or library name.');

        $this->logIn($this->client, $this->people['Lena']);
        $frame = $this->frame();
        self::assertSame(['Round 1 · self-check by Omar'], $frame->filter(self::ROUNDS.' .round-card__headline')->each(static fn (Crawler $headline): string => $headline->text()));
        self::assertCount(0, $frame->filter(self::ROUNDS.' .claude-code-box, '.self::ROUNDS.' form'));
    }

    #[Test]
    public function eachPersonSeesTheClaudeCodeBoxOnlyInTheirOwnUnsharedDraft(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $omarsDraft = $this->addRound($ticketId, null, 'draft', 'Omar', null);
        $lenasDraft = $this->addRound($ticketId, null, 'draft', 'Lena', null);
        $this->addRound($ticketId, 1, 'draft', 'Mia', self::SHARED_AT);

        foreach (['Omar' => $omarsDraft, 'Lena' => $lenasDraft] as $owner => $ownDraft) {
            $this->logIn($this->client, $this->people[$owner]);
            $frame = $this->frame();
            self::assertSame(['Round 1 · findings by Mia', 'Draft round · only you can see this'], $frame->filter(self::ROUNDS.' .round-card__headline')->each(static fn (Crawler $headline): string => $headline->text()), $owner.' sees their own draft and the shared round.');
            self::assertCount(1, $frame->filter(self::ROUNDS.' .claude-code-box'), $owner);
            self::assertCount(1, $frame->filter('#round-'.$ownDraft.' .claude-code-box'), $owner);
        }

        foreach (['Mia', 'Raj', 'Sara'] as $person) {
            $this->logIn($this->client, $this->people[$person]);
            $frame = $this->frame();
            self::assertSame(['Round 1 · findings by Mia'], $frame->filter(self::ROUNDS.' .round-card__headline')->each(static fn (Crawler $headline): string => $headline->text()), $person.' sees the shared round only.');
            self::assertCount(0, $frame->filter(self::ROUNDS.' .claude-code-box, '.self::ROUNDS.' [data-controller="copy-text"]'), $person.': a shared round has no box, not even for its owner.');
            self::assertStringNotContainsString('Read my pinround notes', (string) $this->client->getResponse()->getContent(), $person);
        }
    }

    /**
     * @param string|null $draft the draft's id, also looked for unless the request itself carried it
     */
    private function assertHoldsNothingOfMiasDraft(string $message, ?string $draft): void
    {
        $content = (string) $this->client->getResponse()->getContent();
        foreach (array_filter([self::MIA_DRAFT_SHOT, self::MIA_DRAFT_NOTE, self::MIA_DRAFT_SUMMARY, $draft], static fn (?string $privateText): bool => null !== $privateText) as $privateText) {
            self::assertStringNotContainsString($privateText, $content, $message);
        }
    }

    /**
     * Posts a begin request as the presigned-upload controller sends it, for a screenshot of the round $roundId.
     */
    private function beginUploadTo(string $roundId): void
    {
        $this->client->request(
            'POST',
            '/media/intents',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->csrfTokenFor($this->client, UploadIntentController::CSRF_ID)],
            json_encode(['purpose' => 'screenshot', 'mime' => 'image/png', 'bytes' => 245_760, 'target' => ['kind' => 'round', 'id' => $roundId]], \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return array<string, mixed> the JSON answer of the last request
     */
    private function jsonAnswer(): array
    {
        $answer = json_decode((string) $this->client->getResponse()->getContent(), true, 8, \JSON_THROW_ON_ERROR);
        self::assertIsArray($answer);
        $fields = [];
        foreach ($answer as $name => $value) {
            self::assertIsString($name);
            $fields[$name] = $value;
        }

        return $fields;
    }

    /**
     * What a stray write would change: the ticket's rounds, the report's and the draft's screenshots, the draft's pins,
     * the organization's upload intents and report drafts, and how many tickets there are.
     *
     * @return array{rounds: list<array<string, mixed>>, screenshots: list<array<string, mixed>>, pins: list<array<string, mixed>>, intents: list<array<string, mixed>>, reportDrafts: list<array<string, mixed>>, tickets: int}
     */
    private function draftState(string $ticketId, string $report, string $draft): array
    {
        $organizationId = $this->acmeFoods->toString();
        $intents = [];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId, &$intents): int {
            $intents = $migrator->fetchAllAssociative('SELECT id, user_id, target_kind, target_id FROM med_upload_intent WHERE organization_id = ? ORDER BY id', [$organizationId]);

            return \count($intents);
        });

        return [
            'rounds' => $this->roundRowsOf($ticketId),
            'screenshots' => [...$this->screenshotRowsOf($report), ...$this->screenshotRowsOf($draft)],
            'pins' => $this->pinRowsOf($draft),
            'intents' => $intents,
            'reportDrafts' => $this->reportDraftRows(),
            'tickets' => $this->ticketCount(),
        ];
    }

    /**
     * @return list<array<string, mixed>> the organization's report drafts as stored, oldest first
     */
    private function reportDraftRows(): array
    {
        $organizationId = $this->acmeFoods->toString();
        $rows = [];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId, &$rows): int {
            $rows = $migrator->fetchAllAssociative('SELECT id, owner_id, project_key, title, detail, screenshots::text AS screenshots, version, created_at, updated_at FROM rvw_report_draft WHERE organization_id = ? ORDER BY id', [$organizationId]);

            return \count($rows);
        });

        return $rows;
    }

    /**
     * A report draft's screenshots column, read field by field, as jsonb keeps its keys in an order of its own.
     *
     * @return list<array{mixed, mixed, mixed, list<array{mixed, mixed, mixed}>}> each picture's media id, title, summary
     *                                                                            and pins (x, y, note)
     */
    private static function picturesOf(mixed $screenshotsColumn): array
    {
        self::assertIsString($screenshotsColumn);
        $stored = json_decode($screenshotsColumn, true, 8, \JSON_THROW_ON_ERROR);
        self::assertIsList($stored);
        $pictures = [];
        foreach ($stored as $picture) {
            self::assertIsArray($picture);
            self::assertIsList($picture['pins']);
            $pins = [];
            foreach ($picture['pins'] as $pin) {
                self::assertIsArray($pin);
                $pins[] = [$pin['x_pct'], $pin['y_pct'], $pin['note']];
            }
            $pictures[] = [$picture['media_id'], $picture['title'], $picture['summary'], $pins];
        }

        return $pictures;
    }

    /**
     * @param list<string> $mediaIds
     *
     * @return list<array<string, mixed>> the pictures' Media rows as stored, by id
     */
    private function mediaRowsOf(array $mediaIds): array
    {
        $organizationId = $this->acmeFoods->toString();
        $rows = [];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId, $mediaIds, &$rows): int {
            $rows = $migrator->fetchAllAssociative(
                'SELECT id, storage_key, status, uploaded_by, variants::text AS variants FROM med_object WHERE organization_id = ? AND id = ANY(CAST(? AS uuid[])) ORDER BY id',
                [$organizationId, '{'.implode(',', $mediaIds).'}'],
            );

            return \count($rows);
        });

        return $rows;
    }

    /**
     * @return list<array<string, mixed>> every message waiting in the outbox, which the relay would hand to a worker
     */
    private function outboxMessages(): array
    {
        $rows = [];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use (&$rows): int {
            $rows = $migrator->fetchAllAssociative('SELECT id, body FROM messenger_outbox ORDER BY id');

            return \count($rows);
        });

        return $rows;
    }

    private function ticketCount(): int
    {
        $organizationId = $this->acmeFoods->toString();
        $count = 0;
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId, &$count): int {
            $ticketCount = $migrator->fetchOne('SELECT count(*) FROM tkt_ticket WHERE organization_id = ?', [$organizationId]);
            self::assertIsInt($ticketCount);
            $count = $ticketCount;

            return $count;
        });

        return $count;
    }

    private function frame(): Crawler
    {
        $frame = $this->client->request('GET', self::FRAME, [], [], self::TURBO_FRAME_HEADERS);
        self::assertResponseStatusCodeSame(200);

        return $frame;
    }

    private function shareUrl(string $roundId): string
    {
        return '/t/SHOP-1/rounds/'.$roundId.'/share';
    }

    private function deleteUrl(string $roundId): string
    {
        return '/t/SHOP-1/rounds/'.$roundId.'/delete';
    }

    private function newTicketUrl(string $roundId): string
    {
        return '/t/SHOP-1/rounds/'.$roundId.'/new-ticket';
    }

    private function toastAfterTheRedirect(): string
    {
        self::assertResponseStatusCodeSame(303);
        self::assertSame(self::TICKET_PAGE, $this->client->getResponse()->headers->get('Location'));
        $page = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        $toast = $page->filter('.toast-region [data-toast-message-value]');
        self::assertCount(1, $toast);

        return (string) $toast->attr('data-toast-message-value');
    }

    private function toastOfTheRefusal(): string
    {
        self::assertResponseStatusCodeSame(422);
        self::assertSame('text/vnd.turbo-stream.html; charset=UTF-8', $this->client->getResponse()->headers->get('Content-Type'));
        $matched = preg_match('/data-toast-message-value="([^"]*)"/', (string) $this->client->getResponse()->getContent(), $toast);
        self::assertSame(1, $matched);

        return html_entity_decode($toast[1], \ENT_QUOTES | \ENT_HTML5);
    }

    /**
     * Before any request: an action that throws in its transaction leaves the test's entity manager closed.
     */
    private function otherOrganizationsAdmin(): User
    {
        $betaBakery = $this->createOrganization('Beta Bakery');
        $ben = $this->createUserWithoutOrganization('Ben');
        $this->addMembership($ben, $betaBakery);
        $this->setOrgRoles($ben, $betaBakery, '{admin}');

        return $ben;
    }

    /**
     * @return array<string, mixed>
     */
    private function roundRow(string $ticketId, string $roundId): array
    {
        foreach ($this->roundRowsOf($ticketId) as $row) {
            if ($roundId === $row['id']) {
                return $row;
            }
        }
        self::fail('No round '.$roundId.' on the ticket.');
    }
}
