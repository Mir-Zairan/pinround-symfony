<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ticketing;

use App\Identity\Domain\User;
use App\Notification\Infrastructure\Mercure\RefreshTicketPagesHandler;
use App\Review\UI\Component\RoundsSection;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use App\Tests\Functional\Web\IssuesCsrfTokens;
use App\Tests\Integration\ReadsSentSql;
use App\Ticketing\Api\DraftChoice;
use App\Ticketing\Api\MoveResult;
use App\Ticketing\Api\TicketCommands;
use App\Ticketing\Application\MoveTicket\MoveTicket;
use App\Ticketing\Application\UseCaseTicketCommands;
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
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\Mercure\Debug\TraceableHub;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * The ticket page's moves, POST /t/{key}/move/{verb}, /t/{key}/move-to/{stepId} and /t/{key}/assign, on Acme Foods' SHOP-1 through the
 * real firewall, voters, session, policy and database. The clock is fixed on 6 Nov 2026, before today: a clock ahead of
 * the login would end the session as idle. Every refusal also proves the ticket, its history and the outbox unchanged.
 */
final class TicketMoveTest extends WebTestCase
{
    use CreatesAcmeFoodsTeams;
    use CreatesTeams;
    use Factories;
    use IssuesCsrfTokens;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }
    use ReadsSentSql;

    private const string NOW = '2026-11-06 12:00:00 UTC';
    private const string KEY = 'SHOP-1';
    private const string TICKET_PAGE = '/t/SHOP-1';
    private const string ASSIGN_PATH = '/t/SHOP-1/assign';
    /** The version SHOP-1 is seeded at, so a stale form can send a lower one. */
    private const int VERSION = 3;
    private const string NOBODY_ID = '0197a3c0-0000-7000-8000-00000000dead';
    private const string REVIEW_BEFORE_TEST_NOTE = 'Team rule: code review comes before testing.';
    /** What Turbo sends with a form it posts. */
    private const array TURBO_FORM_HEADERS = ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml'];
    /** base.html.twig's toast region, which a streamed toast updates. */
    private const string TOAST_REGION_ID = 'toast-region';
    /**
     * The stream answer's re-read of the ticket page, warm, by the first table each statement reads. People's Ping
     * buttons add none: the ping limit comes with the settings the re-read already read, the pinged state from Valkey.
     */
    private const array RE_READ_STATEMENTS = ['"START TRANSACTION"', self::TENANT_STATEMENT, 'tkt_ticket', 'idn_user', 'tkt_ticket_event', 'ins_ticket_turn', '"COMMIT"'];
    /** A lead's re-read: their box offers the Needs input dialog, so it also reads which of the ticket's people are members. */
    private const array LEAD_RE_READ_STATEMENTS = ['"START TRANSACTION"', self::TENANT_STATEMENT, 'tkt_ticket', 'idn_user', 'tkt_ticket_event', 'ins_ticket_turn', 'org_membership', '"COMMIT"'];
    /**
     * The tenant middleware's transaction-local setting, matched rather than written out: bin/check-tenant-setting
     * allows the literal call only in the files that set it.
     */
    private const string TENANT_STATEMENT_PATTERN = '/^SELECT set_config\(\'app\.org_id\', \?, true\)\z/';
    private const string TENANT_STATEMENT = 'the tenant setting, transaction-local';

    private KernelBrowser $client;

    /** @var array<string, User> */
    private array $people;

    private string $shopProject;

    /** @var array<string, string> step id by core code, and 'pairing' for Shop's own step */
    private array $steps = [];

    /** The request's clock, which an Undo test moves on. */
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTPS' => 'on']);
        $this->client->disableReboot();
        $this->clock = new MockClock(new \DateTimeImmutable(self::NOW));
        self::getContainer()->set('clock', $this->clock);
        new AddTicketEventPartitions($this->migrator(), $this->clock)();
        $this->people = $this->createAcmeFoodsPeople();
        $this->committedAs($this->acmeFoods, fn (Connection $migrator): int|string => $migrator->executeStatement(
            "UPDATE org_team_member SET job = 'developer' WHERE team_id = ? AND user_id = ?",
            [$this->shop->toString(), $this->people['Omar']->id()->toString()],
        ));
        $this->shopProject = $this->addProject($this->shop, 'SHOP', 'Shop');
        $this->addSteps();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->committedAs(new OrganizationId($organizationId), static function (Connection $migrator) use ($organizationId): int {
                foreach (['tkt_ticket_event', 'ins_ticket_turn', 'ins_project_count_ticket', 'ins_project_counts', 'tkt_ticket', 'wf_step', 'wf_rule_set'] as $table) {
                    $migrator->executeStatement(\sprintf('DELETE FROM %s WHERE organization_id = ?', $table), [$organizationId]);
                }
                $migrator->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox' AND position(? in body) > 0", [$organizationId]);

                return 0;
            });
        }
        $this->removeCommittedProjects();
        $this->removeUsersAndOrganizations();
    }

    /**
     * Every verb the page offers, by the person whose turn it is, from a step the catalogue applies it in. Shop has no
     * tester, so its lead Lena tests; Omar is the assignee. Each row: who clicks, the ticket's columns, the verb, the step
     * after, the toast, and whether Acme Foods' review_before_test rule is on.
     *
     * @return iterable<string, array{0: string, 1: array{step: string, reviewer?: string, waitingOn?: string}, 2: string, 3: string, 4: string, 5?: bool}>
     */
    public static function verbMoves(): iterable
    {
        yield 'plan it, by the lead' => ['Lena', ['step' => 'backlog'], 'plan_it', 'todo', 'Planned. It is in To do now.'];
        yield 'start work, by the assignee' => ['Omar', ['step' => 'todo'], 'start_work', 'progress', 'Started. Status is In progress.'];
        yield 'send to code review, by the assignee' => ['Omar', ['step' => 'progress'], 'send_to_code_review', 'review', 'Sent to code review. Lena’s turn now.'];
        yield 'send to testing, by the assignee, with review before testing off' => ['Omar', ['step' => 'progress'], 'send_to_testing', 'test', 'Sent to testing. Lena’s turn now.', false];
        yield 'approve code, by the reviewer' => ['Lena', ['step' => 'review', 'reviewer' => 'Lena'], 'approve_code', 'test', 'Code approved. Lena’s turn to test.'];
        yield 'ask for changes, by the reviewer' => ['Lena', ['step' => 'review', 'reviewer' => 'Lena'], 'ask_for_changes', 'progress', 'Sent back to Omar.'];
        yield 'pass it, by the lead who tests' => ['Lena', ['step' => 'test'], 'pass_it', 'done', 'Passed. The ticket is Done.'];
        yield 'needs changes, by the lead who tests' => ['Lena', ['step' => 'test'], 'needs_changes', 'progress', 'Sent back to Omar with your notes.'];
        yield 'input given, by the person waited on' => ['Omar', ['step' => 'input', 'waitingOn' => 'Omar'], 'input_given', 'todo', 'Back to work.'];
        yield 'go on, by the lead' => ['Lena', ['step' => 'hold'], 'go_on', 'progress', 'Back in progress.'];
        yield 'reopen, by the lead' => ['Lena', ['step' => 'done'], 'reopen', 'progress', 'Reopened. Back in progress.'];
        yield 'put on hold, by the lead' => ['Lena', ['step' => 'todo'], 'put_on_hold', 'hold', 'On hold. It waits on you.'];
        yield 'move on from a team step, by the assignee' => ['Omar', ['step' => 'pairing'], 'move_on', 'review', 'Moved to Code review.'];
    }

    /**
     * @param array{step: string, reviewer?: string, waitingOn?: string} $columns
     */
    #[Test]
    #[DataProvider('verbMoves')]
    public function aVerbMovesTheTicketAndSaysSoInAToast(string $actor, array $columns, string $verb, string $expectedStep, string $expectedToast, bool $isReviewBeforeTestOn = true): void
    {
        if (!$isReviewBeforeTestOn) {
            $this->switchReviewBeforeTestOff();
        }
        $this->addTicket($columns);
        $stateBefore = $this->stateOfTicket();
        $this->logIn($this->client, $this->people[$actor]);

        $this->client->request('POST', '/t/'.self::KEY.'/move/'.$verb, $this->moveFields());

        self::assertSame($expectedToast, $this->toastAfterTheRedirect());
        self::assertSame(
            [
                ...$stateBefore,
                'step_id' => $this->steps[$expectedStep],
                'version' => self::VERSION + 1,
                'waiting_on_user_id' => null,
                'waiting_on_label' => null,
                'events' => $stateBefore['events'] + 1,
                'outbox' => $stateBefore['outbox'] + 1,
            ],
            $this->stateOfTicket(),
        );
    }

    #[Test]
    public function theSameMoveSentTwiceMovesTheTicketOnceAndTheSecondSaysItChanged(): void
    {
        $this->addTicket(['step' => 'todo']);
        $stateBefore = $this->stateOfTicket();
        $this->logIn($this->client, $this->people['Omar']);
        $fields = $this->moveFields();

        $this->client->request('POST', '/t/'.self::KEY.'/move/start_work', $fields);
        self::assertSame('Started. Status is In progress.', $this->toastAfterTheRedirect());
        $this->client->request('POST', '/t/'.self::KEY.'/move/start_work', $fields);

        self::assertSame(MoveToasts::CHANGED_MEANWHILE, $this->toastAfterTheRedirect());
        self::assertSame(
            [...$stateBefore, 'step_id' => $this->steps['progress'], 'version' => self::VERSION + 1, 'events' => $stateBefore['events'] + 1, 'outbox' => $stateBefore['outbox'] + 1],
            $this->stateOfTicket(),
        );
    }

    /**
     * @return iterable<string, array{string, string}> the reviewer with a review already waiting, the one the toast names
     */
    public static function busyReviewers(): iterable
    {
        yield 'Omar is busy' => ['Omar', 'Pia'];
        yield 'Pia is busy' => ['Pia', 'Omar'];
    }

    /**
     * Lena, the lead, wrote it, so Omar or Pia reviews, whoever has fewer reviews waiting; swapping who is busy swaps
     * the name, so a toast that named anyone but the saved turn's person fails one of the two.
     */
    #[Test]
    #[DataProvider('busyReviewers')]
    public function theToastNamesTheReviewerTheMoveGaveTheTurnTo(string $busyReviewer, string $expectedReviewer): void
    {
        $this->people['Pia'] = $this->createUserWithoutOrganization('Pia');
        $this->addMembership($this->people['Pia'], $this->acmeFoods);
        $this->addTeamRole($this->people['Pia'], $this->acmeFoods, $this->shop, 'member');
        $this->committedAs($this->acmeFoods, fn (Connection $migrator): int|string => $migrator->executeStatement(
            "UPDATE org_team_member SET job = 'developer' WHERE team_id = ? AND user_id = ?",
            [$this->shop->toString(), $this->people['Pia']->id()->toString()],
        ));
        $this->addTicket(['step' => 'progress', 'assignee' => 'Lena']);
        $this->addTicket(['step' => 'review', 'number' => 2]);
        $this->addWaitingReview(2, $busyReviewer);
        $this->logIn($this->client, $this->people['Lena']);

        $this->client->request('POST', '/t/'.self::KEY.'/move/send_to_code_review', $this->moveFields());

        self::assertSame('Sent to code review. '.$expectedReviewer.'’s turn now.', $this->toastAfterTheRedirect());
        self::assertSame($this->steps['review'], $this->stateOfTicket()['step_id']);
    }

    #[Test]
    public function theLeadMovesTheTicketToAnotherStepAndTheToastNamesIt(): void
    {
        $this->addTicket(['step' => 'todo']);
        $stateBefore = $this->stateOfTicket();
        $this->logIn($this->client, $this->people['Lena']);

        $this->client->request('POST', $this->moveToPath('progress'), $this->moveFields());

        self::assertSame('Moved to In progress.', $this->toastAfterTheRedirect());
        self::assertSame(
            [...$stateBefore, 'step_id' => $this->steps['progress'], 'version' => self::VERSION + 1, 'events' => $stateBefore['events'] + 1, 'outbox' => $stateBefore['outbox'] + 1],
            $this->stateOfTicket(),
        );
    }

    #[Test]
    public function aMovePublishesNoLiveUpdateInTheRequestOnlyItsEventToTheOutbox(): void
    {
        $this->addTicket(['step' => 'todo']);
        $stateBefore = $this->stateOfTicket();
        $this->logIn($this->client, $this->people['Lena']);

        $this->client->request('POST', $this->moveToPath('progress'), $this->moveFields());

        // Read before the redirect is followed: the next request resets the hub's trace.
        self::assertResponseStatusCodeSame(303);
        self::assertSame([], $this->tracedHub()->getMessages(), 'the refresh is published by worker-notify, never by the request');
        self::assertSame('Moved to In progress.', $this->toastAfterTheRedirect());
        self::assertSame($stateBefore['outbox'] + 1, $this->stateOfTicket()['outbox']);

        // The trace is of the hub the refresh handler publishes to, so its empty list is not one nobody writes to.
        $refreshTicketPages = self::getContainer()->get(RefreshTicketPagesHandler::class);
        self::assertSame($this->tracedHub(), new \ReflectionProperty(RefreshTicketPagesHandler::class, 'hub')->getValue($refreshTicketPages));
    }

    /**
     * @return iterable<string, array{string, string|null, string|null}> the label sent, the label and person saved
     */
    public static function waitingOnLabels(): iterable
    {
        yield 'a label' => ['Customer', 'Customer', null];
        yield 'a label of 100 multibyte characters' => [str_repeat('é', 100), str_repeat('é', 100), null];
        yield 'a label of 100 multibyte characters between spaces' => ['  '.str_repeat('é', 100).' ', str_repeat('é', 100), null];
        yield 'no label and no person, so the reporter' => ['', null, 'Mia'];
    }

    #[Test]
    #[DataProvider('waitingOnLabels')]
    public function theLeadMovesTheTicketToNeedsInputWaitingOnALabel(string $label, ?string $expectedLabel, ?string $expectedPerson): void
    {
        $this->addTicket(['step' => 'review', 'reporter' => 'Mia']);
        $stateBefore = $this->stateOfTicket();
        $this->logIn($this->client, $this->people['Lena']);

        $this->client->request('POST', $this->moveToPath('input'), $this->moveFields([TicketMoveController::WAITING_ON_LABEL_FIELD => $label]));

        self::assertSame('Moved to Needs input.', $this->toastAfterTheRedirect());
        self::assertSame(
            [
                ...$stateBefore,
                'step_id' => $this->steps['input'],
                'version' => self::VERSION + 1,
                'waiting_on_user_id' => null === $expectedPerson ? null : $this->people[$expectedPerson]->id()->toString(),
                'waiting_on_label' => $expectedLabel,
                'events' => $stateBefore['events'] + 1,
                'outbox' => $stateBefore['outbox'] + 1,
            ],
            $this->stateOfTicket(),
        );
    }

    #[Test]
    public function theLeadMovesTheTicketToNeedsInputWaitingOnAPerson(): void
    {
        $this->addTicket(['step' => 'review']);
        $stateBefore = $this->stateOfTicket();
        $this->logIn($this->client, $this->people['Lena']);

        $this->client->request('POST', $this->moveToPath('input'), $this->moveFields([TicketMoveController::WAITING_ON_USER_FIELD => strtoupper($this->people['Raj']->id()->toString())]));

        self::assertSame('Moved to Needs input.', $this->toastAfterTheRedirect());
        self::assertSame(
            [...$stateBefore, 'step_id' => $this->steps['input'], 'version' => self::VERSION + 1, 'waiting_on_user_id' => $this->people['Raj']->id()->toString(), 'events' => $stateBefore['events'] + 1, 'outbox' => $stateBefore['outbox'] + 1],
            $this->stateOfTicket(),
        );
    }

    /**
     * The dialog writes the chosen person or label into the chip's hidden fields, and Turbo posts the chip's form. Mia
     * reported the ticket and Omar has it; Omar is the second choice, so a move that fell back on the reporter fails.
     */
    #[Test]
    public function theNeedsInputDialogsPersonIsPostedByTheChipsFormAndTheTicketWaitsOnThem(): void
    {
        $this->addTicket(['step' => 'review', 'reporter' => 'Mia']);
        $stateBefore = $this->stateOfTicket();
        $this->logIn($this->client, $this->people['Lena']);
        $page = $this->client->request('GET', self::TICKET_PAGE);
        self::assertResponseStatusCodeSame(200);
        $personChoices = $page->filter('dialog#needs-input-'.$this->steps['input'].' input[type="radio"]:not([value=""])')->each(static fn (Crawler $radio): string => (string) $radio->attr('value'));
        self::assertSame([$this->people['Mia']->id()->toString(), $this->people['Omar']->id()->toString()], $personChoices);

        $this->client->submit($this->needsInputFormOf($page), [TicketMoveController::WAITING_ON_USER_FIELD => $personChoices[1]], self::TURBO_FORM_HEADERS);

        $streams = $this->streamsOfTheAnswer(200);
        self::assertSame('Needs input', $streams[TicketPageParts::STATE_ID]->filter('.pill')->text());
        self::assertSame('now · Lena moved it from Code review to Needs input', $streams[TicketPageParts::ACTIVITY_ID]->filter('.activity__line')->first()->text());
        self::assertSame('Moved to Needs input.', $this->toastOf($streams));
        self::assertSame(
            [...$stateBefore, 'step_id' => $this->steps['input'], 'version' => self::VERSION + 1, 'waiting_on_user_id' => $this->people['Omar']->id()->toString(), 'events' => $stateBefore['events'] + 1, 'outbox' => $stateBefore['outbox'] + 1],
            $this->stateOfTicket(),
        );
    }

    #[Test]
    public function theNeedsInputDialogsLabelIsPostedByTheChipsFormAndTheTicketWaitsOnIt(): void
    {
        $this->addTicket(['step' => 'review', 'reporter' => 'Mia']);
        $stateBefore = $this->stateOfTicket();
        $this->logIn($this->client, $this->people['Lena']);
        $page = $this->client->request('GET', self::TICKET_PAGE);
        self::assertResponseStatusCodeSame(200);

        $this->client->submit($this->needsInputFormOf($page), [TicketMoveController::WAITING_ON_LABEL_FIELD => 'Customer'], self::TURBO_FORM_HEADERS);

        self::assertSame('Moved to Needs input.', $this->toastOf($this->streamsOfTheAnswer(200)));
        self::assertSame(
            [...$stateBefore, 'step_id' => $this->steps['input'], 'version' => self::VERSION + 1, 'waiting_on_label' => 'Customer', 'events' => $stateBefore['events'] + 1, 'outbox' => $stateBefore['outbox'] + 1],
            $this->stateOfTicket(),
        );
    }

    /**
     * Mia was offered when the page was read and has left Acme Foods since.
     */
    #[Test]
    public function aPersonWhoLeftAfterTheDialogWasShownIsRefusedAndNothingChanges(): void
    {
        $this->addTicket(['step' => 'review', 'reporter' => 'Mia']);
        $this->logIn($this->client, $this->people['Lena']);
        $page = $this->client->request('GET', self::TICKET_PAGE);
        self::assertResponseStatusCodeSame(200);
        $miasChoice = $page->filter('dialog#needs-input-'.$this->steps['input'].' input[type="radio"][value="'.$this->people['Mia']->id()->toString().'"]');
        self::assertCount(1, $miasChoice);
        $this->removeMembership($this->people['Mia'], $this->acmeFoods);
        $stateBefore = $this->stateOfTicket();

        $this->client->submit($this->needsInputFormOf($page), [TicketMoveController::WAITING_ON_USER_FIELD => (string) $miasChoice->attr('value')], self::TURBO_FORM_HEADERS);

        $streams = $this->streamsOfTheAnswer(422);
        self::assertSame(MoveToasts::MOVE_REFUSED, $this->toastOf($streams));
        self::assertSame('Code review', $streams[TicketPageParts::STATE_ID]->filter('.pill')->text());
        self::assertSame($stateBefore, $this->stateOfTicket());
        self::assertSame($this->steps['review'], $stateBefore['step_id']);
    }

    /**
     * Without script the chip posts its form with both fields empty, so the ticket would wait on its reporter, Mia, who
     * has left Acme Foods.
     */
    #[Test]
    public function aChipPostedWithoutScriptIsRefusedWhenTheReporterHasLeftAndNothingChanges(): void
    {
        $this->addTicket(['step' => 'review', 'reporter' => 'Mia']);
        $this->logIn($this->client, $this->people['Lena']);
        $page = $this->client->request('GET', self::TICKET_PAGE);
        self::assertResponseStatusCodeSame(200);
        $this->removeMembership($this->people['Mia'], $this->acmeFoods);
        $stateBefore = $this->stateOfTicket();
        $form = $this->needsInputFormOf($page);
        self::assertSame(
            [TicketMoveController::WAITING_ON_USER_FIELD => '', TicketMoveController::WAITING_ON_LABEL_FIELD => ''],
            array_intersect_key($form->getValues(), [TicketMoveController::WAITING_ON_USER_FIELD => true, TicketMoveController::WAITING_ON_LABEL_FIELD => true]),
        );

        $this->client->submit($form);

        self::assertSame(MoveToasts::MOVE_REFUSED, $this->toastAfterTheRedirect());
        self::assertSame($stateBefore, $this->stateOfTicket());
        self::assertSame($this->steps['review'], $stateBefore['step_id']);
    }

    /**
     * @return iterable<string, array{string, string}> who posts, and which route
     */
    public static function peopleWhoMayNotMoveTheTeamsTickets(): iterable
    {
        yield 'Sara, an admin with no team role, on move' => ['Sara', 'move'];
        yield 'Sara, an admin with no team role, on move-to' => ['Sara', 'move-to'];
        yield 'Mia, a viewer of the team, on move' => ['Mia', 'move'];
        yield 'Mia, a viewer of the team, on move-to' => ['Mia', 'move-to'];
        yield 'Raj, a manager with no team role, on move' => ['Raj', 'move'];
        yield 'Raj, a manager with no team role, on move-to' => ['Raj', 'move-to'];
    }

    /**
     * Each of them may open the ticket, so it is a 403 and not the 404 of a ticket they may not see.
     */
    #[Test]
    #[DataProvider('peopleWhoMayNotMoveTheTeamsTickets')]
    public function someoneWhoMayNotMoveTheTeamsTicketsIsDeniedAndNothingChanges(string $person, string $route): void
    {
        $this->addTicket(['step' => 'todo']);
        $stateBefore = $this->stateOfTicket();
        $this->logIn($this->client, $this->people[$person]);
        $this->client->request('GET', self::TICKET_PAGE);
        self::assertResponseStatusCodeSame(200);

        $this->client->request('POST', 'move' === $route ? '/t/'.self::KEY.'/move/start_work' : $this->moveToPath('progress'), $this->moveFields());

        self::assertResponseStatusCodeSame(403);
        self::assertSame($stateBefore, $this->stateOfTicket());
    }

    /**
     * The voter lets every member move the team's tickets; the policy keeps Move to another step for the lead.
     */
    #[Test]
    public function aMemberMovingToAnotherStepIsRefusedWithAToastAndNothingChanges(): void
    {
        $this->addTicket(['step' => 'todo']);
        $stateBefore = $this->stateOfTicket();
        $this->logIn($this->client, $this->people['Omar']);

        $this->client->request('POST', $this->moveToPath('progress'), $this->moveFields());

        self::assertSame(MoveToasts::MOVE_REFUSED, $this->toastAfterTheRedirect());
        self::assertSame($stateBefore, $this->stateOfTicket());
    }

    /**
     * @return iterable<string, array{string}> the path, with {progress} for the In progress step's id
     */
    public static function pathsOfNoMove(): iterable
    {
        yield 'a verb no step has' => ['/t/SHOP-1/move/jump'];
        yield 'give_it_to, which only the assign route takes' => ['/t/SHOP-1/move/give_it_to'];
        yield 'a verb in upper case' => ['/t/SHOP-1/move/START_WORK'];
        yield 'a step id that is no uuid' => ['/t/SHOP-1/move-to/progress'];
        yield 'a step id in upper case' => ['/t/SHOP-1/move-to/{PROGRESS}'];
        yield 'a step id with a trailing newline' => ['/t/SHOP-1/move-to/{progress}%0A'];
        yield 'the key in lower case' => ['/t/shop-1/move/start_work'];
        yield 'a key with a leading zero' => ['/t/SHOP-01/move-to/{progress}'];
        yield 'a key no ticket has' => ['/t/SHOP-99/move/start_work'];
    }

    #[Test]
    #[DataProvider('pathsOfNoMove')]
    public function aPathOfNoTicketOrNoMoveIsNotFoundAndNothingChanges(string $path): void
    {
        $this->addTicket(['step' => 'todo']);
        $stateBefore = $this->stateOfTicket();
        $this->logIn($this->client, $this->people['Lena']);

        $this->client->request('POST', strtr($path, ['{progress}' => $this->steps['progress'], '{PROGRESS}' => strtoupper($this->steps['progress'])]), $this->moveFields());

        self::assertResponseStatusCodeSame(404);
        self::assertSame($stateBefore, $this->stateOfTicket());
    }

    /**
     * @return iterable<string, array{string, string}> who posts, and which route
     */
    public static function peopleWhoMayNotSeeTheTicket(): iterable
    {
        yield 'an admin of another organization, on move' => ['Ben', 'move'];
        yield 'an admin of another organization, on move-to' => ['Ben', 'move-to'];
        yield 'a manager with no team role, on a team-only ticket' => ['Raj', 'move'];
    }

    #[Test]
    #[DataProvider('peopleWhoMayNotSeeTheTicket')]
    public function someoneWhoMayNotSeeTheTicketGetsNotFoundAndNothingChanges(string $person, string $route): void
    {
        $this->addTicket(['step' => 'todo', 'visibility' => 'Raj' === $person ? 'team' : 'all']);
        $stateBefore = $this->stateOfTicket();
        $betaBakery = $this->createOrganization('Beta Bakery');
        $this->people['Ben'] = $this->createUserWithoutOrganization('Ben');
        $this->addMembership($this->people['Ben'], $betaBakery);
        $this->setOrgRoles($this->people['Ben'], $betaBakery, '{admin}');
        $this->logIn($this->client, $this->people[$person]);

        $this->client->request('POST', 'move' === $route ? '/t/'.self::KEY.'/move/start_work' : $this->moveToPath('progress'), $this->moveFields());

        self::assertResponseStatusCodeSame(404);
        self::assertSame($stateBefore, $this->stateOfTicket());
    }

    /**
     * @return iterable<string, array{string}> which route
     */
    public static function routes(): iterable
    {
        yield 'move' => ['move'];
        yield 'move-to' => ['move-to'];
    }

    #[Test]
    #[DataProvider('routes')]
    public function anAnonymousVisitorIsSentToLogInAndNothingChanges(string $route): void
    {
        $this->addTicket(['step' => 'todo']);
        $stateBefore = $this->stateOfTicket();

        $this->client->request('POST', 'move' === $route ? '/t/'.self::KEY.'/move/start_work' : $this->moveToPath('progress'), [TicketMoveController::VERSION_FIELD => (string) self::VERSION]);

        self::assertResponseStatusCodeSame(302);
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
        self::assertSame($stateBefore, $this->stateOfTicket());
    }

    /**
     * @return iterable<string, array{string, string}> which route, and which token the form sends
     */
    public static function formTokensOfNoUse(): iterable
    {
        foreach (['move', 'move-to'] as $route) {
            yield 'a made-up token, on '.$route => [$route, 'made-up'];
            yield 'another ticket\'s token, on '.$route => [$route, 'another ticket'];
            yield 'no token, on '.$route => [$route, 'none'];
        }
    }

    #[Test]
    #[DataProvider('formTokensOfNoUse')]
    public function aFormTokenOfNoUseIsRefusedWithAToastAndNothingChanges(string $route, string $token): void
    {
        $this->addTicket(['step' => 'todo']);
        $stateBefore = $this->stateOfTicket();
        $this->logIn($this->client, $this->people['Lena']);
        $fields = $this->moveFields();
        unset($fields[TicketMoveController::CSRF_FIELD]);
        $sentToken = match ($token) {
            'made-up' => 'not-a-token',
            'another ticket' => $this->csrfTokenFor($this->client, TicketMoveController::csrfIdOf('SHOP-2')),
            default => null,
        };
        if (null !== $sentToken) {
            $fields[TicketMoveController::CSRF_FIELD] = $sentToken;
        }

        $this->client->request('POST', 'move' === $route ? '/t/'.self::KEY.'/move/start_work' : $this->moveToPath('progress'), $fields);

        self::assertSame(MoveToasts::FORM_EXPIRED, $this->toastAfterTheRedirect());
        self::assertSame($stateBefore, $this->stateOfTicket());
    }

    /**
     * @return iterable<string, array{string, string|null}> which route, and the version the form sends; null for none
     */
    public static function versionsOfAnotherTicketState(): iterable
    {
        foreach (['move', 'move-to'] as $route) {
            yield 'a version behind, on '.$route => [$route, '2'];
            yield 'a version ahead, on '.$route => [$route, '4'];
            yield 'no version, on '.$route => [$route, null];
            yield 'a version that is no number, on '.$route => [$route, 'three'];
        }
        yield 'a version with a leading zero' => ['move', '03'];
        yield 'a version with a sign' => ['move', '+3'];
        yield 'a version with a trailing newline' => ['move', "3\n"];
        yield 'a version with a decimal point' => ['move', '3.0'];
        yield 'a version past the integer range' => ['move', '99999999999999999999'];
    }

    #[Test]
    #[DataProvider('versionsOfAnotherTicketState')]
    public function aFormOfAnotherVersionIsRefusedAsChangedAndNothingChanges(string $route, ?string $version): void
    {
        $this->addTicket(['step' => 'todo']);
        $stateBefore = $this->stateOfTicket();
        $this->logIn($this->client, $this->people['Lena']);
        $fields = $this->moveFields();
        unset($fields[TicketMoveController::VERSION_FIELD]);
        if (null !== $version) {
            $fields[TicketMoveController::VERSION_FIELD] = $version;
        }

        $this->client->request('POST', 'move' === $route ? '/t/'.self::KEY.'/move/start_work' : $this->moveToPath('progress'), $fields);

        self::assertSame(MoveToasts::CHANGED_MEANWHILE, $this->toastAfterTheRedirect());
        self::assertSame($stateBefore, $this->stateOfTicket());
    }

    /**
     * @return iterable<string, array{string, array<string, string>, string, string}> who clicks, the ticket's columns,
     *                                                                                the verb and the toast
     */
    public static function verbsThePolicyRefuses(): iterable
    {
        yield 'a team rule with a note' => ['Omar', ['step' => 'progress'], 'send_to_testing', self::REVIEW_BEFORE_TEST_NOTE];
        yield 'a team rule whose note names the reviewer' => ['Omar', ['step' => 'review', 'reviewer' => 'Lena'], 'approve_code', 'Team rule: nobody approves their own code. Lena reviews it.'];
        yield 'a team rule whose note names a reviewer Identity does not know' => ['Omar', ['step' => 'review', 'reviewer' => self::NOBODY_ID], 'approve_code', 'Team rule: nobody approves their own code. An unknown person reviews it.'];
        yield 'a lead-only verb by a member' => ['Omar', ['step' => 'todo'], 'put_on_hold', MoveToasts::MOVE_REFUSED];
        yield 'a verb that does not apply in the step' => ['Lena', ['step' => 'todo'], 'pass_it', MoveToasts::MOVE_REFUSED];
    }

    /**
     * A note's person is the ticket's reviewer or one of its team's developers or its lead, all of whom the page has
     * already named; so only someone Identity does not know reaches the controller's second name lookup.
     *
     * @param array{step: string, reviewer?: string} $columns
     */
    #[Test]
    #[DataProvider('verbsThePolicyRefuses')]
    public function aVerbThePolicyRefusesSaysWhyInAToastAndNothingChanges(string $actor, array $columns, string $verb, string $expectedToast): void
    {
        $this->addTicket($columns);
        $stateBefore = $this->stateOfTicket();
        $this->logIn($this->client, $this->people[$actor]);

        $this->client->request('POST', '/t/'.self::KEY.'/move/'.$verb, $this->moveFields());

        self::assertSame($expectedToast, $this->toastAfterTheRedirect());
        self::assertSame($stateBefore, $this->stateOfTicket());
    }

    /**
     * @return iterable<string, array{string, array<string, string>, string}> the step moved to, the waiting-on fields
     *                                                                        and the toast
     */
    public static function movesToThePolicyOrTheFormRefuses(): iterable
    {
        yield 'testing before review, for the lead too' => ['test', [], self::REVIEW_BEFORE_TEST_NOTE];
        yield 'the step the ticket is in' => ['progress', [], MoveToasts::MOVE_REFUSED];
        yield 'a step no organization has' => [self::NOBODY_ID, [], MoveToasts::MOVE_REFUSED];
        yield 'a label to wait on outside Needs input' => ['done', [TicketMoveController::WAITING_ON_LABEL_FIELD => 'Customer'], MoveToasts::MOVE_REFUSED];
        yield 'a label with a CR LF' => ['input', [TicketMoveController::WAITING_ON_LABEL_FIELD => "Cus\r\ntomer"], MoveToasts::WAITING_ON_REFUSED];
        yield 'a label with a U+2028 line separator' => ['input', [TicketMoveController::WAITING_ON_LABEL_FIELD => "Cus\u{2028}tomer"], MoveToasts::WAITING_ON_REFUSED];
        yield 'a blank label' => ['input', [TicketMoveController::WAITING_ON_LABEL_FIELD => "  \t "], MoveToasts::WAITING_ON_REFUSED];
        yield 'a person to wait on whom the organization has not' => ['input', [TicketMoveController::WAITING_ON_USER_FIELD => self::NOBODY_ID], MoveToasts::MOVE_REFUSED];
    }

    /**
     * @param array<string, string> $waitingOnFields
     */
    #[Test]
    #[DataProvider('movesToThePolicyOrTheFormRefuses')]
    public function aMoveToThePolicyOrTheFormRefusesSaysWhyInAToastAndNothingChanges(string $step, array $waitingOnFields, string $expectedToast): void
    {
        $this->addTicket(['step' => 'progress']);
        $stateBefore = $this->stateOfTicket();
        $this->logIn($this->client, $this->people['Lena']);

        $this->client->request('POST', $this->moveToPath($step), $this->moveFields($waitingOnFields));

        self::assertSame($expectedToast, $this->toastAfterTheRedirect());
        self::assertSame($stateBefore, $this->stateOfTicket());
    }

    /**
     * As broken stored data would make the policy, snapshot or Ticket throw: only WaitingOnRefused is the form's fault.
     */
    #[Test]
    public function anotherInvalidArgumentOfTheMoveIsAServerErrorWithNoToast(): void
    {
        $this->replaceMoveToWithOneThatThrows(new \InvalidArgumentException('A step group is one of todo, doing, check, wait or done.'));
        $this->addTicket(['step' => 'todo']);
        $stateBefore = $this->stateOfTicket();
        $this->logIn($this->client, $this->people['Lena']);

        $this->client->request('POST', $this->moveToPath('progress'), $this->moveFields());

        self::assertResponseStatusCodeSame(500);
        self::assertSame($stateBefore, $this->stateOfTicket());
        $page = $this->client->request('GET', self::TICKET_PAGE);
        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $page->filter('.toast-region [data-toast-message-value]'));
    }

    /**
     * @return iterable<string, array{array<string, string>}> the waiting-on fields, with {Raj} for Raj's id
     */
    public static function waitingOnFieldsTheFormAloneRefuses(): iterable
    {
        yield 'a person and a label' => [[TicketMoveController::WAITING_ON_USER_FIELD => '{Raj}', TicketMoveController::WAITING_ON_LABEL_FIELD => 'Customer']];
        yield 'a person who is no uuid' => [[TicketMoveController::WAITING_ON_USER_FIELD => 'raj']];
        yield 'a label of 101 multibyte characters' => [[TicketMoveController::WAITING_ON_LABEL_FIELD => str_repeat('é', 101)]];
    }

    /**
     * moveTo is never asked: a call would end in a server error.
     *
     * @param array<string, string> $waitingOnFields
     */
    #[Test]
    #[DataProvider('waitingOnFieldsTheFormAloneRefuses')]
    public function waitingOnFieldsTheFormAloneRefusesAreRefusedBeforeTheMove(array $waitingOnFields): void
    {
        $this->replaceMoveToWithOneThatThrows(new \LogicException('The form alone shows the waiting-on is wrong.'));
        $this->addTicket(['step' => 'progress']);
        $stateBefore = $this->stateOfTicket();
        $this->logIn($this->client, $this->people['Lena']);
        $sentFields = array_map(fn (string $value): string => strtr($value, ['{Raj}' => $this->people['Raj']->id()->toString()]), $waitingOnFields);

        $this->client->request('POST', $this->moveToPath('input'), $this->moveFields($sentFields));

        self::assertSame(MoveToasts::WAITING_ON_REFUSED, $this->toastAfterTheRedirect());
        self::assertSame($stateBefore, $this->stateOfTicket());
    }

    #[Test]
    public function aTurboVerbMoveAnswersAStreamOfTheNewStateTheNewBoxAndTheToast(): void
    {
        $this->addTicket(['step' => 'progress']);
        $stateBefore = $this->stateOfTicket();
        $this->logIn($this->client, $this->people['Omar']);

        $this->client->request('POST', '/t/'.self::KEY.'/move/send_to_code_review', $this->moveFields(), [], self::TURBO_FORM_HEADERS);

        $streams = $this->streamsOfTheAnswer(200);
        self::assertSame('Code review', $streams[TicketPageParts::STATE_ID]->filter('.pill')->text());
        self::assertSame('Reported in pinround · Group: Checking', $streams[TicketPageParts::STATE_ID]->filter('.ticket__note')->text());
        self::assertSame('Nothing for you now. Lena’s turn.', $streams[TicketPageParts::NEXT_STEP_ID]->filter('.next-step-box__sentence')->text());
        self::assertSame([], $this->versionsOfTheForms($streams[TicketPageParts::NEXT_STEP_ID]));
        self::assertSame('Sent to code review. Lena’s turn now.', $this->toastOf($streams));
        self::assertSame(
            [...$stateBefore, 'step_id' => $this->steps['review'], 'version' => self::VERSION + 1, 'events' => $stateBefore['events'] + 1, 'outbox' => $stateBefore['outbox'] + 1],
            $this->stateOfTicket(),
        );
        $this->assertTheNextPageShowsNoToast();
    }

    #[Test]
    public function aTurboMoveToAnotherStepAnswersAStreamOfTheNewStateTheNewBoxAndTheToast(): void
    {
        $this->addTicket(['step' => 'todo']);
        $stateBefore = $this->stateOfTicket();
        $this->logIn($this->client, $this->people['Lena']);

        $this->client->request('POST', $this->moveToPath('progress'), $this->moveFields(), [], self::TURBO_FORM_HEADERS);

        $streams = $this->streamsOfTheAnswer(200);
        self::assertSame('In progress', $streams[TicketPageParts::STATE_ID]->filter('.pill')->text());
        self::assertSame(['In progress'], $streams[TicketPageParts::NEXT_STEP_ID]->filter('button[aria-current="step"]')->each(static fn (Crawler $chip): string => $chip->text()));
        $versions = $this->versionsOfTheForms($streams[TicketPageParts::NEXT_STEP_ID]);
        self::assertNotEmpty($versions);
        self::assertSame([(string) (self::VERSION + 1)], array_values(array_unique($versions)));
        self::assertSame('Moved to In progress.', $this->toastOf($streams));
        self::assertSame(
            [...$stateBefore, 'step_id' => $this->steps['progress'], 'version' => self::VERSION + 1, 'events' => $stateBefore['events'] + 1, 'outbox' => $stateBefore['outbox'] + 1],
            $this->stateOfTicket(),
        );
        $this->assertTheNextPageShowsNoToast();
    }

    /**
     * Omar sends his ticket to code review by mistake (gap list 1.10). The toast offers Undo for 30 seconds; his Undo
     * 10 seconds later puts the ticket back in In progress, his turn again, and What happened says so.
     */
    #[Test]
    public function theMoveToastOffersUndoAndTheMoverTakesTheMoveBack(): void
    {
        $this->addTicket(['step' => 'progress']);
        $stateBefore = $this->stateOfTicket();
        $this->logIn($this->client, $this->people['Omar']);
        $this->client->request('POST', '/t/'.self::KEY.'/move/send_to_code_review', $this->moveFields(), [], self::TURBO_FORM_HEADERS);
        $undoForm = $this->undoFormOf($this->streamsOfTheAnswer(200));

        $this->clock()->modify('+10 seconds');
        $this->client->request('POST', (string) $undoForm->attr('action'), $this->undoFieldsOf($undoForm), [], self::TURBO_FORM_HEADERS);

        $streams = $this->streamsOfTheAnswer(200);
        self::assertSame('Undone. It is back in In progress.', $this->toastOf($streams));
        self::assertCount(0, $streams[self::TOAST_REGION_ID]->filter('form'), 'An Undo offers no Undo.');
        self::assertSame('In progress', $streams[TicketPageParts::STATE_ID]->filter('.pill')->text());
        self::assertStringContainsString('Omar undid the move to Code review, back to In progress', $streams[TicketPageParts::ACTIVITY_ID]->text());
        self::assertSame(
            [...$stateBefore, 'version' => self::VERSION + 2, 'events' => $stateBefore['events'] + 2, 'outbox' => $stateBefore['outbox'] + 2],
            $this->stateOfTicket(),
        );
    }

    /**
     * @return iterable<string, array{string, int, string}> who presses Undo on Omar's move, how many seconds after it,
     *                                                      and the toast
     */
    public static function undosRefused(): iterable
    {
        yield 'after 30 seconds' => ['Omar', 31, 'Undo works for 30 seconds after a move. Ask Lena to move it back.'];
        yield 'someone who did not make the move' => ['Lena', 5, 'Only Omar can undo this move.'];
    }

    #[Test]
    #[DataProvider('undosRefused')]
    public function aRefusedUndoSaysWhyInAToastAndNothingChanges(string $person, int $secondsLater, string $expectedToast): void
    {
        $this->addTicket(['step' => 'progress']);
        $this->logIn($this->client, $this->people['Omar']);
        $this->client->request('POST', '/t/'.self::KEY.'/move/send_to_code_review', $this->moveFields(), [], self::TURBO_FORM_HEADERS);
        $undoForm = $this->undoFormOf($this->streamsOfTheAnswer(200));
        $stateAfterTheMove = $this->stateOfTicket();
        $this->logIn($this->client, $this->people[$person]);

        $this->clock()->modify(\sprintf('+%d seconds', $secondsLater));
        $this->client->request('POST', (string) $undoForm->attr('action'), [...$this->undoFieldsOf($undoForm), TicketMoveController::CSRF_FIELD => $this->csrfTokenFor($this->client, TicketMoveController::csrfIdOf(self::KEY))], [], self::TURBO_FORM_HEADERS);

        $streams = $this->streamsOfTheAnswer(422);
        self::assertSame($expectedToast, $this->toastOf($streams));
        self::assertSame('Code review', $streams[TicketPageParts::STATE_ID]->filter('.pill')->text());
        self::assertSame($stateAfterTheMove, $this->stateOfTicket());
    }

    /**
     * Lena put the ticket on hold after Omar's move: his Undo carries the version his move left and is refused.
     */
    #[Test]
    public function anUndoAfterSomeoneActedSinceIsRefusedAndNothingChanges(): void
    {
        $this->addTicket(['step' => 'progress']);
        $this->logIn($this->client, $this->people['Omar']);
        $this->client->request('POST', '/t/'.self::KEY.'/move/send_to_code_review', $this->moveFields(), [], self::TURBO_FORM_HEADERS);
        $undoForm = $this->undoFormOf($this->streamsOfTheAnswer(200));
        $this->logIn($this->client, $this->people['Lena']);
        $this->client->request('POST', '/t/'.self::KEY.'/move/put_on_hold', $this->moveFields([TicketMoveController::VERSION_FIELD => (string) (self::VERSION + 1)]), [], self::TURBO_FORM_HEADERS);
        $this->streamsOfTheAnswer(200);
        $stateAfterLenasMove = $this->stateOfTicket();
        $this->logIn($this->client, $this->people['Omar']);

        $this->client->request('POST', (string) $undoForm->attr('action'), [...$this->undoFieldsOf($undoForm), TicketMoveController::CSRF_FIELD => $this->csrfTokenFor($this->client, TicketMoveController::csrfIdOf(self::KEY))], [], self::TURBO_FORM_HEADERS);

        $streams = $this->streamsOfTheAnswer(422);
        self::assertSame(MoveToasts::UNDO_CHANGED_SINCE, $this->toastOf($streams));
        self::assertSame($stateAfterLenasMove, $this->stateOfTicket());
        self::assertSame($this->steps['hold'], $stateAfterLenasMove['step_id']);
    }

    /**
     * A stale page posts the version it read; the answer shows the step and version the ticket is at now, so the next
     * click is made from them.
     */
    #[Test]
    public function aTurboMoveFromAStalePageSaysItChangedAndShowsTheTicketAsItIsNow(): void
    {
        $this->addTicket(['step' => 'todo']);
        $this->logIn($this->client, $this->people['Lena']);
        $staleFields = $this->moveFields();
        $this->client->request('POST', '/t/'.self::KEY.'/move/put_on_hold', $staleFields, [], self::TURBO_FORM_HEADERS);
        $this->streamsOfTheAnswer(200);
        $stateBefore = $this->stateOfTicket();

        $this->client->request('POST', $this->moveToPath('progress'), $staleFields, [], self::TURBO_FORM_HEADERS);

        $streams = $this->streamsOfTheAnswer(422);
        self::assertSame(MoveToasts::CHANGED_MEANWHILE, $this->toastOf($streams));
        self::assertSame('On hold', $streams[TicketPageParts::STATE_ID]->filter('.pill')->text());
        $versions = $this->versionsOfTheForms($streams[TicketPageParts::NEXT_STEP_ID]);
        self::assertNotEmpty($versions);
        self::assertSame([(string) (self::VERSION + 1)], array_values(array_unique($versions)));
        self::assertSame($stateBefore, $this->stateOfTicket());
        self::assertSame($this->steps['hold'], $stateBefore['step_id']);
    }

    /**
     * @return iterable<string, array{string, string, array<string, string>, string}> who posts, the path, the fields
     *                                                                                over SHOP-1's and the toast
     */
    public static function turboMovesRefused(): iterable
    {
        yield 'a team rule with a note' => ['Omar', '/t/SHOP-1/move/send_to_testing', [], self::REVIEW_BEFORE_TEST_NOTE];
        yield 'a lead-only move by a member' => ['Omar', '{todo}', [], MoveToasts::MOVE_REFUSED];
        yield 'a form token of no use' => ['Lena', '/t/SHOP-1/move/put_on_hold', [TicketMoveController::CSRF_FIELD => 'not-a-token'], MoveToasts::FORM_EXPIRED];
        yield 'a version that is no number' => ['Lena', '/t/SHOP-1/move/put_on_hold', [TicketMoveController::VERSION_FIELD => 'three'], MoveToasts::CHANGED_MEANWHILE];
        yield 'a label to wait on that the form alone refuses' => ['Lena', '{input}', [TicketMoveController::WAITING_ON_LABEL_FIELD => str_repeat('é', 101)], MoveToasts::WAITING_ON_REFUSED];
        yield 'a label to wait on that the move refuses' => ['Lena', '{input}', [TicketMoveController::WAITING_ON_LABEL_FIELD => "Cus\r\ntomer"], MoveToasts::WAITING_ON_REFUSED];
    }

    /**
     * @param array<string, string> $fields
     */
    #[Test]
    #[DataProvider('turboMovesRefused')]
    public function aRefusedTurboMoveAnswersAStreamOfTheUnchangedTicketAndWhy(string $actor, string $path, array $fields, string $expectedToast): void
    {
        $this->addTicket(['step' => 'progress']);
        $stateBefore = $this->stateOfTicket();
        $this->logIn($this->client, $this->people[$actor]);

        $this->client->request('POST', strtr($path, ['{todo}' => $this->moveToPath('todo'), '{input}' => $this->moveToPath('input')]), $this->moveFields($fields), [], self::TURBO_FORM_HEADERS);

        $streams = $this->streamsOfTheAnswer(422);
        self::assertSame($expectedToast, $this->toastOf($streams));
        self::assertSame('In progress', $streams[TicketPageParts::STATE_ID]->filter('.pill')->text());
        $versions = $this->versionsOfTheForms($streams[TicketPageParts::NEXT_STEP_ID]);
        self::assertNotEmpty($versions);
        self::assertSame([(string) self::VERSION], array_values(array_unique($versions)));
        self::assertSame($stateBefore, $this->stateOfTicket());
        $this->assertTheNextPageShowsNoToast();
    }

    /**
     * Each of them may open the ticket but not move it: a plain 403 with no stream, so nothing of the ticket is
     * re-rendered for them.
     */
    #[Test]
    #[DataProvider('peopleWhoMayNotMoveTheTeamsTickets')]
    public function someoneWhoMayNotMoveTheTeamsTicketsGetsAPlainDenialToATurboMove(string $person, string $route): void
    {
        $this->addTicket(['step' => 'todo']);
        $stateBefore = $this->stateOfTicket();
        $this->logIn($this->client, $this->people[$person]);

        $this->client->request('POST', 'move' === $route ? '/t/'.self::KEY.'/move/start_work' : $this->moveToPath('progress'), $this->moveFields(), [], self::TURBO_FORM_HEADERS);

        self::assertResponseStatusCodeSame(403);
        $this->assertNoStreamAndNoWordOfTheTicket();
        self::assertSame($stateBefore, $this->stateOfTicket());
    }

    #[Test]
    #[DataProvider('peopleWhoMayNotSeeTheTicket')]
    public function someoneWhoMayNotSeeTheTicketGetsAPlainNotFoundToATurboMove(string $person, string $route): void
    {
        $this->addTicket(['step' => 'todo', 'visibility' => 'Raj' === $person ? 'team' : 'all']);
        $stateBefore = $this->stateOfTicket();
        $betaBakery = $this->createOrganization('Beta Bakery');
        $this->people['Ben'] = $this->createUserWithoutOrganization('Ben');
        $this->addMembership($this->people['Ben'], $betaBakery);
        $this->setOrgRoles($this->people['Ben'], $betaBakery, '{admin}');
        $this->logIn($this->client, $this->people[$person]);

        $this->client->request('POST', 'move' === $route ? '/t/'.self::KEY.'/move/start_work' : $this->moveToPath('progress'), $this->moveFields(), [], self::TURBO_FORM_HEADERS);

        self::assertResponseStatusCodeSame(404);
        $this->assertNoStreamAndNoWordOfTheTicket();
        self::assertSame($stateBefore, $this->stateOfTicket());
    }

    /**
     * The elements the stream replaces are on the ticket page, each once, so Turbo finds its targets.
     */
    #[Test]
    public function theTargetsOfTheStreamAreOnTheTicketPage(): void
    {
        $this->addTicket(['step' => 'todo']);
        $this->logIn($this->client, $this->people['Lena']);

        $page = $this->client->request('GET', self::TICKET_PAGE);

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $page->filter('header.ticket__header #'.TicketPageParts::STATE_ID.' .pill'));
        self::assertCount(1, $page->filter('.ticket__columns > #'.TicketPageParts::NEXT_STEP_ID.' > .next-step-box'));
        self::assertCount(1, $page->filter('#'.self::TOAST_REGION_ID.'[role="status"]'));
    }

    /**
     * The stream replaces the form that had focus; Turbo then focuses the stream's one [autofocus] element, the box's
     * heading, so focus stays in the box (WCAG 2.4.3). The page itself must not pull focus there on load.
     */
    #[Test]
    public function theStreamFocusesTheBoxHeadingAndThePageDoesNot(): void
    {
        $this->addTicket(['step' => 'todo']);
        $this->logIn($this->client, $this->people['Lena']);

        $page = $this->client->request('GET', self::TICKET_PAGE);

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $page->filter('.next-step-box__label'));
        self::assertCount(0, $page->filter('[autofocus]'));
        self::assertNull($page->filter('.next-step-box__label')->attr('tabindex'));

        $this->client->request('POST', $this->moveToPath('progress'), $this->moveFields(), [], self::TURBO_FORM_HEADERS);

        $streams = $this->streamsOfTheAnswer(200);
        self::assertSame(
            [TicketPageParts::STATE_ID => 0, TicketPageParts::NEXT_STEP_ID => 1, TicketPageParts::ROUNDS_ID => 0, TicketPageParts::PEOPLE_ID => 0, TicketPageParts::ACTIVITY_ID => 0, self::TOAST_REGION_ID => 0],
            array_map(static fn (Crawler $stream): int => $stream->filter('[autofocus]')->count(), $streams),
        );
        $heading = $streams[TicketPageParts::NEXT_STEP_ID]->filter('[autofocus]');
        self::assertSame('Your next step', $heading->text());
        self::assertSame('-1', $heading->attr('tabindex'));
    }

    /**
     * The stream answer reads the ticket's page again after the move's transaction, in one of its own: the same refusal
     * answered by stream and by redirect differs by that read alone, warm: 7 queries with the transaction's own
     * statements, the ticket, the names, What happened's moves and the saved turn among them, and for a lead an 8th,
     * the Needs input dialog's one read of which of the ticket's people are members. People's Ping buttons read no SQL.
     */
    #[Test]
    public function theStreamAnswerReadsTheTicketAgainInTheBudgetedQueries(): void
    {
        $this->addTicket(['step' => 'todo']);
        $this->logIn($this->client, $this->people['Lena']);
        $staleFields = $this->moveFields([TicketMoveController::VERSION_FIELD => (string) (self::VERSION - 1)]);
        $this->client->request('GET', self::TICKET_PAGE);
        self::assertResponseStatusCodeSame(200);
        $this->statementsOfAMove($staleFields, []);
        $this->statementsOfAMove($staleFields, self::TURBO_FORM_HEADERS);

        $redirectStatements = $this->statementsOfAMove($staleFields, []);
        $streamStatements = $this->statementsOfAMove($staleFields, self::TURBO_FORM_HEADERS);

        self::assertSame(MoveToasts::CHANGED_MEANWHILE, $this->toastOf($this->streamsOfTheAnswer(422)));
        self::assertSame(8, \count($streamStatements) - \count($redirectStatements), 'the re-read takes 7 and the lead’s Needs input dialog 1');
        self::assertSame(
            1,
            \count(array_keys($streamStatements, 'org_membership', true)) - \count(array_keys($redirectStatements, 'org_membership', true)),
            'the lead’s box offers Needs input, so its dialog reads which of the ticket’s people are members, once',
        );
    }

    /**
     * A move made reads the ticket again after its COMMIT, warm, in the same statements as a refused one: its own
     * transaction with the ticket, the names, What happened's moves and the saved turn.
     */
    #[Test]
    public function aTurboVerbMoveReadsTheTicketAgainInTheBudgetedQueries(): void
    {
        $this->addTicket(['step' => 'todo']);
        $this->logIn($this->client, $this->people['Omar']);
        $this->client->request('GET', self::TICKET_PAGE);
        self::assertResponseStatusCodeSame(200);
        $this->client->request('POST', '/t/'.self::KEY.'/move/start_work', $this->moveFields(), [], self::TURBO_FORM_HEADERS);
        $this->streamsOfTheAnswer(200);

        $reRead = $this->statementsAfterTheMovesCommit('/t/'.self::KEY.'/move/send_to_code_review', $this->moveFields([TicketMoveController::VERSION_FIELD => (string) (self::VERSION + 1)]));

        self::assertSame('Sent to code review. Lena’s turn now.', $this->toastOf($this->streamsOfTheAnswer(200)));
        $state = $this->stateOfTicket();
        self::assertSame([$this->steps['review'], self::VERSION + 2], [$state['step_id'], $state['version']]);
        self::assertSame(self::RE_READ_STATEMENTS, $reRead);
    }

    #[Test]
    public function aTurboMoveToAnotherStepReadsTheTicketAgainInTheBudgetedQueries(): void
    {
        $this->addTicket(['step' => 'todo']);
        $this->logIn($this->client, $this->people['Lena']);
        $this->client->request('GET', self::TICKET_PAGE);
        self::assertResponseStatusCodeSame(200);
        $this->client->request('POST', $this->moveToPath('progress'), $this->moveFields(), [], self::TURBO_FORM_HEADERS);
        $this->streamsOfTheAnswer(200);

        $reRead = $this->statementsAfterTheMovesCommit($this->moveToPath('review'), $this->moveFields([TicketMoveController::VERSION_FIELD => (string) (self::VERSION + 1)]));

        self::assertSame('Moved to Code review.', $this->toastOf($this->streamsOfTheAnswer(200)));
        $state = $this->stateOfTicket();
        self::assertSame([$this->steps['review'], self::VERSION + 2], [$state['step_id'], $state['version']]);
        self::assertSame(self::LEAD_RE_READ_STATEMENTS, $reRead, 'the lead’s box offers Needs input, so its dialog reads which of the ticket’s people are members, once');
    }

    /**
     * From Backlog the give also moves the ticket to To do: one history row, and TicketMoved and TicketAssigned in the
     * outbox.
     */
    #[Test]
    public function theLeadGivesABacklogTicketAndTheStreamSaysWhoGotIt(): void
    {
        $this->addTicket(['step' => 'backlog', 'assignee' => null]);
        $stateBefore = $this->stateOfTicket();
        $this->logIn($this->client, $this->people['Lena']);

        $this->client->request('POST', self::ASSIGN_PATH, $this->giveFields($this->people['Omar']->id()->toString()), [], self::TURBO_FORM_HEADERS);

        $streams = $this->streamsOfTheAnswer(200);
        self::assertSame('Given to Omar.', $this->toastOf($streams));
        self::assertSame('To do', $streams[TicketPageParts::STATE_ID]->filter('.pill')->text());
        $versions = $this->versionsOfTheForms($streams[TicketPageParts::NEXT_STEP_ID]);
        self::assertNotEmpty($versions);
        self::assertSame([(string) (self::VERSION + 1)], array_values(array_unique($versions)));
        self::assertNull($stateBefore['assignee_id']);
        self::assertSame(
            [
                ...$stateBefore,
                'step_id' => $this->steps['todo'],
                'assignee_id' => $this->people['Omar']->id()->toString(),
                'version' => self::VERSION + 1,
                'events' => $stateBefore['events'] + 1,
                'outbox' => $stateBefore['outbox'] + 2,
            ],
            $this->stateOfTicket(),
        );
        $this->assertTheNextPageShowsNoToast();
    }

    /**
     * Any other form gets the toast after a redirect. A re-give stays in To do, so the outbox gets TicketAssigned alone;
     * the id is sent in upper case, as the form may send it, and saved in lower case.
     */
    #[Test]
    public function theLeadGivesAnAssignedToDoTicketToSomeoneElseAndTheToastSaysWho(): void
    {
        $this->addTicket(['step' => 'todo']);
        $stateBefore = $this->stateOfTicket();
        $this->logIn($this->client, $this->people['Lena']);

        $this->client->request('POST', self::ASSIGN_PATH, $this->giveFields(strtoupper($this->people['Lena']->id()->toString())));

        self::assertSame('Given to Lena.', $this->toastAfterTheRedirect());
        self::assertSame($this->people['Omar']->id()->toString(), $stateBefore['assignee_id']);
        self::assertSame(
            [...$stateBefore, 'assignee_id' => $this->people['Lena']->id()->toString(), 'version' => self::VERSION + 1, 'events' => $stateBefore['events'] + 1, 'outbox' => $stateBefore['outbox'] + 1],
            $this->stateOfTicket(),
        );
        self::assertSame($this->steps['todo'], $stateBefore['step_id']);
    }

    /**
     * @return iterable<string, array{string, bool}> who posts, and whether it is a Turbo form
     */
    public static function peopleWhoMayNotGiveTheTicket(): iterable
    {
        yield 'Omar, a member, who may move it, giving it to himself' => ['Omar', false];
        yield 'Omar, a member, on a Turbo form' => ['Omar', true];
        yield 'Mia, a viewer of the team' => ['Mia', false];
        yield 'Sara, an admin with no team role' => ['Sara', false];
        yield 'Raj, a manager with no team role' => ['Raj', false];
    }

    /**
     * Each of them may open the ticket, so it is a 403 and not the 404 of a ticket they may not see. Omar may move the
     * ticket, so his 403 is the lead-only assign check's: without it the policy would answer him with a toast.
     */
    #[Test]
    #[DataProvider('peopleWhoMayNotGiveTheTicket')]
    public function someoneWhoMayNotGiveTheTicketIsDeniedAndNothingChanges(string $person, bool $isTurboForm): void
    {
        $this->addTicket(['step' => 'backlog', 'assignee' => null]);
        $stateBefore = $this->stateOfTicket();
        $this->logIn($this->client, $this->people[$person]);
        $this->client->request('GET', self::TICKET_PAGE);
        self::assertResponseStatusCodeSame(200);

        $this->client->request('POST', self::ASSIGN_PATH, $this->giveFields($this->people['Omar']->id()->toString()), [], $isTurboForm ? self::TURBO_FORM_HEADERS : []);

        self::assertResponseStatusCodeSame(403);
        $this->assertNoStreamAndNoWordOfTheTicket();
        self::assertSame($stateBefore, $this->stateOfTicket());
        self::assertNull($stateBefore['assignee_id']);
    }

    #[Test]
    public function someoneOfAnotherOrganizationGetsNotFoundToAGiveAndNothingChanges(): void
    {
        $this->addTicket(['step' => 'backlog', 'assignee' => null]);
        $stateBefore = $this->stateOfTicket();
        $betaBakery = $this->createOrganization('Beta Bakery');
        $this->people['Ben'] = $this->createUserWithoutOrganization('Ben');
        $this->addMembership($this->people['Ben'], $betaBakery);
        $this->setOrgRoles($this->people['Ben'], $betaBakery, '{admin}');
        $this->logIn($this->client, $this->people['Ben']);

        $this->client->request('POST', self::ASSIGN_PATH, $this->giveFields($this->people['Omar']->id()->toString()));

        self::assertResponseStatusCodeSame(404);
        $this->assertNoStreamAndNoWordOfTheTicket();
        self::assertSame($stateBefore, $this->stateOfTicket());
    }

    #[Test]
    public function anAnonymousVisitorIsSentToLogInFromAGiveAndNothingChanges(): void
    {
        $this->addTicket(['step' => 'backlog', 'assignee' => null]);
        $stateBefore = $this->stateOfTicket();

        $this->client->request('POST', self::ASSIGN_PATH, [TicketMoveController::VERSION_FIELD => (string) self::VERSION, TicketMoveController::ASSIGNEE_FIELD => $this->people['Omar']->id()->toString()]);

        self::assertResponseStatusCodeSame(302);
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
        self::assertSame($stateBefore, $this->stateOfTicket());
    }

    /**
     * @return iterable<string, array{array<string, string>, string}> the fields over a give to Omar, and the toast
     */
    public static function giveFormsOfNoUse(): iterable
    {
        yield 'a made-up token' => [[TicketMoveController::CSRF_FIELD => 'not-a-token'], MoveToasts::FORM_EXPIRED];
        yield 'a version behind' => [[TicketMoveController::VERSION_FIELD => (string) (self::VERSION - 1)], MoveToasts::CHANGED_MEANWHILE];
        yield 'a version that is no number' => [[TicketMoveController::VERSION_FIELD => 'three'], MoveToasts::CHANGED_MEANWHILE];
        yield 'an assignee who is no uuid' => [[TicketMoveController::ASSIGNEE_FIELD => 'omar'], MoveTicket::ASSIGNEE_NOT_IN_TEAM_NOTE];
        yield 'no assignee' => [[TicketMoveController::ASSIGNEE_FIELD => ''], MoveTicket::ASSIGNEE_NOT_IN_TEAM_NOTE];
        yield 'an assignee with a trailing newline' => [[TicketMoveController::ASSIGNEE_FIELD => '{Omar}'."\n"], MoveTicket::ASSIGNEE_NOT_IN_TEAM_NOTE];
    }

    /**
     * Refused before the give is asked, each answered 422 with a stream of the unchanged ticket and the reason.
     *
     * @param array<string, string> $fields
     */
    #[Test]
    #[DataProvider('giveFormsOfNoUse')]
    public function aGiveFormOfNoUseIsRefusedWithAToastAndNothingChanges(array $fields, string $expectedToast): void
    {
        $this->addTicket(['step' => 'backlog', 'assignee' => null]);
        $stateBefore = $this->stateOfTicket();
        $this->logIn($this->client, $this->people['Lena']);
        $sentFields = array_map(fn (string $value): string => strtr($value, ['{Omar}' => $this->people['Omar']->id()->toString()]), $fields);

        $this->client->request('POST', self::ASSIGN_PATH, $this->giveFields($this->people['Omar']->id()->toString(), $sentFields), [], self::TURBO_FORM_HEADERS);

        $streams = $this->streamsOfTheAnswer(422);
        self::assertSame($expectedToast, $this->toastOf($streams));
        self::assertSame('Backlog', $streams[TicketPageParts::STATE_ID]->filter('.pill')->text());
        self::assertSame($stateBefore, $this->stateOfTicket());
    }

    /**
     * @return iterable<string, array{array{step: string, assignee?: string|null}, string, string}> the ticket's columns,
     *                                                                                              who it is given to,
     *                                                                                              and the toast
     */
    public static function givesTheUseCaseRefuses(): iterable
    {
        yield 'a viewer of the team' => [['step' => 'backlog', 'assignee' => null], 'Mia', MoveTicket::ASSIGNEE_NOT_IN_TEAM_NOTE];
        yield 'a manager with no team role' => [['step' => 'backlog', 'assignee' => null], 'Raj', MoveTicket::ASSIGNEE_NOT_IN_TEAM_NOTE];
        yield 'someone Identity does not know' => [['step' => 'backlog', 'assignee' => null], self::NOBODY_ID, MoveTicket::ASSIGNEE_NOT_IN_TEAM_NOTE];
        yield 'the person who already has it' => [['step' => 'todo'], 'Omar', 'Omar already has this ticket.'];
        yield 'a ticket in progress' => [['step' => 'progress'], 'Lena', MoveToasts::MOVE_REFUSED];
    }

    /**
     * @param array{step: string, assignee?: string|null} $columns
     */
    #[Test]
    #[DataProvider('givesTheUseCaseRefuses')]
    public function aGiveTheUseCaseRefusesSaysWhyInAToastAndNothingChanges(array $columns, string $assignee, string $expectedToast): void
    {
        $this->addTicket($columns);
        $stateBefore = $this->stateOfTicket();
        $this->logIn($this->client, $this->people['Lena']);

        $this->client->request('POST', self::ASSIGN_PATH, $this->giveFields(isset($this->people[$assignee]) ? $this->people[$assignee]->id()->toString() : $assignee));

        self::assertSame($expectedToast, $this->toastAfterTheRedirect());
        self::assertSame($stateBefore, $this->stateOfTicket());
    }

    /**
     * Posts a Turbo move and gives what it sent after the move's own COMMIT.
     *
     * @param array<string, string> $fields
     *
     * @return list<string> each statement as the first table it reads from, or whole when it reads from none
     */
    private function statementsAfterTheMovesCommit(string $path, array $fields): array
    {
        $debugData = $this->debugData();
        $debugData->reset();
        $this->client->request('POST', $path, $fields, [], self::TURBO_FORM_HEADERS);

        $sql = self::sqlOf($debugData);
        $commits = array_keys($sql, '"COMMIT"', true);
        self::assertCount(2, $commits, 'the move and the re-read each commit once');

        return self::firstTablesOf(\array_slice($sql, $commits[0] + 1));
    }

    /**
     * @param array<string, string> $fields
     * @param array<string, string> $headers
     *
     * @return list<string> each statement as the first table it reads from, or whole when it reads from none
     */
    private function statementsOfAMove(array $fields, array $headers): array
    {
        $debugData = $this->debugData();
        $debugData->reset();
        $this->client->request('POST', '/t/'.self::KEY.'/move/put_on_hold', $fields, [], $headers);

        return self::firstTablesOf(self::sqlOf($debugData));
    }

    /**
     * @param list<string> $sql
     *
     * @return list<string>
     */
    private static function firstTablesOf(array $sql): array
    {
        return array_map(
            static fn (string $statement): string => match (true) {
                1 === preg_match(self::TENANT_STATEMENT_PATTERN, $statement) => self::TENANT_STATEMENT,
                1 === preg_match('/\bFROM (\w+)/', $statement, $table) => $table[1],
                default => $statement,
            },
            $sql,
        );
    }

    /**
     * Asserts the answer is a Turbo Stream of the status that replaces the ticket's state, its box, its Rounds, its
     * People and What happened and updates the toast region, in that order.
     *
     * @return array<string, Crawler> each stream's template content by its target
     */
    private function streamsOfTheAnswer(int $expectedStatus): array
    {
        self::assertResponseStatusCodeSame($expectedStatus);
        self::assertSame('text/vnd.turbo-stream.html; charset=UTF-8', $this->client->getResponse()->headers->get('Content-Type'));
        preg_match_all(
            '#<turbo-stream action="([a-z]+)" target="([a-z-]+)"><template>(.*?)</template></turbo-stream>#s',
            (string) $this->client->getResponse()->getContent(),
            $matches,
            \PREG_SET_ORDER,
        );
        self::assertSame(
            [['replace', TicketPageParts::STATE_ID], ['replace', TicketPageParts::NEXT_STEP_ID], ['replace', TicketPageParts::ROUNDS_ID], ['replace', TicketPageParts::PEOPLE_ID], ['replace', TicketPageParts::ACTIVITY_ID], ['update', self::TOAST_REGION_ID]],
            array_map(static fn (array $match): array => [$match[1], $match[2]], $matches),
        );
        $streams = [];
        foreach ($matches as [, , $target, $content]) {
            $streams[$target] = new Crawler('<!DOCTYPE html><html><body>'.$content.'</body></html>');
        }
        self::assertCount(1, $streams[TicketPageParts::STATE_ID]->filter('#'.TicketPageParts::STATE_ID));
        self::assertCount(1, $streams[TicketPageParts::NEXT_STEP_ID]->filter('#'.TicketPageParts::NEXT_STEP_ID.' .next-step-box'));
        self::assertSame('/t/'.self::KEY.'/rounds', $streams[TicketPageParts::ROUNDS_ID]->filter('section#'.TicketPageParts::ROUNDS_ID.'.ticket__rounds > turbo-frame#'.RoundsSection::FRAME_ID.'[loading="lazy"][data-live-refresh]')->attr('src'));
        self::assertCount(1, $streams[TicketPageParts::PEOPLE_ID]->filter('#'.TicketPageParts::PEOPLE_ID));
        self::assertCount(1, $streams[TicketPageParts::ACTIVITY_ID]->filter('#'.TicketPageParts::ACTIVITY_ID));

        return $streams;
    }

    /**
     * @param array<string, Crawler> $streams
     */
    private function toastOf(array $streams): string
    {
        $toast = $streams[self::TOAST_REGION_ID]->filter('.toast[data-controller="toast"]');
        self::assertCount(1, $toast);
        $text = $toast->filter('.toast__text');
        self::assertSame('', (0 === $text->count() ? $toast : $text)->text());

        return (string) $toast->attr('data-toast-message-value');
    }

    /**
     * The toast's Undo: a POST form to the Undo route with the version the move left, shown for 30 seconds and kept
     * while the pointer is on the toast or focus is in it; Undo is the button the controller may give the focus.
     *
     * @param array<string, Crawler> $streams
     */
    private function undoFormOf(array $streams): Crawler
    {
        $toast = $streams[self::TOAST_REGION_ID]->filter('.toast[data-controller="toast"]');
        self::assertSame('30000', $toast->attr('data-toast-shown-for-ms-value'));
        self::assertSame(
            'mouseenter->toast#pointerEntered mouseleave->toast#pointerLeft focusin->toast#focusEntered focusout->toast#focusLeft',
            $toast->attr('data-action'),
        );
        $form = $toast->filter('form.toast__action');
        self::assertCount(1, $form);
        self::assertSame('/t/'.self::KEY.'/undo-move', $form->attr('action'));
        self::assertSame(MoveToasts::UNDO, $form->filter('button[data-toast-target="action"]')->text());
        self::assertSame((string) (self::VERSION + 1), $form->filter('input[name="'.TicketMoveController::VERSION_FIELD.'"]')->attr('value'));

        return $form;
    }

    /**
     * @return array<string, string> the hidden fields of the Undo form
     */
    private function undoFieldsOf(Crawler $undoForm): array
    {
        $fields = [];
        foreach ($undoForm->filter('input[type=hidden]') as $input) {
            self::assertInstanceOf(\DOMElement::class, $input);
            $fields[$input->getAttribute('name')] = $input->getAttribute('value');
        }

        return $fields;
    }

    private function clock(): MockClock
    {
        return $this->clock;
    }

    /**
     * @return list<string> the version every move form of the box sends
     */
    private function versionsOfTheForms(Crawler $box): array
    {
        return $box->filter('form input[name="'.TicketMoveController::VERSION_FIELD.'"]')->each(static fn (Crawler $field): string => (string) $field->attr('value'));
    }

    /**
     * The stream answer flashes nothing, so the toast is not shown a second time on the next page load.
     */
    private function assertTheNextPageShowsNoToast(): void
    {
        $page = $this->client->request('GET', self::TICKET_PAGE);
        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $page->filter('.toast-region [data-toast-message-value]'));
    }

    private function assertNoStreamAndNoWordOfTheTicket(): void
    {
        $response = $this->client->getResponse();
        self::assertStringNotContainsString('turbo-stream', (string) $response->headers->get('Content-Type'));
        self::assertStringNotContainsString('<turbo-stream', (string) $response->getContent());
        self::assertStringNotContainsString('Cart total shows the wrong sum', (string) $response->getContent());
    }

    private function replaceMoveToWithOneThatThrows(\Throwable $fault): void
    {
        self::getContainer()->set(UseCaseTicketCommands::class, new readonly class($fault) implements TicketCommands {
            public function __construct(private \Throwable $fault)
            {
            }

            public function report(\App\Ticketing\Api\ProblemReport $report): \App\Ticketing\Api\ReportedTicket
            {
                throw $this->fault;
            }

            public function create(OrganizationId $organizationId, UserId $reporter, string $projectId, string $title, string $description, string $type, string $priority, ?UserId $assignee, \DateTimeImmutable $reportedAt): string
            {
                throw new \LogicException('The move routes create no ticket.');
            }

            public function move(OrganizationId $organizationId, UserId $actor, string $key, string $verb, int $expectedVersion, \DateTimeImmutable $at, ?DraftChoice $draftChoice = DraftChoice::Keep): MoveResult
            {
                throw new \LogicException('Only moveTo is replaced.');
            }

            public function giveTo(OrganizationId $organizationId, UserId $actor, string $key, UserId $assignee, int $expectedVersion, \DateTimeImmutable $at): int
            {
                throw new \LogicException('Only moveTo is replaced.');
            }

            public function undoMove(OrganizationId $organizationId, UserId $actor, string $key, int $expectedVersion, \DateTimeImmutable $at): MoveResult
            {
                throw new \LogicException('Only moveTo is replaced.');
            }

            public function moveTo(OrganizationId $organizationId, UserId $actor, string $key, string $stepId, int $expectedVersion, ?UserId $waitingOnUserId, ?string $waitingOnLabel, \DateTimeImmutable $at, ?DraftChoice $draftChoice = DraftChoice::Keep): MoveResult
            {
                throw $this->fault;
            }
        });
    }

    /**
     * Acme Foods' rule set, which Shop follows under the default step policy, with review_before_test off and the
     * other rules at their defaults.
     */
    private function switchReviewBeforeTestOff(): void
    {
        $this->committedAs($this->acmeFoods, fn (Connection $migrator): int|string => $migrator->executeStatement(
            'INSERT INTO wf_rule_set (organization_id, team_id, tester_moves_done, no_self_review, review_before_test) VALUES (?, NULL, true, true, false)',
            [$this->acmeFoods->toString()],
        ));
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string> SHOP-1's form token and version, then the overrides
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
     * @param array<string, string> $overrides
     *
     * @return array<string, string> SHOP-1's form token and version, the assignee, then the overrides
     */
    private function giveFields(string $assigneeId, array $overrides = []): array
    {
        return $this->moveFields([TicketMoveController::ASSIGNEE_FIELD => $assigneeId, ...$overrides]);
    }

    /**
     * The Needs input chip's own form, whose hidden waiting-on fields the dialog fills.
     */
    private function needsInputFormOf(Crawler $page): Form
    {
        $form = $page->filter('form[data-waiting-on-target="moveForm"]');
        self::assertCount(1, $form);
        self::assertSame('/t/'.self::KEY.'/move-to/'.$this->steps['input'], $form->attr('action'));

        return $form->form();
    }

    /**
     * @param string $step a seeded step's code, or a step id
     */
    private function moveToPath(string $step): string
    {
        return '/t/'.self::KEY.'/move-to/'.($this->steps[$step] ?? $step);
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

    /** The test env's default hub: kernel.debug wraps it in the bundle's trace for the profiler. */
    private function tracedHub(): TraceableHub
    {
        return self::getContainer()->get('mercure.hub.default.traceable');
    }

    /**
     * SHOP-1's columns, and the count of Acme Foods' history and outbox rows.
     *
     * @return array{step_id: mixed, assignee_id: mixed, version: mixed, waiting_on_user_id: mixed, waiting_on_label: mixed, events: int, outbox: int}
     */
    private function stateOfTicket(): array
    {
        $organizationId = $this->acmeFoods->toString();
        $row = [];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId, &$row): int {
            $row = $migrator->fetchAssociative(
                "SELECT step_id, assignee_id, version, waiting_on_user_id, waiting_on_label,
                     (SELECT count(*) FROM tkt_ticket_event WHERE organization_id = ?) AS events,
                     (SELECT count(*) FROM messenger_outbox WHERE queue_name = 'outbox' AND position(? in body) > 0) AS outbox
                 FROM tkt_ticket WHERE organization_id = ? AND key = ?",
                [$organizationId, $organizationId, $organizationId, self::KEY],
            );

            return 0;
        });
        self::assertIsArray($row);
        self::assertIsInt($row['events']);
        self::assertIsInt($row['outbox']);

        return [
            'step_id' => $row['step_id'],
            'assignee_id' => $row['assignee_id'],
            'version' => $row['version'],
            'waiting_on_user_id' => $row['waiting_on_user_id'],
            'waiting_on_label' => $row['waiting_on_label'],
            'events' => $row['events'],
            'outbox' => $row['outbox'],
        ];
    }

    /**
     * The core steps, each placed in its group as Workflow orders them, and Pairing, a step of Shop's own.
     */
    private function addSteps(): void
    {
        $organizationId = $this->acmeFoods->toString();
        $shopTeamId = $this->shop->toString();
        $steps = [];
        foreach ([
            ['backlog', null, 'Backlog', 'todo', 'core', 0], ['todo', null, 'To do', 'todo', 'core', 1],
            ['progress', null, 'In progress', 'doing', 'core', 0], ['review', null, 'Code review', 'check', 'core', 0],
            ['test', null, 'Testing', 'check', 'core', 1], ['input', null, 'Needs input', 'wait', 'core', 0],
            ['hold', null, 'On hold', 'wait', 'core', 1], ['done', null, 'Done', 'done', 'core', 0],
            ['pairing', $shopTeamId, 'Pairing', 'doing', 'team', 5],
        ] as [$code, $teamId, $name, $groupCode, $origin, $position]) {
            $this->steps[$code] = Uuid::v7()->toRfc4122();
            $steps[] = [$this->steps[$code], $teamId, $name, $groupCode, 'team' === $origin ? null : $code, $origin, $position];
        }
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId, $steps): int {
            foreach ($steps as $step) {
                $migrator->executeStatement(
                    'INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [$organizationId, ...$step],
                );
            }

            return 0;
        });
    }

    /**
     * SHOP-1 unless numbered, a major bug of Shop's team for everyone at VERSION, reported by Lena and assigned to Omar
     * unless others are named, or nobody for an assignee of null. The reviewer is a person's name, or an id no user has.
     *
     * @param array{step: string, number?: int, assignee?: string|null, reviewer?: string, reporter?: string, waitingOn?: string, visibility?: string} $columns
     */
    private function addTicket(array $columns): void
    {
        $number = $columns['number'] ?? 1;
        $reviewer = $columns['reviewer'] ?? null;
        $assignee = \array_key_exists('assignee', $columns) ? $columns['assignee'] : 'Omar';
        $parameters = [
            $this->acmeFoods->toString(), Uuid::v7()->toRfc4122(), $this->shopProject, $this->shop->toString(), $number, 'SHOP-'.$number,
            $this->steps[$columns['step']], $this->people[$columns['reporter'] ?? 'Lena']->id()->toString(), null === $assignee ? null : $this->people[$assignee]->id()->toString(),
            null === $reviewer ? null : (isset($this->people[$reviewer]) ? $this->people[$reviewer]->id()->toString() : $reviewer),
            isset($columns['waitingOn']) ? $this->people[$columns['waitingOn']]->id()->toString() : null, $columns['visibility'] ?? 'all', self::VERSION,
        ];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO tkt_ticket (organization_id, id, project_id, team_id, number, key, title, description, type, priority, priority_rank,
                 step_id, reporter_id, assignee_id, reviewer_id, waiting_on_user_id, visibility, version, components, check_reasons, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, 'Cart total shows the wrong sum', '', 'bug', 'major', 2, ?, ?, ?, ?, ?, ?, ?, '{}', '{}',
                 TIMESTAMPTZ '2026-11-04 10:00:00+00', TIMESTAMPTZ '2026-11-05 10:00:00+00')",
            $parameters,
        ));
    }

    /**
     * A review waiting on $reviewer for SHOP-$number, as the turn projector would have saved it.
     */
    private function addWaitingReview(int $number, string $reviewer): void
    {
        $this->committedAs($this->acmeFoods, fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO ins_ticket_turn (organization_id, ticket_id, team_id, project_id, grp, turn_user_id, turn_label, kind, base_score, waiting_since, visibility, reporter_id, assignee_id)
             SELECT organization_id, id, team_id, project_id, 'check', ?, NULL, 'review', 100, TIMESTAMPTZ '2026-11-05 10:00:00+00', visibility, reporter_id, assignee_id
             FROM tkt_ticket WHERE key = ?",
            [$this->people[$reviewer]->id()->toString(), 'SHOP-'.$number],
        ));
    }
}
