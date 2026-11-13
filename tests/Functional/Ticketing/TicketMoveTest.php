<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ticketing;

use App\Identity\Domain\User;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use App\Tests\Functional\Web\IssuesCsrfTokens;
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
 * The ticket page's moves, POST /t/{key}/move/{verb} and /t/{key}/move-to/{stepId}, on Acme Foods' SHOP-1 through the
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

    private const string NOW = '2026-11-06 12:00:00 UTC';
    private const string KEY = 'SHOP-1';
    private const string TICKET_PAGE = '/t/SHOP-1';
    /** The version SHOP-1 is seeded at, so a stale form can send a lower one. */
    private const int VERSION = 3;
    private const string NOBODY_ID = '0197a3c0-0000-7000-8000-00000000dead';
    private const string REVIEW_BEFORE_TEST_NOTE = 'Team rule: code review comes before testing.';

    private KernelBrowser $client;

    /** @var array<string, User> */
    private array $people;

    private string $shopProject;

    /** @var array<string, string> step id by core code, and 'pairing' for Shop's own step */
    private array $steps = [];

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTPS' => 'on']);
        $this->client->disableReboot();
        $clock = new MockClock(new \DateTimeImmutable(self::NOW));
        self::getContainer()->set('clock', $clock);
        new AddTicketEventPartitions($this->migrator(), $clock)();
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
                foreach (['tkt_ticket_event', 'ins_ticket_turn', 'ins_project_count_ticket', 'ins_project_counts', 'tkt_ticket', 'wf_step'] as $table) {
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
     * @return iterable<string, array{string, string, string, string, string}> who clicks, the step before, the verb, the
     *                                                                         step after and the toast
     */
    public static function verbMoves(): iterable
    {
        yield 'a verb whose toast names whose turn it is' => ['Omar', 'progress', 'send_to_code_review', 'review', 'Sent to code review. Lena’s turn now.'];
        yield 'a verb whose toast names nobody' => ['Omar', 'todo', 'start_work', 'progress', 'Started. Status is In progress.'];
        yield 'move on from a team step' => ['Omar', 'pairing', 'move_on', 'review', 'Moved to Code review.'];
        yield 'a lead-only verb by the lead' => ['Lena', 'todo', 'put_on_hold', 'hold', 'On hold. It waits on you.'];
    }

    #[Test]
    #[DataProvider('verbMoves')]
    public function aVerbMovesTheTicketAndSaysSoInAToast(string $actor, string $stepBefore, string $verb, string $expectedStep, string $expectedToast): void
    {
        $this->addTicket(['step' => $stepBefore]);
        $stateBefore = $this->stateOfTicket();
        $this->logIn($this->client, $this->people[$actor]);

        $this->client->request('POST', '/t/'.self::KEY.'/move/'.$verb, $this->moveFields());

        self::assertSame($expectedToast, $this->toastAfterTheRedirect());
        self::assertSame(
            [...$stateBefore, 'step_id' => $this->steps[$expectedStep], 'version' => self::VERSION + 1, 'events' => $stateBefore['events'] + 1, 'outbox' => $stateBefore['outbox'] + 1],
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
        yield 'give_it_to, which comes with P03.T8' => ['/t/SHOP-1/move/give_it_to'];
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

    #[Test]
    public function anAnonymousVisitorIsSentToLogInAndNothingChanges(): void
    {
        $this->addTicket(['step' => 'todo']);
        $stateBefore = $this->stateOfTicket();

        $this->client->request('POST', '/t/'.self::KEY.'/move/start_work', [TicketMoveController::VERSION_FIELD => (string) self::VERSION]);

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
        yield 'a lead-only verb by a member' => ['Omar', ['step' => 'todo'], 'put_on_hold', MoveToasts::MOVE_REFUSED];
        yield 'a verb that does not apply in the step' => ['Lena', ['step' => 'todo'], 'pass_it', MoveToasts::MOVE_REFUSED];
    }

    /**
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
        yield 'a label of 101 multibyte characters' => ['input', [TicketMoveController::WAITING_ON_LABEL_FIELD => str_repeat('é', 101)], MoveToasts::WAITING_ON_REFUSED];
        yield 'a label with a CR LF' => ['input', [TicketMoveController::WAITING_ON_LABEL_FIELD => "Cus\r\ntomer"], MoveToasts::WAITING_ON_REFUSED];
        yield 'a label with a U+2028 line separator' => ['input', [TicketMoveController::WAITING_ON_LABEL_FIELD => "Cus\u{2028}tomer"], MoveToasts::WAITING_ON_REFUSED];
        yield 'a blank label' => ['input', [TicketMoveController::WAITING_ON_LABEL_FIELD => "  \t "], MoveToasts::WAITING_ON_REFUSED];
        yield 'a person to wait on who is no uuid' => ['input', [TicketMoveController::WAITING_ON_USER_FIELD => 'raj'], MoveToasts::WAITING_ON_REFUSED];
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

    /**
     * SHOP-1's columns, and the count of Acme Foods' history and outbox rows.
     *
     * @return array{step_id: mixed, version: mixed, waiting_on_user_id: mixed, waiting_on_label: mixed, events: int, outbox: int}
     */
    private function stateOfTicket(): array
    {
        $organizationId = $this->acmeFoods->toString();
        $row = [];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId, &$row): int {
            $row = $migrator->fetchAssociative(
                "SELECT step_id, version, waiting_on_user_id, waiting_on_label,
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
     * unless others are named.
     *
     * @param array{step: string, number?: int, assignee?: string, reviewer?: string, reporter?: string, visibility?: string} $columns
     */
    private function addTicket(array $columns): void
    {
        $number = $columns['number'] ?? 1;
        $parameters = [
            $this->acmeFoods->toString(), Uuid::v7()->toRfc4122(), $this->shopProject, $this->shop->toString(), $number, 'SHOP-'.$number,
            $this->steps[$columns['step']], $this->people[$columns['reporter'] ?? 'Lena']->id()->toString(), $this->people[$columns['assignee'] ?? 'Omar']->id()->toString(),
            isset($columns['reviewer']) ? $this->people[$columns['reviewer']]->id()->toString() : null, $columns['visibility'] ?? 'all', self::VERSION,
        ];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO tkt_ticket (organization_id, id, project_id, team_id, number, key, title, description, type, priority, priority_rank,
                 step_id, reporter_id, assignee_id, reviewer_id, visibility, version, components, check_reasons, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, 'Cart total shows the wrong sum', '', 'bug', 'major', 2, ?, ?, ?, ?, ?, ?, '{}', '{}',
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
