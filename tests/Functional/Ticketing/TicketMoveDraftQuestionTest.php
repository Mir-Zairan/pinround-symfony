<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ticketing;

use App\Identity\Domain\User;
use App\Review\Api\RoundCommands;
use App\Review\Application\LockedRoundClosing;
use App\Review\Application\RoundShareDraftQuestion;
use App\Review\Domain\Rounds;
use App\Review\UI\Component\RoundsSection;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Review\SeedsTicketRounds;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use App\Tests\Functional\Web\IssuesCsrfTokens;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Ticketing\Api\MoveFacts;
use App\Ticketing\Api\MoveResult;
use App\Ticketing\Api\ShareDraftQuestion;
use App\Ticketing\Api\TicketChangedMeanwhile;
use App\Ticketing\Api\TicketCommands;
use App\Ticketing\Api\UndecidedDraft;
use App\Ticketing\Infrastructure\Scheduler\AddTicketEventPartitions;
use App\Ticketing\UI\MoveToasts;
use App\Ticketing\UI\TicketMoveController;
use App\Ticketing\UI\TicketPageParts;
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
 * "Share your draft round?" on a verb move and a move to a chosen step of Acme Foods' SHOP-1 (P05.T9, ADR-0017, hand-off), through the real
 * firewall, voters, policy and database. Omar is the assignee and owns the draft with two screenshots; Lena leads Shop
 * and has a draft of her own, Mia is a member, Raj a manager and Sara an admin of no team. Mia's round 1 is shared and
 * closed, so Omar's draft would be round 2. The clock is fixed on 6 Nov 2026, before today: a clock ahead of the login would end
 * the session as idle.
 */
final class TicketMoveDraftQuestionTest extends WebTestCase
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
    private const string KEY = 'SHOP-1';
    private const string START_WORK = '/t/SHOP-1/move/start_work';
    private const string NEEDS_CHANGES = '/t/SHOP-1/move/needs_changes';
    private const string TICKET_PAGE = '/t/SHOP-1';
    /** The version SHOP-1 is seeded at (SeedsTicketRounds). */
    private const int VERSION = 3;
    private const array TURBO_FORM_HEADERS = ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml'];

    private KernelBrowser $client;

    /** @var array<string, User> */
    private array $people;

    /** Read by SeedsTicketRounds. */
    protected string $shopProject;
    /** To do, where SHOP-1 starts. */
    private string $stepId;

    /** @var array<string, string> step id by core code */
    private array $steps = [];

    private string $ticketId;
    private string $omarsDraft;
    private string $lenasDraft;

    /** How many rounds SHOP-1 has once a test has seeded its own; stateOf checks it. */
    private int $seededRoundCount = 3;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTPS' => 'on']);
        $this->client->disableReboot();
        $clock = new MockClock(new \DateTimeImmutable(self::NOW));
        self::getContainer()->set('clock', $clock);
        new AddTicketEventPartitions($this->migrator(), $clock)();
        $this->people = $this->createAcmeFoodsPeople('member');
        $this->committedAs($this->acmeFoods, fn (Connection $migrator): int|string => $migrator->executeStatement(
            "UPDATE org_team_member SET job = 'developer' WHERE team_id = ? AND user_id = ?",
            [$this->shop->toString(), $this->people['Omar']->id()->toString()],
        ));
        $this->shopProject = $this->addProject($this->shop, 'SHOP', 'Shop');
        $this->addSteps();
        $this->stepId = $this->steps['todo'];
        $this->ticketId = $this->addTicket(self::KEY);
        $miasRound = $this->addRound($this->ticketId, 1, 'draft', 'Mia', '2026-11-05 09:00:00+00');
        $this->addScreenshot($miasRound, $this->addMedia('ready', 'Mia'), 0, 'Shared shot', null);
        // Closed, so it holds back no share of these hand-offs (ADR-0048, one open shared round per ticket).
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "UPDATE rvw_round SET state = 'closed' WHERE id = ?",
            [$miasRound],
        ));
        $this->lenasDraft = $this->addRound($this->ticketId, null, 'draft', 'Lena', null);
        $this->addScreenshot($this->lenasDraft, $this->addMedia('ready', 'Lena'), 0, 'Lena own shot', null);
        $this->omarsDraft = $this->addRound($this->ticketId, null, 'draft', 'Omar', null);
    }

    protected function tearDown(): void
    {
        $this->removeSeededRounds();
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->committedAs(new OrganizationId($organizationId), static fn (Connection $migrator): int|string => $migrator->executeStatement(
                "DELETE FROM messenger_outbox WHERE queue_name = 'outbox' AND position(? in body) > 0",
                [$organizationId],
            ));
        }
        $this->removeCommittedProjects();
        $this->removeUsersAndOrganizations();
    }

    /**
     * @return iterable<string, array{string|null}> the draft choice the form sends; null for none
     */
    public static function formsWithNoChoice(): iterable
    {
        yield 'no choice' => [null];
        yield 'an empty choice' => [''];
        yield 'a choice in upper case' => ['SHARE'];
        yield 'a choice with a trailing newline' => ["keep\n"];
        yield 'a choice of no value' => ['yes'];
    }

    #[Test]
    #[DataProvider('formsWithNoChoice')]
    public function aMoveWithNoChoiceAsksAboutTheOwnersDraftAndMovesNothing(?string $choice): void
    {
        $this->addOmarsScreenshots(2);
        $before = $this->stateOf();
        $this->logIn($this->client, $this->people['Omar']);

        $this->client->request('POST', self::START_WORK, $this->moveFields(null === $choice ? [] : [TicketMoveController::DRAFT_CHOICE_FIELD => $choice]));

        self::assertResponseStatusCodeSame(409);
        $this->assertTheQuestionPage(2, 2, 'In progress', self::START_WORK);
        self::assertSame($before, $this->stateOf());
    }

    #[Test]
    public function shareMovesTheTicketThenSharesTheDraftAsTheNextRoundAndAsksNoMore(): void
    {
        $this->addOmarsScreenshots(2);
        $before = $this->stateOf();
        $this->logIn($this->client, $this->people['Omar']);

        $this->client->request('POST', self::START_WORK, $this->moveFields([TicketMoveController::DRAFT_CHOICE_FIELD => 'share']));

        self::assertSame('Moved to In progress. Your draft is now round 2 for the team.', $this->toastAfterTheRedirect());
        $after = $this->stateOf();
        self::assertSame([...$before['ticket'], 'step_id' => $this->steps['progress'], 'version' => self::VERSION + 1, 'events' => $before['ticket']['events'] + 1], $after['ticket']);
        self::assertSame(
            [...$before['rounds'][$this->omarsDraft], 'number' => 2, 'shared_at' => self::NOW_AS_STORED, 'version' => 3, 'decision' => 'shared', 'decided_at' => self::NOW_AS_STORED],
            $after['rounds'][$this->omarsDraft],
        );
        self::assertSame(array_diff_key($before['rounds'], [$this->omarsDraft => true]), array_diff_key($after['rounds'], [$this->omarsDraft => true]), 'Lena\'s draft and Mia\'s round stay as they were.');

        // Round 2 was shared at a hand-off, so Omar's next move in his turn closes it (ADR-0048).
        $this->assertASecondMoveAsksNothing([...$after['rounds'], $this->omarsDraft => [...$after['rounds'][$this->omarsDraft], 'state' => 'closed', 'version' => 4]]);
    }

    /**
     * FR-RND-11: the page shows the shared round with no reload. The move's stream replaces the Rounds section, whose
     * fresh lazy frame loads the rounds again: the draft's row with its "Open draft" is gone, Round 2 is there.
     */
    #[Test]
    public function aTurboShareReplacesTheRoundsSectionSoItsFrameShowsTheSharedRoundAndNoDraft(): void
    {
        $this->addOmarsScreenshots(1);
        $this->logIn($this->client, $this->people['Omar']);
        $before = $this->roundsFrame();
        self::assertSame(['Draft round · only you can see this'], $before->filter('article.round-card--private h3')->each(static fn (Crawler $heading): string => $heading->text()));
        self::assertCount(1, $before->filter('article.round-card--private a.round-card__open'), 'The draft opens its page.');

        $this->client->request('POST', self::START_WORK, $this->moveFields([TicketMoveController::DRAFT_CHOICE_FIELD => 'share']), [], self::TURBO_FORM_HEADERS);

        self::assertResponseStatusCodeSame(200);
        $content = (string) $this->client->getResponse()->getContent();
        $roundsTarget = '<turbo-stream action="replace" target="'.TicketPageParts::ROUNDS_ID.'"><template>';
        self::assertSame(1, substr_count($content, $roundsTarget), 'The move\'s stream replaces the Rounds section once.');
        $roundsTemplate = strstr(substr($content, (int) strpos($content, $roundsTarget)), '</template>', true);
        self::assertIsString($roundsTemplate, 'The Rounds stream closes its template.');
        $roundsStream = new Crawler($roundsTemplate);
        self::assertSame(self::TICKET_PAGE.'/rounds', $roundsStream->filter('section#'.TicketPageParts::ROUNDS_ID.' > turbo-frame#'.RoundsSection::FRAME_ID.'[loading="lazy"][data-live-refresh]')->attr('src'));

        $after = $this->roundsFrame();
        $headlines = $after->filter('article.round-card h3')->each(static fn (Crawler $heading): string => $heading->text());
        self::assertCount(1, array_filter($headlines, static fn (string $headline): bool => str_starts_with($headline, 'Round 2 · ')), implode(' | ', $headlines));
        self::assertNotContains('Draft round · only you can see this', $headlines);
        self::assertCount(0, $after->filter('.round-card--private'));
        self::assertStringNotContainsString('Open draft', (string) $this->client->getResponse()->getContent());
    }

    /**
     * ADR-0044: the dialog follows the draft's owner, not the verb, so the tester's "Needs changes" asks about her own
     * draft as the assignee's "Start work" asks about his.
     */
    #[Test]
    public function aTestersNeedsChangesAsksAboutHerDraftThenShareSendsTheTicketBackAndSharesIt(): void
    {
        $miasDraft = $this->putShop1InTestingWithAMiasDraftOfOneScreenshot();
        $before = $this->stateOf();
        $this->logIn($this->client, $this->people['Mia']);

        $this->client->request('POST', '/t/SHOP-1/move/needs_changes', $this->moveFields());

        self::assertResponseStatusCodeSame(409);
        $this->assertTheQuestionPage(1, 2, 'In progress', '/t/SHOP-1/move/needs_changes');
        self::assertSame($before, $this->stateOf());

        $this->client->request('POST', '/t/SHOP-1/move/needs_changes', $this->moveFields([TicketMoveController::DRAFT_CHOICE_FIELD => 'share']));

        self::assertStringEndsWith('Your draft is now round 2 for the team.', $this->toastAfterTheRedirect());
        $after = $this->stateOf();
        self::assertSame($this->steps['progress'], $after['ticket']['step_id']);
        self::assertSame(
            [...$before['rounds'][$miasDraft], 'number' => 2, 'shared_at' => self::NOW_AS_STORED, 'version' => 3, 'decision' => 'shared', 'decided_at' => self::NOW_AS_STORED],
            $after['rounds'][$miasDraft],
        );
        self::assertSame(array_diff_key($before['rounds'], [$miasDraft => true]), array_diff_key($after['rounds'], [$miasDraft => true]), 'Omar\'s and Lena\'s drafts and Mia\'s first round stay as they were.');
    }

    /**
     * ADR-0044, P13.T1: Omar's and Lena's drafts also hold screenshots, yet Mia's "Needs changes" opens one dialog, about
     * her own draft. Cancel only closes the dialog and sends nothing, so the ticket, her draft and the others stay put.
     */
    #[Test]
    public function aTestersNeedsChangesAsksOnceAboutOnlyHerDraftAndCancelMovesAndSharesNothing(): void
    {
        $this->addOmarsScreenshots(2);
        $miasDraft = $this->putShop1InTestingWithAMiasDraftOfOneScreenshot();
        $before = $this->stateOf();
        self::assertSame($this->steps['test'], $before['ticket']['step_id']);
        self::assertNull($before['rounds'][$miasDraft]['decision']);
        $this->logIn($this->client, $this->people['Mia']);

        $this->client->request('POST', self::NEEDS_CHANGES, $this->moveFields(), [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html']);

        self::assertResponseStatusCodeSame(409);
        $content = (string) $this->client->getResponse()->getContent();
        self::assertSame(1, substr_count($content, '<dialog id="share-draft-dialog"'), 'One dialog for one move.');
        self::assertSame(1, substr_count($content, 'Your draft round has '), 'One question, about one draft.');
        $this->assertTheQuestion($content, 1, 2, 'In progress', self::NEEDS_CHANGES);
        $cancelButtons = preg_match_all('/<button([^>]*)>\s*Cancel\s*<\/button>/', $content, $cancel);
        self::assertSame(1, $cancelButtons);
        self::assertStringContainsString('type="button"', $cancel[1][0], 'Cancel submits no form.');
        self::assertStringContainsString('data-action="dialog#close"', $cancel[1][0]);
        self::assertStringNotContainsString('name=', $cancel[1][0], 'Cancel sends no draft choice.');
        self::assertStringNotContainsString('form=', $cancel[1][0], 'Cancel belongs to no form.');
        self::assertSame($before, $this->stateOf());

        $this->client->request('POST', self::NEEDS_CHANGES, $this->moveFields());

        self::assertResponseStatusCodeSame(409);
        $cancelLink = $this->client->getCrawler()->filter('a')->reduce(static fn (Crawler $link): bool => 'Cancel' === trim($link->text()));
        self::assertCount(1, $cancelLink, 'Without script, Cancel is a link back to the ticket.');
        self::assertSame(self::TICKET_PAGE, $cancelLink->attr('href'));
        $this->client->click($cancelLink->link());
        self::assertResponseStatusCodeSame(200);
        self::assertSame($before, $this->stateOf(), 'Neither Cancel moved the ticket or shared or decided a draft.');
    }

    /**
     * Once Mia has chosen, her draft is no longer undecided: when the ticket comes back to Testing through code review,
     * her next "Needs changes" moves it with no question and leaves every round as it was.
     */
    #[Test]
    #[DataProvider('choices')]
    public function aTestersNextNeedsChangesAfterSheChoseAsksNothingMore(string $choice): void
    {
        $miasDraft = $this->putShop1InTestingWithAMiasDraftOfOneScreenshot();
        $this->logIn($this->client, $this->people['Mia']);
        $this->client->request('POST', self::NEEDS_CHANGES, $this->moveFields([TicketMoveController::DRAFT_CHOICE_FIELD => $choice]));
        self::assertStringEndsWith('share' === $choice ? 'Your draft is now round 2 for the team.' : MoveToasts::DRAFT_KEPT_PRIVATE, $this->toastAfterTheRedirect());
        self::assertSame('share' === $choice ? 'shared' : 'kept_private', $this->stateOf()['rounds'][$miasDraft]['decision']);

        $this->removeLenasScreenshots();
        $this->logIn($this->client, $this->people['Omar']);
        $this->client->request('POST', '/t/SHOP-1/move/send_to_code_review', $this->moveFields([TicketMoveController::VERSION_FIELD => (string) (self::VERSION + 1)]));
        self::assertSame('Sent to code review. Lena’s turn now.', $this->toastAfterTheRedirect(), 'Omar\'s draft has no screenshot, so he is not asked.');
        $this->logIn($this->client, $this->people['Lena']);
        $this->client->request('POST', '/t/SHOP-1/move/approve_code', $this->moveFields([TicketMoveController::VERSION_FIELD => (string) (self::VERSION + 2)]));
        self::assertSame('Code approved. Mia’s turn to test.', $this->toastAfterTheRedirect(), 'Lena\'s draft has no screenshot either.');
        $backInTesting = $this->stateOf();
        self::assertSame($this->steps['test'], $backInTesting['ticket']['step_id']);

        $this->logIn($this->client, $this->people['Mia']);
        $this->client->request('POST', self::NEEDS_CHANGES, $this->moveFields([TicketMoveController::VERSION_FIELD => (string) (self::VERSION + 3)]));

        self::assertSame('Sent back to Omar with your notes.', $this->toastAfterTheRedirect());
        $after = $this->stateOf();
        self::assertSame($this->steps['progress'], $after['ticket']['step_id']);
        self::assertSame(self::VERSION + 4, $after['ticket']['version']);
        self::assertSame($backInTesting['rounds'], $after['rounds'], 'Her decided draft and the other rounds stay as they were.');
    }

    #[Test]
    public function keepMovesTheTicketAndKeepsTheDraftPrivateAndAsksNoMore(): void
    {
        $this->addOmarsScreenshots(1);
        $before = $this->stateOf();
        $this->logIn($this->client, $this->people['Omar']);

        $this->client->request('POST', self::START_WORK, $this->moveFields([TicketMoveController::DRAFT_CHOICE_FIELD => 'keep']));

        self::assertSame('Moved to In progress. Your draft stays private.', $this->toastAfterTheRedirect());
        $after = $this->stateOf();
        self::assertSame([...$before['ticket'], 'step_id' => $this->steps['progress'], 'version' => self::VERSION + 1, 'events' => $before['ticket']['events'] + 1], $after['ticket']);
        self::assertSame(
            [...$before['rounds'][$this->omarsDraft], 'version' => 2, 'decision' => 'kept_private', 'decided_at' => self::NOW_AS_STORED],
            $after['rounds'][$this->omarsDraft],
        );
        self::assertNull($after['rounds'][$this->omarsDraft]['shared_at']);
        self::assertNull($after['rounds'][$this->omarsDraft]['number']);
        self::assertSame(array_diff_key($before['rounds'], [$this->omarsDraft => true]), array_diff_key($after['rounds'], [$this->omarsDraft => true]));

        $this->assertASecondMoveAsksNothing($after['rounds']);
    }

    /**
     * @return iterable<string, array{string}> the choice the form sends
     */
    public static function choices(): iterable
    {
        yield 'share' => ['share'];
        yield 'keep' => ['keep'];
    }

    /**
     * The draft is decided after the ticket is saved, its history written and Omar's pings cleared; a refusal there
     * rolls all of it back, the draft's own write too.
     */
    #[Test]
    #[DataProvider('choices')]
    public function aDraftAnswerRefusedAfterTheMoveRollsBackTheMoveThePingsAndTheDraft(string $choice): void
    {
        $this->addOmarsScreenshots(2);
        $omarsPing = $this->addPingToOmar();
        $before = $this->stateOf();
        $pingBefore = $this->stateOfPing($omarsPing);
        self::assertSame(['cleared_reason' => null, 'done' => false], $pingBefore);
        $written = $this->answerTheDraftQuestionThenRefuse();
        $this->logIn($this->client, $this->people['Omar']);

        $this->client->request('POST', self::START_WORK, $this->moveFields([TicketMoveController::DRAFT_CHOICE_FIELD => $choice]), [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('data-toast-message-value="'.MoveToasts::CHANGED_MEANWHILE.'"', (string) $this->client->getResponse()->getContent());
        self::assertSame('share' === $choice ? ['roundNumber' => 2] : ['kept' => true], $written->getArrayCopy(), 'The real share or keep wrote before the refusal.');
        self::assertSame($before, $this->stateOf());
        self::assertSame($this->stepId, $this->stateOf()['ticket']['step_id']);
        self::assertSame($pingBefore, $this->stateOfPing($omarsPing));
    }

    #[Test]
    public function aDraftWithNoScreenshotMovesWithNoQuestionAndStaysUndecided(): void
    {
        $before = $this->stateOf();
        $this->logIn($this->client, $this->people['Omar']);

        $this->client->request('POST', self::START_WORK, $this->moveFields());

        self::assertSame('Started. Status is In progress.', $this->toastAfterTheRedirect());
        $after = $this->stateOf();
        self::assertSame($this->steps['progress'], $after['ticket']['step_id']);
        self::assertSame($before['rounds'], $after['rounds']);
        self::assertNull($after['rounds'][$this->omarsDraft]['decision']);
    }

    /**
     * @return iterable<string, array{string, array<string, string>, string}> the path, the fields over the form's, and the toast
     */
    public static function refusedMovesWithAChoice(): iterable
    {
        foreach (['share', 'keep', null] as $choice) {
            $choiceFields = null === $choice ? [] : [TicketMoveController::DRAFT_CHOICE_FIELD => $choice];
            $label = $choice ?? 'no choice';
            yield 'a stale version, with '.$label => [self::START_WORK, [TicketMoveController::VERSION_FIELD => '2', ...$choiceFields], MoveToasts::CHANGED_MEANWHILE];
            yield 'a lead-only verb, with '.$label => ['/t/SHOP-1/move/put_on_hold', $choiceFields, MoveToasts::MOVE_REFUSED];
            yield 'a verb that does not apply in To do, with '.$label => ['/t/SHOP-1/move/pass_it', $choiceFields, MoveToasts::MOVE_REFUSED];
        }
    }

    /**
     * The move's own refusal comes before the question, and a choice never shares or decides without the move.
     *
     * @param array<string, string> $fields
     */
    #[Test]
    #[DataProvider('refusedMovesWithAChoice')]
    public function aRefusedMoveSaysWhyAndNeitherMovesNorSharesNorDecides(string $path, array $fields, string $expectedToast): void
    {
        $this->addOmarsScreenshots(2);
        $before = $this->stateOf();
        $this->logIn($this->client, $this->people['Omar']);

        $this->client->request('POST', $path, $this->moveFields($fields));

        self::assertSame($expectedToast, $this->toastAfterTheRedirect());
        self::assertSame($before, $this->stateOf());
        self::assertNull($before['rounds'][$this->omarsDraft]['decision']);
    }

    /**
     * @return iterable<string, array{string, string, int, string|null}> who posts, the verb, the status, and the toast
     *                                                                   after the redirect (null for a 403)
     */
    public static function peopleWhoMustNotDecideOmarsDraft(): iterable
    {
        yield 'Lena, the lead, who may put it on hold' => ['Lena', 'put_on_hold', 303, 'On hold. It waits on you.'];
        yield 'Mia, a member who is not the assignee' => ['Mia', 'start_work', 303, MoveToasts::MOVE_REFUSED];
        yield 'Raj, a manager of no team' => ['Raj', 'start_work', 403, null];
        yield 'Sara, an admin of no team' => ['Sara', 'start_work', 403, null];
    }

    #[Test]
    #[DataProvider('peopleWhoMustNotDecideOmarsDraft')]
    public function someoneElseMovingWithShareNeverSharesOrDecidesOmarsDraft(string $person, string $verb, int $expectedStatus, ?string $expectedToast): void
    {
        $this->addOmarsScreenshots(2);
        $this->removeLenasScreenshots();
        $before = $this->stateOf();
        $this->logIn($this->client, $this->people[$person]);

        $this->client->request('POST', '/t/SHOP-1/move/'.$verb, $this->moveFields([TicketMoveController::DRAFT_CHOICE_FIELD => 'share']));

        self::assertResponseStatusCodeSame($expectedStatus);
        if (null !== $expectedToast) {
            self::assertSame($expectedToast, $this->toastAfterTheRedirect());
        }
        self::assertSame($before['rounds'], $this->stateOf()['rounds']);
    }

    #[Test]
    public function aMoveThroughTicketCommandsWithNoChoiceKeepsTheDraftPrivate(): void
    {
        $this->addOmarsScreenshots(2);
        $before = $this->stateOf();
        $ticketCommands = self::getContainer()->get('test.ticketing_ticket_commands');
        self::assertInstanceOf(TicketCommands::class, $ticketCommands);
        $omar = $this->people['Omar']->id();
        $organizationId = $this->acmeFoods;

        $this->tenantTransactions()->committedAs(
            $organizationId->toString(),
            static fn (): MoveResult => $ticketCommands->move($organizationId, $omar, self::KEY, 'start_work', self::VERSION, new \DateTimeImmutable(self::NOW)),
        );

        $after = $this->stateOf();
        self::assertSame($this->steps['progress'], $after['ticket']['step_id']);
        self::assertSame(
            [...$before['rounds'][$this->omarsDraft], 'version' => 2, 'decision' => 'kept_private', 'decided_at' => self::NOW_AS_STORED],
            $after['rounds'][$this->omarsDraft],
        );
        self::assertSame(array_diff_key($before['rounds'], [$this->omarsDraft => true]), array_diff_key($after['rounds'], [$this->omarsDraft => true]));
    }

    /**
     * Lena's "Move to another step…" asks about her own draft (one screenshot) as a verb move does.
     */
    #[Test]
    #[DataProvider('formsWithNoChoice')]
    public function aMoveToWithNoChoiceAsksAboutTheMoversDraftAndMovesNothing(?string $choice): void
    {
        $this->addOmarsScreenshots(2);
        $before = $this->stateOf();
        $this->logIn($this->client, $this->people['Lena']);

        $this->client->request('POST', $this->moveToPath('progress'), $this->moveFields(null === $choice ? [] : [TicketMoveController::DRAFT_CHOICE_FIELD => $choice]));

        self::assertResponseStatusCodeSame(409);
        $this->assertTheQuestionPage(1, 2, 'In progress', $this->moveToPath('progress'));
        self::assertSame($before, $this->stateOf());
        self::assertSame($this->steps['todo'], $before['ticket']['step_id']);
        self::assertNull($before['rounds'][$this->lenasDraft]['decision']);
    }

    #[Test]
    public function shareOnAMoveToMovesTheTicketThenSharesOnlyTheMoversDraft(): void
    {
        $this->addOmarsScreenshots(2);
        $before = $this->stateOf();
        $this->logIn($this->client, $this->people['Lena']);

        $this->client->request('POST', $this->moveToPath('progress'), $this->moveFields([TicketMoveController::DRAFT_CHOICE_FIELD => 'share']));

        self::assertSame('Moved to In progress. Your draft is now round 2 for the team.', $this->toastAfterTheRedirect());
        $after = $this->stateOf();
        self::assertSame([...$before['ticket'], 'step_id' => $this->steps['progress'], 'version' => self::VERSION + 1, 'events' => $before['ticket']['events'] + 1], $after['ticket']);
        self::assertSame(
            [...$before['rounds'][$this->lenasDraft], 'number' => 2, 'shared_at' => self::NOW_AS_STORED, 'version' => 3, 'decision' => 'shared', 'decided_at' => self::NOW_AS_STORED],
            $after['rounds'][$this->lenasDraft],
        );
        self::assertSame(array_diff_key($before['rounds'], [$this->lenasDraft => true]), array_diff_key($after['rounds'], [$this->lenasDraft => true]), 'Omar\'s draft and Mia\'s round stay as they were.');
    }

    #[Test]
    public function keepOnAMoveToMovesTheTicketAndKeepsOnlyTheMoversDraftPrivate(): void
    {
        $this->addOmarsScreenshots(2);
        $before = $this->stateOf();
        $this->logIn($this->client, $this->people['Lena']);

        $this->client->request('POST', $this->moveToPath('progress'), $this->moveFields([TicketMoveController::DRAFT_CHOICE_FIELD => 'keep']));

        self::assertSame('Moved to In progress. Your draft stays private.', $this->toastAfterTheRedirect());
        $after = $this->stateOf();
        self::assertSame([...$before['ticket'], 'step_id' => $this->steps['progress'], 'version' => self::VERSION + 1, 'events' => $before['ticket']['events'] + 1], $after['ticket']);
        self::assertSame(
            [...$before['rounds'][$this->lenasDraft], 'version' => 2, 'decision' => 'kept_private', 'decided_at' => self::NOW_AS_STORED],
            $after['rounds'][$this->lenasDraft],
        );
        self::assertNull($after['rounds'][$this->lenasDraft]['shared_at']);
        self::assertSame(array_diff_key($before['rounds'], [$this->lenasDraft => true]), array_diff_key($after['rounds'], [$this->lenasDraft => true]));
    }

    /**
     * @return iterable<string, array{string, string, array<string, string>, string}> who posts, the target step's core
     *                                                                                code, the fields over the form's,
     *                                                                                and the toast
     */
    public static function refusedMoveTosWithAChoice(): iterable
    {
        foreach (['share', 'keep', null] as $choice) {
            $choiceFields = null === $choice ? [] : [TicketMoveController::DRAFT_CHOICE_FIELD => $choice];
            $label = $choice ?? 'no choice';
            yield 'the step it is in, with '.$label => ['Lena', 'todo', $choiceFields, MoveToasts::MOVE_REFUSED];
            yield 'a stale version, with '.$label => ['Lena', 'progress', [TicketMoveController::VERSION_FIELD => '2', ...$choiceFields], MoveToasts::CHANGED_MEANWHILE];
            yield 'Omar, the assignee, who may not move to a chosen step, with '.$label => ['Omar', 'progress', $choiceFields, MoveToasts::MOVE_REFUSED];
        }
    }

    /**
     * Only a move to another step asks, after the move's own checks; a choice never shares or decides without the move.
     *
     * @param array<string, string> $fields
     */
    #[Test]
    #[DataProvider('refusedMoveTosWithAChoice')]
    public function aRefusedMoveToSaysWhyAndNeitherMovesNorSharesNorDecides(string $person, string $step, array $fields, string $expectedToast): void
    {
        $this->addOmarsScreenshots(2);
        $before = $this->stateOf();
        $this->logIn($this->client, $this->people[$person]);

        $this->client->request('POST', $this->moveToPath($step), $this->moveFields($fields));

        self::assertSame($expectedToast, $this->toastAfterTheRedirect());
        self::assertSame($before, $this->stateOf());
        self::assertNull($before['rounds'][$this->lenasDraft]['decision']);
        self::assertNull($before['rounds'][$this->omarsDraft]['decision']);
    }

    /**
     * A give changes who has the ticket, not who sees the draft, so it asks nothing (P05.T9 step 1, ADR-0017).
     */
    #[Test]
    public function aGiveAsksNothingAndLeavesTheGiversDraftUndecided(): void
    {
        $before = $this->stateOf();
        $this->logIn($this->client, $this->people['Lena']);

        $this->client->request('POST', '/t/SHOP-1/assign', $this->moveFields([TicketMoveController::ASSIGNEE_FIELD => $this->people['Mia']->id()->toString()]));

        self::assertSame('Given to Mia.', $this->toastAfterTheRedirect());
        $after = $this->stateOf();
        self::assertSame(self::VERSION + 1, $after['ticket']['version']);
        self::assertSame($this->steps['todo'], $after['ticket']['step_id']);
        self::assertSame($before['rounds'], $after['rounds']);
        self::assertNull($after['rounds'][$this->lenasDraft]['decision']);
    }

    #[Test]
    public function aMoveToThroughTicketCommandsWithNoChoiceKeepsTheDraftPrivate(): void
    {
        $before = $this->stateOf();
        $ticketCommands = self::getContainer()->get('test.ticketing_ticket_commands');
        self::assertInstanceOf(TicketCommands::class, $ticketCommands);
        $lena = $this->people['Lena']->id();
        $organizationId = $this->acmeFoods;
        $progress = $this->steps['progress'];

        $this->tenantTransactions()->committedAs(
            $organizationId->toString(),
            static fn (): MoveResult => $ticketCommands->moveTo($organizationId, $lena, self::KEY, $progress, self::VERSION, null, null, new \DateTimeImmutable(self::NOW)),
        );

        $after = $this->stateOf();
        self::assertSame($this->steps['progress'], $after['ticket']['step_id']);
        self::assertSame(
            [...$before['rounds'][$this->lenasDraft], 'version' => 2, 'decision' => 'kept_private', 'decided_at' => self::NOW_AS_STORED],
            $after['rounds'][$this->lenasDraft],
        );
        self::assertSame(array_diff_key($before['rounds'], [$this->lenasDraft => true]), array_diff_key($after['rounds'], [$this->lenasDraft => true]));
    }

    /**
     * @return iterable<string, array{string, string|null}> the field the form sends, and the person it names, if any
     */
    public static function waitingOnFields(): iterable
    {
        yield 'a label' => [TicketMoveController::WAITING_ON_LABEL_FIELD, null];
        yield 'a person' => [TicketMoveController::WAITING_ON_USER_FIELD, 'Raj'];
    }

    #[Test]
    #[DataProvider('waitingOnFields')]
    public function aMoveToANeedsInputStepRepeatsWhoItWaitsOnAndKeepsItWhenSentAgainWithAChoice(string $field, ?string $person): void
    {
        $value = null === $person ? 'Customer' : $this->people[$person]->id()->toString();
        $this->logIn($this->client, $this->people['Lena']);
        $this->client->request('POST', $this->moveToPath('input'), $this->moveFields([$field => $value]));

        self::assertResponseStatusCodeSame(409);
        $page = $this->client->getCrawler();
        $sent = $page->filter('input[type=hidden][name="'.$field.'"]')->extract(['value']);
        self::assertSame([$value], $sent);
        $other = TicketMoveController::WAITING_ON_LABEL_FIELD === $field ? TicketMoveController::WAITING_ON_USER_FIELD : TicketMoveController::WAITING_ON_LABEL_FIELD;
        self::assertSame([''], $page->filter('input[type=hidden][name="'.$other.'"]')->extract(['value']));
        self::assertSame(['user' => null, 'label' => null], $this->waitingOn());

        $this->client->request('POST', $this->moveToPath('input'), $this->moveFields([$field => $value, TicketMoveController::DRAFT_CHOICE_FIELD => 'keep']));

        self::assertResponseStatusCodeSame(303);
        self::assertSame(
            ['user' => null === $person ? null : $value, 'label' => null === $person ? $value : null],
            $this->waitingOn(),
        );
    }

    /**
     * TicketMoveController passes no step name when the verb is not among the box's transitions; the HTTP path to that
     * is closed (the policy refuses such a verb first), so the answer is rendered as the controller would.
     */
    #[Test]
    public function theShareButtonNamesNoStepWhenTheMoveLeadsToNoNamedStep(): void
    {
        $content = self::getContainer()->get('twig')->render('@Ticketing/draft_question.stream.html.twig', [
            'ticketKey' => self::KEY,
            'moveUrl' => self::START_WORK,
            'draft' => new UndecidedDraft($this->omarsDraft, 2, 2),
            'stepName' => null,
            'shareBlockedReason' => null,
            'fields' => [],
            'choiceField' => TicketMoveController::DRAFT_CHOICE_FIELD,
            'failedToast' => MoveToasts::MOVE_FAILED,
        ]);

        self::assertMatchesRegularExpression('/value="share"[^>]*>\s*Share and move\s*<\/button>/', $content);
        self::assertStringNotContainsString('Share and move to', $content);
    }

    /**
     * @return iterable<string, array{string, string, string}> which round stays open, whose turn the turn row names, and
     *                                                         the reason that names who gives its verdict
     */
    public static function roundsStillOpen(): iterable
    {
        yield 'Mia\'s findings round 1 waits for Omar, the assignee, though it is Mia\'s turn' => ['findings', 'Mia', 'Round 1 is still open. Omar gives a verdict first.'];
        yield 'Omar\'s self-check round 2 waits for Mia, whose turn it is' => ['self-check', 'Mia', 'Round 2 is still open. Mia gives a verdict first.'];
        yield 'Omar\'s self-check round 2 waits for Lena herself, whose turn it is' => ['self-check', 'Lena', 'Round 2 is still open. Give your verdict first.'];
    }

    /**
     * ADR-0048, P14.T5: while another shared round stays open, Lena's move to a chosen step (lead-only, so it closes no
     * round) asks about her draft with only "Keep private and move" and says who gives the verdict.
     */
    #[Test]
    #[DataProvider('roundsStillOpen')]
    public function whileAnotherRoundIsOpenTheQuestionOffersOnlyKeepPrivateAndNamesWhoGivesTheVerdict(string $openRound, string $turnPerson, string $expectedReason): void
    {
        $this->keepARoundOpen($openRound, $turnPerson);
        $before = $this->stateOf();
        $this->logIn($this->client, $this->people['Lena']);

        foreach ([[], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html']] as $headers) {
            $this->client->request('POST', $this->moveToPath('progress'), $this->moveFields(), [], $headers);

            self::assertResponseStatusCodeSame(409);
            $content = html_entity_decode((string) $this->client->getResponse()->getContent(), \ENT_QUOTES | \ENT_HTML5);
            self::assertStringContainsString('<p>'.$expectedReason.'</p>', $content);
            self::assertStringContainsString('Your draft round has 1 screenshot. Keep it private and move; you can share it later.', $content);
            self::assertSame(1, substr_count($content, 'name="draft_choice" value="keep"'));
            self::assertSame(0, substr_count($content, 'value="share"'), 'No button shares while the round is open.');
            self::assertSame(0, substr_count($content, 'Share and move'));
            self::assertSame($before, $this->stateOf());
        }
    }

    /**
     * The dialog offers no share then, so a share sent anyway is a forged form: the move and the share roll back, and
     * the toast names who gives the verdict, read after the rollback.
     *
     * @return iterable<string, array{string, string, string, string}> which round stays open, whose turn it is, the move,
     *                                                                 and the reason
     */
    public static function sharesSentWhileARoundIsOpen(): iterable
    {
        foreach (self::roundsStillOpen() as $label => [$openRound, $turnPerson, $expectedReason]) {
            yield $label.', on a move to a chosen step' => [$openRound, $turnPerson, 'move-to', $expectedReason];
            yield $label.', on the lead\'s Put on hold' => [$openRound, $turnPerson, '/t/SHOP-1/move/put_on_hold', $expectedReason];
        }
    }

    #[Test]
    #[DataProvider('sharesSentWhileARoundIsOpen')]
    public function aShareSentWhileAnotherRoundIsOpenIsRefusedWithWhoGivesTheVerdictAndChangesNothing(string $openRound, string $turnPerson, string $path, string $expectedReason): void
    {
        $this->keepARoundOpen($openRound, $turnPerson);
        $before = $this->stateOf();
        self::assertNull($before['rounds'][$this->lenasDraft]['number']);
        $this->logIn($this->client, $this->people['Lena']);

        $this->client->request(
            'POST',
            'move-to' === $path ? $this->moveToPath('progress') : $path,
            $this->moveFields([TicketMoveController::DRAFT_CHOICE_FIELD => 'share']),
            [],
            ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html'],
        );

        self::assertResponseStatusCodeSame(422);
        $matched = preg_match('/data-toast-message-value="([^"]*)"/', (string) $this->client->getResponse()->getContent(), $toast);
        self::assertSame(1, $matched);
        self::assertSame($expectedReason, html_entity_decode($toast[1], \ENT_QUOTES | \ENT_HTML5));
        self::assertSame($before, $this->stateOf(), 'The ticket did not move, and Lena\'s draft stays private, undecided and unnumbered.');
    }

    /**
     * It is $turnPerson's turn on SHOP-1 in the turn read model; with 'findings' Mia's round 1 opens again, which is
     * findings for Omar, the assignee; with 'self-check' Omar's own shared round 2 is open.
     */
    private function keepARoundOpen(string $openRound, string $turnPerson): void
    {
        $turnUserId = $this->people[$turnPerson]->id()->toString();
        $ticketId = $this->ticketId;
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO ins_ticket_turn (organization_id, ticket_id, team_id, project_id, grp, turn_user_id, turn_label, kind, base_score, waiting_since, visibility, reporter_id, assignee_id, tester_id)
             SELECT organization_id, id, team_id, project_id, 'todo', ?, NULL, 'build', 10, '2026-11-06 11:00:00+00', visibility, reporter_id, assignee_id, tester_id FROM tkt_ticket WHERE id = ?",
            [$turnUserId, $ticketId],
        ));
        if ('findings' === $openRound) {
            $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
                "UPDATE rvw_round SET state = 'open' WHERE ticket_id = ? AND number = 1",
                [$ticketId],
            ));

            return;
        }
        $omarsRound = $this->addRound($this->ticketId, 2, 'draft', 'Omar', '2026-11-05 10:00:00+00');
        $this->addScreenshot($omarsRound, $this->addMedia('ready', 'Omar'), 0, 'Self-check shot', null);
        $this->seededRoundCount = 4;
    }

    /**
     * @return array{user: mixed, label: mixed} who SHOP-1 waits on
     */
    private function waitingOn(): array
    {
        $organizationId = $this->acmeFoods->toString();
        $row = [];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId, &$row): int {
            $row = $migrator->fetchAssociative('SELECT waiting_on_user_id AS user, waiting_on_label AS label FROM tkt_ticket WHERE organization_id = ? AND key = ?', [$organizationId, self::KEY]);

            return 0;
        });
        self::assertIsArray($row);

        return ['user' => $row['user'], 'label' => $row['label']];
    }

    #[Test]
    public function aTurboFormGetsTheDialogAsATurboStreamThatOpensItAndMovesNothing(): void
    {
        $this->addOmarsScreenshots(2);
        $before = $this->stateOf();
        $this->logIn($this->client, $this->people['Omar']);

        $this->client->request('POST', self::START_WORK, $this->moveFields(), [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html']);

        self::assertResponseStatusCodeSame(409);
        self::assertStringStartsWith('text/vnd.turbo-stream.html', (string) $this->client->getResponse()->headers->get('Content-Type'));
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<turbo-stream action="append" targets="body">', $content);
        self::assertStringContainsString('data-controller="share-draft"', $content);
        self::assertStringContainsString('<dialog id="share-draft-dialog"', $content);
        self::assertStringContainsString('data-controller="dialog" data-dialog-open-on-connect-value="true"', $content, 'The dialog opens itself; no button on the page opens it.');
        $this->assertTheQuestion($content, 2, 2, 'In progress', self::START_WORK);
        self::assertSame($before, $this->stateOf());
    }

    #[Test]
    public function aFormWithoutScriptGetsAPageWithTheQuestionAndNoDialogToOpen(): void
    {
        $this->addOmarsScreenshots(1);
        $before = $this->stateOf();
        $this->logIn($this->client, $this->people['Omar']);

        $this->client->request('POST', self::START_WORK, $this->moveFields());

        self::assertResponseStatusCodeSame(409);
        self::assertStringStartsWith('text/html', (string) $this->client->getResponse()->headers->get('Content-Type'));
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<h1 class="draft-question__title">Share your draft round?</h1>', $content);
        self::assertStringNotContainsString('<dialog', $content);
        self::assertStringContainsString('href="'.self::TICKET_PAGE.'"', $content);
        $this->assertTheQuestion($content, 1, 2, 'In progress', self::START_WORK);
        self::assertSame($before, $this->stateOf());
    }

    private function assertTheQuestionPage(int $screenshotCount, int $roundNumber, string $stepName, string $moveUrl): void
    {
        self::assertStringStartsWith('text/html', (string) $this->client->getResponse()->headers->get('Content-Type'));
        $this->assertTheQuestion((string) $this->client->getResponse()->getContent(), $screenshotCount, $roundNumber, $stepName, $moveUrl);
    }

    /**
     * The text, the buttons and the form's own fields that send the move again with a choice.
     */
    private function assertTheQuestion(string $content, int $screenshotCount, int $roundNumber, string $stepName, string $moveUrl): void
    {
        $noun = 1 === $screenshotCount ? 'screenshot' : 'screenshots';
        self::assertStringContainsString(\sprintf('Your draft round has %d %s. Share it with the team as round %d, or keep it private. Private notes stay readable for you and Claude Code.', $screenshotCount, $noun, $roundNumber), $content);
        self::assertStringContainsString('Keep private and move', $content);
        self::assertStringContainsString('Share and move to '.$stepName, $content);
        self::assertStringContainsString('name="draft_choice" value="keep"', $content);
        self::assertStringContainsString('name="draft_choice" value="share"', $content);
        self::assertStringContainsString('action="'.$moveUrl.'"', $content);
        self::assertStringContainsString('name="version" value="'.self::VERSION.'"', $content);
        self::assertStringContainsString('name="_csrf_token"', $content);
    }

    private function moveToPath(string $coreCode): string
    {
        return '/t/SHOP-1/move-to/'.$this->steps[$coreCode];
    }

    /**
     * Omar's Send to code review, in his turn, decides no draft; it may close the round he shared at the first move
     * (ADR-0048), which the caller's expected rounds say.
     *
     * @param array<string, array<string, mixed>> $expectedRounds the rounds after the second move, by id
     */
    private function assertASecondMoveAsksNothing(array $expectedRounds): void
    {
        $this->client->request('POST', '/t/SHOP-1/move/send_to_code_review', $this->moveFields([TicketMoveController::VERSION_FIELD => (string) (self::VERSION + 1)]));

        self::assertSame('Sent to code review. Lena’s turn now.', $this->toastAfterTheRedirect());
        $after = $this->stateOf();
        self::assertSame($this->steps['review'], $after['ticket']['step_id']);
        self::assertSame($expectedRounds, $after['rounds']);
    }

    /**
     * Mia becomes Shop's tester and SHOP-1's, the ticket goes to Testing (its version stays), and Mia starts an
     * undecided draft with one ready screenshot.
     *
     * @return string the id of Mia's draft
     */
    private function putShop1InTestingWithAMiasDraftOfOneScreenshot(): string
    {
        $miaId = $this->people['Mia']->id()->toString();
        $shopId = $this->shop->toString();
        $testingStepId = $this->steps['test'];
        $ticketId = $this->ticketId;
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($miaId, $shopId, $testingStepId, $ticketId): int {
            $migrator->executeStatement("UPDATE org_team_member SET job = 'tester' WHERE team_id = ? AND user_id = ?", [$shopId, $miaId]);
            $migrator->executeStatement('UPDATE tkt_ticket SET step_id = ?, tester_id = ? WHERE id = ?', [$testingStepId, $miaId, $ticketId]);

            return 0;
        });
        $miasDraft = $this->addRound($this->ticketId, null, 'draft', 'Mia', null);
        $this->addScreenshot($miasDraft, $this->addMedia('ready', 'Mia'), 0, null, null);
        $this->seededRoundCount = 4;

        return $miasDraft;
    }

    private function addOmarsScreenshots(int $count): void
    {
        for ($position = 0; $position < $count; ++$position) {
            $this->addScreenshot($this->omarsDraft, $this->addMedia('ready', 'Omar'), $position, null, null);
        }
    }

    /**
     * Review's answer as the real one gives it, which then refuses as Review would on a draft changed meanwhile: the
     * share or keep is written before the refusal, so only the rollback can undo it.
     *
     * @return \ArrayObject<string, int|bool> what the real share or keep returned or did before the refusal
     */
    private function answerTheDraftQuestionThenRefuse(): \ArrayObject
    {
        $realQuestion = new RoundShareDraftQuestion(self::getContainer()->get(Rounds::class), self::getContainer()->get(RoundCommands::class), self::getContainer()->get(LockedRoundClosing::class));
        /** @var \ArrayObject<string, int|bool> $written what the real share or keep returned or did, before the refusal */
        $written = new \ArrayObject();
        self::getContainer()->set(RoundShareDraftQuestion::class, new readonly class($realQuestion, $written) implements ShareDraftQuestion {
            /**
             * @param \ArrayObject<string, int|bool> $written
             */
            public function __construct(private ShareDraftQuestion $realQuestion, private \ArrayObject $written)
            {
            }

            public function undecidedDraftOf(TicketId $ticketId, MoveFacts $move): ?UndecidedDraft
            {
                return $this->realQuestion->undecidedDraftOf($ticketId, $move);
            }

            public function closeRoundsOnMove(TicketId $ticketId, MoveFacts $move, \DateTimeImmutable $at): void
            {
                $this->realQuestion->closeRoundsOnMove($ticketId, $move, $at);
            }

            public function undecidedDraftIdOf(TicketId $ticketId, UserId $ownerId): ?string
            {
                return $this->realQuestion->undecidedDraftIdOf($ticketId, $ownerId);
            }

            public function share(string $roundId, UserId $ownerId, \DateTimeImmutable $at): int
            {
                $this->written['roundNumber'] = $this->realQuestion->share($roundId, $ownerId, $at);

                throw new TicketChangedMeanwhile('The draft round was changed after it was read.');
            }

            public function keepPrivate(string $roundId, UserId $ownerId, \DateTimeImmutable $at): void
            {
                $this->realQuestion->keepPrivate($roundId, $ownerId, $at);
                $this->written['kept'] = true;

                throw new TicketChangedMeanwhile('The draft round was changed after it was read.');
            }
        });

        return $written;
    }

    /**
     * An open ping from Lena to Omar on SHOP-1, with its bell row, which Omar's move clears.
     *
     * @return string the ping's id
     */
    private function addPingToOmar(): string
    {
        $organizationId = $this->acmeFoods->toString();
        $pingId = Uuid::v7()->toRfc4122();
        $ping = [
            'organization_id' => $organizationId,
            'id' => $pingId,
            'ticket_id' => $this->ticketId,
            'from_id' => $this->people['Lena']->id()->toString(),
            'to_id' => $this->people['Omar']->id()->toString(),
            'body' => 'Any news on this one?',
            'created_at' => '2026-11-06 11:00:00+00',
        ];
        $notification = [
            'organization_id' => $organizationId,
            'id' => Uuid::v7()->toRfc4122(),
            'user_id' => $this->people['Omar']->id()->toString(),
            'kind' => 'ping',
            'actor_id' => $this->people['Lena']->id()->toString(),
            'ticket_id' => $this->ticketId,
            'data' => json_encode(['actorName' => 'Lena', 'ticketKey' => self::KEY, 'pingId' => $pingId], \JSON_THROW_ON_ERROR),
            'created_at' => '2026-11-06 11:00:00+00',
        ];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($ping, $notification): int|string {
            $migrator->insert('ntf_ping', $ping);

            return $migrator->insert('ntf_notification', $notification);
        });

        return $pingId;
    }

    /**
     * @return array{cleared_reason: mixed, done: mixed}
     */
    private function stateOfPing(string $pingId): array
    {
        $organizationId = $this->acmeFoods->toString();
        $row = [];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId, $pingId, &$row): int {
            $row = $migrator->fetchAssociative(
                "SELECT p.cleared_reason, n.done_at IS NOT NULL AS done FROM ntf_ping p JOIN ntf_notification n ON n.organization_id = p.organization_id AND n.data->>'pingId' = p.id::text
                 WHERE p.organization_id = ? AND p.id = ?",
                [$organizationId, $pingId],
            );

            return 0;
        });
        self::assertIsArray($row);

        return ['cleared_reason' => $row['cleared_reason'], 'done' => $row['done']];
    }

    private function removeLenasScreenshots(): void
    {
        $lenasDraft = $this->lenasDraft;
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement('DELETE FROM rvw_screenshot WHERE round_id = ?', [$lenasDraft]));
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private function moveFields(array $overrides = []): array
    {
        return [
            TicketMoveController::CSRF_FIELD => $this->csrfTokenFor($this->client, TicketMoveController::csrfIdOf(self::KEY)),
            TicketMoveController::VERSION_FIELD => (string) self::VERSION,
            ...$overrides,
        ];
    }

    /**
     * The Rounds section's frame as its lazy load asks for it.
     */
    private function roundsFrame(): Crawler
    {
        $frame = $this->client->request('GET', self::TICKET_PAGE.'/rounds', [], [], ['HTTP_TURBO_FRAME' => RoundsSection::FRAME_ID]);
        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $frame->filter('turbo-frame#'.RoundsSection::FRAME_ID));

        return $frame;
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

    /**
     * SHOP-1's step, version and history count, and its rounds as stored by id.
     *
     * @return array{ticket: array{step_id: mixed, version: mixed, events: int}, rounds: array<string, array<string, mixed>>}
     */
    private function stateOf(): array
    {
        $organizationId = $this->acmeFoods->toString();
        $ticket = [];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId, &$ticket): int {
            $ticket = $migrator->fetchAssociative(
                'SELECT step_id, version, (SELECT count(*) FROM tkt_ticket_event WHERE organization_id = ?) AS events FROM tkt_ticket WHERE organization_id = ? AND key = ?',
                [$organizationId, $organizationId, self::KEY],
            );

            return 0;
        });
        self::assertIsArray($ticket);
        self::assertIsInt($ticket['events']);
        $rounds = [];
        foreach ($this->roundRowsOf($this->ticketId) as $round) {
            self::assertIsString($round['id']);
            $rounds[$round['id']] = $round;
        }
        self::assertCount($this->seededRoundCount, $rounds);

        return ['ticket' => ['step_id' => $ticket['step_id'], 'version' => $ticket['version'], 'events' => $ticket['events']], 'rounds' => $rounds];
    }

    /**
     * The core steps, each placed in its group as Workflow orders them.
     */
    private function addSteps(): void
    {
        $organizationId = $this->acmeFoods->toString();
        $steps = [];
        foreach ([
            ['backlog', 'Backlog', 'todo', 0], ['todo', 'To do', 'todo', 1], ['progress', 'In progress', 'doing', 0],
            ['review', 'Code review', 'check', 0], ['test', 'Testing', 'check', 1], ['input', 'Needs input', 'wait', 0],
            ['hold', 'On hold', 'wait', 1], ['done', 'Done', 'done', 0],
        ] as [$code, $name, $groupCode, $position]) {
            $this->steps[$code] = Uuid::v7()->toRfc4122();
            $steps[] = [$this->steps[$code], $name, $groupCode, $code, $position];
        }
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId, $steps): int {
            foreach ($steps as $step) {
                $migrator->executeStatement(
                    "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, ?, ?, ?, 'core', ?)",
                    [$organizationId, ...$step],
                );
            }

            return 0;
        });
    }

    private function tenantTransactions(): TenantTransactions
    {
        return new TenantTransactions(self::getContainer()->get('doctrine.orm.default_entity_manager'), self::getContainer()->get(TenantContext::class));
    }
}
