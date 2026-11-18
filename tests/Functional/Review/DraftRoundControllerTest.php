<?php

declare(strict_types=1);

namespace App\Tests\Functional\Review;

use App\Identity\Domain\User;
use App\Review\Api\RoundRefused;
use App\Review\UI\Component\DraftRoundEditor;
use App\Review\UI\Component\RoundsSection;
use App\Review\UI\Controller\RefusedWithToast;
use App\Review\UI\DraftRoundController;
use App\Review\UI\RoundsController;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use App\Tests\Functional\Web\IssuesCsrfTokens;
use Doctrine\DBAL\Connection;
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

    protected function tearDown(): void
    {
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
        self::assertSame(['Share with the team now', 'Delete draft'], $frame->filter('#round-'.$draft.' form button')->each(static fn (Crawler $button): string => $button->text()), 'Share and Delete stay.');
    }

    #[Test]
    public function noOneButTheAssigneeSeesTheStartButtonOrMayStartADraft(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $ben = $this->otherOrganizationsAdmin();

        foreach (self::OTHERS as $person) {
            $this->logIn($this->client, $this->people[$person]);
            self::assertCount(0, $this->frame()->filter(self::ROUNDS.' form.rounds__start, .rounds__start-draft'), $person);

            $this->client->request('POST', self::START, [DraftRoundController::CSRF_FIELD => $this->csrfTokenFor($this->client, DraftRoundController::csrfIdOf('SHOP-1'))]);

            self::assertSame("Only the ticket's assignee can start a draft round.", $this->toastAfterTheRedirect(), $person.' may see the ticket, so the denial is a toast, not a 404.');
            self::assertSame([], $this->roundRowsOf($ticketId), $person);

            $this->client->request('POST', self::START, [], [], self::TURBO_FORM_HEADERS);

            self::assertSame("Only the ticket's assignee can start a draft round.", $this->toastOfTheRefusal(), $person.': the voter runs before the form token is read.');
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

        foreach ([self::START, $this->shareUrl($draft), $this->deleteUrl($draft)] as $url) {
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

            foreach ([$this->shareUrl($draft), $this->deleteUrl($draft)] as $url) {
                $this->client->request('POST', $url, [DraftRoundController::CSRF_FIELD => $this->csrfTokenFor($this->client, DraftRoundController::csrfIdOf('SHOP-1'))]);

                self::assertResponseStatusCodeSame(404, $person.' '.$url);
                self::assertStringContainsString(RoundRefused::NOT_FOUND, (string) $this->client->getResponse()->getContent());
                self::assertSame($before, $this->roundRowsOf($ticketId), $person.' '.$url);
            }
        }

        $this->logIn($this->client, $ben);
        foreach ([$this->shareUrl($draft), $this->deleteUrl($draft)] as $url) {
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

        foreach ([$this->shareUrl($rajsRound), $this->deleteUrl($rajsRound)] as $url) {
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
                foreach ([$this->shareUrl($roundId), $this->deleteUrl($roundId)] as $url) {
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
        self::assertSame($before, $this->roundRowsOf($ticketId));
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
            foreach ([$this->shareUrl($roundId), $this->deleteUrl($roundId)] as $url) {
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
            ['Add screenshot', 'Share with the team now', 'Delete draft', 'Copy line'],
            $frame->filter(self::ROUNDS.' button')->each(static fn (Crawler $button): string => $button->text()),
            'Only the private draft has buttons; the start button is gone beside it.',
        );
        self::assertSame('Delete this draft round? Its screenshots, pins and notes go too.', $frame->filter('#round-'.$draft.' form.round-card__form:has(.round-card__delete)')->attr('data-turbo-confirm'), 'Deleting asks first.');
        self::assertNull($frame->filter('#round-'.$draft.' form:has(.round-card__share)')->attr('data-turbo-confirm'), 'Sharing does not ask.');
        self::assertStringContainsString('(built with mcp/sdk)', $tools);

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
            self::assertSame(['Round 1 · self-check by Mia', 'Draft round · only you can see this'], $frame->filter(self::ROUNDS.' .round-card__headline')->each(static fn (Crawler $headline): string => $headline->text()), $owner.' sees their own draft and the shared round.');
            self::assertCount(1, $frame->filter(self::ROUNDS.' .claude-code-box'), $owner);
            self::assertCount(1, $frame->filter('#round-'.$ownDraft.' .claude-code-box'), $owner);
        }

        foreach (['Mia', 'Raj', 'Sara'] as $person) {
            $this->logIn($this->client, $this->people[$person]);
            $frame = $this->frame();
            self::assertSame(['Round 1 · self-check by Mia'], $frame->filter(self::ROUNDS.' .round-card__headline')->each(static fn (Crawler $headline): string => $headline->text()), $person.' sees the shared round only.');
            self::assertCount(0, $frame->filter(self::ROUNDS.' .claude-code-box, '.self::ROUNDS.' [data-controller="copy-text"]'), $person.': a shared round has no box, not even for its owner.');
            self::assertStringNotContainsString('Read my pinround notes', (string) $this->client->getResponse()->getContent(), $person);
        }
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
