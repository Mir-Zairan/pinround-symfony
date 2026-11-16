<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ticketing;

use App\Identity\Domain\User;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Review\SeedsTicketRounds;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use App\Tests\Functional\Web\IssuesCsrfTokens;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Ticketing\Api\MoveResult;
use App\Ticketing\Api\TicketCommands;
use App\Ticketing\Infrastructure\Scheduler\AddTicketEventPartitions;
use App\Ticketing\UI\MoveToasts;
use App\Ticketing\UI\TicketMoveController;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * "Share your draft round?" on a verb move and a move to a chosen step of Acme Foods' SHOP-1 (P05.T9, ADR-0017, hand-off), through the real
 * firewall, voters, policy and database. Omar is the assignee and owns the draft with two screenshots; Lena leads Shop
 * and has a draft of her own, Mia is a member, Raj a manager and Sara an admin of no team. Mia's round 1 is shared, so
 * Omar's draft would be round 2. The clock is fixed on 6 Nov 2026, before today: a clock ahead of the login would end
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
    private const string TICKET_PAGE = '/t/SHOP-1';
    /** The version SHOP-1 is seeded at (SeedsTicketRounds). */
    private const int VERSION = 3;

    private KernelBrowser $client;

    /** @var array<string, User> */
    private array $people;

    private string $shopProject;
    /** To do, where SHOP-1 starts. */
    private string $stepId;

    /** @var array<string, string> step id by core code */
    private array $steps = [];

    private string $ticketId;
    private string $omarsDraft;
    private string $lenasDraft;

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

        $this->assertASecondMoveAsksNothing($after);
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

        $this->assertASecondMoveAsksNothing($after);
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
        self::assertStringContainsString('<h1 class="profile__title">Share your draft round?</h1>', $content);
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
     * @param array{ticket: array<string, mixed>, rounds: array<string, array<string, mixed>>} $state the state after the first move
     */
    private function assertASecondMoveAsksNothing(array $state): void
    {
        $this->client->request('POST', '/t/SHOP-1/move/send_to_code_review', $this->moveFields([TicketMoveController::VERSION_FIELD => (string) (self::VERSION + 1)]));

        self::assertSame('Sent to code review. Lena’s turn now.', $this->toastAfterTheRedirect());
        $after = $this->stateOf();
        self::assertSame($this->steps['review'], $after['ticket']['step_id']);
        self::assertSame($state['rounds'], $after['rounds']);
    }

    private function addOmarsScreenshots(int $count): void
    {
        for ($position = 0; $position < $count; ++$position) {
            $this->addScreenshot($this->omarsDraft, $this->addMedia('ready', 'Omar'), $position, null, null);
        }
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
        self::assertCount(3, $rounds);

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
