<?php

declare(strict_types=1);

namespace App\Tests\Functional\Review;

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
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * The round page's move-on buttons (P14.T8, ADR-0048) on Acme Foods' SHOP-1, through the real firewall, voters, policy
 * and database. SHOP-1 is in Testing with Omar as its assignee and Mia as its tester, and it is Mia's turn; Omar's shared
 * Round 1 is open, so Mia's move on is its verdict. Lena leads Shop, Omar develops, Mia tests and Raj is a manager in
 * no team. Omar also keeps a private draft with one screenshot, which his next hand-off shares. The clock is fixed on
 * 6 Nov 2026, before today: a clock ahead of the login would end the session as idle.
 */
final class RoundPageMoveOnButtonsTest extends WebTestCase
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
    private const string KEY = 'SHOP-1';
    private const string ROUND_PAGE = '/t/SHOP-1/rounds/1';
    private const string TICKET_PAGE = '/t/SHOP-1';
    /** The version SHOP-1 is seeded at (SeedsTicketRounds). */
    private const int VERSION = 3;

    private KernelBrowser $client;

    /** @var array<string, User> */
    private array $people;

    /** Read by SeedsTicketRounds. */
    protected string $shopProject;
    /** Testing, where SHOP-1 is. */
    private string $stepId;

    /** @var array<string, string> step id by core code */
    private array $steps = [];

    private string $ticketId;
    private string $omarsRound;
    private string $omarsDraft;

    /** How many rounds SHOP-1 has once a test has seeded its own; stateOf checks it. */
    private int $seededRoundCount = 2;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTPS' => 'on']);
        $this->client->disableReboot();
        $clock = new MockClock(new \DateTimeImmutable(self::NOW));
        self::getContainer()->set('clock', $clock);
        new AddTicketEventPartitions($this->migrator(), $clock)();
        $this->people = $this->createAcmeFoodsPeople('member');
        $shopId = $this->shop->toString();
        $omarId = $this->people['Omar']->id()->toString();
        $miaId = $this->people['Mia']->id()->toString();
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($shopId, $omarId, $miaId): int {
            $migrator->executeStatement("UPDATE org_team_member SET job = 'developer' WHERE team_id = ? AND user_id = ?", [$shopId, $omarId]);
            $migrator->executeStatement("UPDATE org_team_member SET job = 'tester' WHERE team_id = ? AND user_id = ?", [$shopId, $miaId]);

            return 0;
        });
        $this->shopProject = $this->addProject($this->shop, 'SHOP', 'Shop');
        $this->addSteps();
        $this->stepId = $this->steps['test'];
        $this->ticketId = $this->addTicket(self::KEY);
        $ticketId = $this->ticketId;
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            'UPDATE tkt_ticket SET tester_id = ? WHERE id = ?',
            [$miaId, $ticketId],
        ));
        $this->addTurnRowOfMia();
        $this->omarsRound = $this->addRound($this->ticketId, 1, 'draft', 'Omar', '2026-11-05 10:00:00+00');
        $this->addScreenshot($this->omarsRound, $this->addMedia('ready', 'Omar'), 0, 'Cart after the fix', null);
        $this->omarsDraft = $this->addRound($this->ticketId, null, 'draft', 'Omar', null);
        $this->addScreenshot($this->omarsDraft, $this->addMedia('ready', 'Omar'), 0, 'Second fix', null);
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

    #[Test]
    public function thePersonWhoseTurnItIsSeesTheTicketsMoveOnVerbsAsPlainForms(): void
    {
        $this->logIn($this->client, $this->people['Mia']);

        $page = $this->client->request('GET', self::ROUND_PAGE);

        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('Ready for you', $page->filter('h1.round-page__title')->text());
        $box = $page->filter('section.round-move-on');
        self::assertCount(1, $box);
        self::assertSame('Your verdict', $box->filter('h2.round-move-on__label')->text());
        self::assertSame('Move SHOP-1 on to close Round 1.', $box->filter('.round-move-on__sentence')->text());
        $forms = $box->filter('form');
        self::assertSame(
            [
                ['Pass it', '/t/SHOP-1/move/pass_it', 'false', '3'],
                ['Needs changes', '/t/SHOP-1/move/needs_changes', 'false', '3'],
            ],
            $forms->each(static fn (Crawler $form): array => [
                $form->filter('button[type="submit"]')->text(),
                $form->attr('action'),
                $form->attr('data-turbo'),
                $form->filter('input[name="'.TicketMoveController::VERSION_FIELD.'"]')->attr('value'),
            ]),
        );
        // A token is masked afresh on every render, so the move from the round page below proves it is the move's.
        self::assertNotSame('', (string) $forms->first()->filter('input[name="'.TicketMoveController::CSRF_FIELD.'"]')->attr('value'));
        self::assertCount(0, $forms->filter('[data-failed-toast]'), 'Without Turbo no form waits for a stream to fail.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function peopleWhoseTurnItIsNot(): iterable
    {
        yield 'the lead' => ['Lena'];
        yield 'a manager' => ['Raj'];
        yield 'the round\'s owner' => ['Omar'];
    }

    #[Test]
    #[DataProvider('peopleWhoseTurnItIsNot')]
    public function nobodyElseSeesTheMoveOnVerbs(string $person): void
    {
        $this->logIn($this->client, $this->people[$person]);

        $page = $this->client->request('GET', self::ROUND_PAGE);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Round 1', trim((string) preg_replace('/·.*\z/s', '', $page->filter('h1.round-page__title')->text())), 'The page itself shows.');
        self::assertCount(0, $page->filter('.round-move-on'));
        self::assertCount(0, $page->filter('form[action^="/t/SHOP-1/move/"]'));
    }

    /**
     * ADR-0048: the owner's own move never closes a round he shared with "Share now", so on his turn the page neither
     * says it is ready for him nor offers him a move on.
     */
    #[Test]
    public function theOwnerWhoseTurnItIsGetsNoMoveOnVerbsForARoundHeSharedWithShareNow(): void
    {
        $this->giveOmarTheTurnInProgress();
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', self::ROUND_PAGE);

        self::assertResponseStatusCodeSame(200);
        $title = $page->filter('h1.round-page__title')->text();
        self::assertStringStartsWith('Round 1', $title, 'The page itself shows.');
        self::assertStringNotContainsString('Ready for', $title);
        self::assertCount(0, $page->filter('.round-move-on'));
        self::assertCount(0, $page->filter('form[action^="/t/SHOP-1/move/"]'));
    }

    #[Test]
    public function theOwnerWhoseTurnItIsGetsTheMoveOnVerbsForARoundHeSharedAtHandOff(): void
    {
        $this->giveOmarTheTurnInProgress();
        $roundId = $this->omarsRound;
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "UPDATE rvw_round SET decision = 'shared', decided_at = shared_at WHERE id = ?",
            [$roundId],
        ));
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', self::ROUND_PAGE);

        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('Ready for you', $page->filter('h1.round-page__title')->text());
        $box = $page->filter('section.round-move-on');
        self::assertCount(1, $box);
        self::assertSame('Move SHOP-1 on to close Round 1.', $box->filter('.round-move-on__sentence')->text());
        self::assertGreaterThan(0, $box->filter('form[action^="/t/SHOP-1/move/"]')->count());
    }

    /**
     * ADR-0048: a lead-only verb never closes a round, also when the lead is the person whose turn it is. On hold, the
     * Your next step box offers Lena only lead-only moves, so the round page offers her none.
     */
    #[Test]
    public function theLeadWhoseTurnItIsOnHoldGetsNoMoveOnVerbsAsHersAreAllLeadOnly(): void
    {
        $holdStepId = $this->steps['hold'];
        $lenaId = $this->people['Lena']->id()->toString();
        $ticketId = $this->ticketId;
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($holdStepId, $lenaId, $ticketId): int {
            $migrator->executeStatement('UPDATE tkt_ticket SET step_id = ? WHERE id = ?', [$holdStepId, $ticketId]);
            $migrator->executeStatement("UPDATE ins_ticket_turn SET grp = 'wait', kind = 'decide', turn_user_id = ? WHERE ticket_id = ?", [$lenaId, $ticketId]);

            return 0;
        });
        $this->logIn($this->client, $this->people['Lena']);

        $ticketPage = $this->client->request('GET', self::TICKET_PAGE);
        self::assertResponseStatusCodeSame(200);
        self::assertContains('Go on', $ticketPage->filter('.next-step-box__verbs button')->each(static fn (Crawler $button): string => $button->text()), 'Her box offers her a move.');

        $page = $this->client->request('GET', self::ROUND_PAGE);

        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $page->filter('.round-move-on'));
    }

    #[Test]
    public function aClosedRoundShowsNoMoveOnVerbsEvenToThePersonWhoseTurnItIs(): void
    {
        $this->closeRound($this->omarsRound);
        $this->logIn($this->client, $this->people['Mia']);

        $page = $this->client->request('GET', self::ROUND_PAGE);

        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $page->filter('.round-move-on'));
    }

    /**
     * The form posts without Turbo, so the Share draft question comes back as a page of its own that needs no script.
     */
    #[Test]
    public function aMoveOnByThePersonWithADraftAsksAboutItOnAPageAndMovesNothing(): void
    {
        $miasDraft = $this->addRound($this->ticketId, null, 'draft', 'Mia', null);
        $this->addScreenshot($miasDraft, $this->addMedia('ready', 'Mia'), 0, 'Still wrong total', null);
        $this->seededRoundCount = 3;
        $this->logIn($this->client, $this->people['Mia']);
        $page = $this->client->request('GET', self::ROUND_PAGE);
        self::assertResponseStatusCodeSame(200);
        $before = $this->stateOf();

        $this->client->submit($page->filter('section.round-move-on')->selectButton('Needs changes')->form());

        self::assertResponseStatusCodeSame(409);
        self::assertStringStartsWith('text/html', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('Your draft round has 1 screenshot.', (string) $this->client->getResponse()->getContent());
        self::assertSame($before, $this->stateOf());
        self::assertSame('open', $before['rounds'][$this->omarsRound]['state']);
    }

    #[Test]
    public function aMovePostedByTheLeadWhoseTurnItIsNotIsRefusedAndChangesNothing(): void
    {
        $before = $this->stateOf();
        $this->logIn($this->client, $this->people['Lena']);

        $this->client->request('POST', '/t/SHOP-1/move/needs_changes', $this->moveFields(self::VERSION));

        self::assertSame(MoveToasts::MOVE_REFUSED, $this->toastAfterTheRedirect());
        self::assertSame($before, $this->stateOf());
        self::assertSame('open', $before['rounds'][$this->omarsRound]['state']);
    }

    #[Test]
    public function aMovePostedByAManagerIsDeniedAndChangesNothing(): void
    {
        $before = $this->stateOf();
        $this->logIn($this->client, $this->people['Raj']);

        $this->client->request('POST', '/t/SHOP-1/move/needs_changes', $this->moveFields(self::VERSION));

        self::assertResponseStatusCodeSame(403);
        self::assertSame($before, $this->stateOf());
    }

    #[Test]
    public function theTurnPersonsMoveOnFromTheRoundPageClosesTheRoundAndFreesTheNextNumber(): void
    {
        $this->logIn($this->client, $this->people['Mia']);
        $page = $this->client->request('GET', self::ROUND_PAGE);
        self::assertResponseStatusCodeSame(200);

        $this->client->submit($page->filter('section.round-move-on')->selectButton('Needs changes')->form());

        self::assertSame('Sent back to Omar with your notes.', $this->toastAfterTheRedirect());
        $afterVerdict = $this->stateOf();
        self::assertSame($this->steps['progress'], $afterVerdict['ticket']['step_id']);
        self::assertSame('closed', $afterVerdict['rounds'][$this->omarsRound]['state']);
        self::assertNull($afterVerdict['rounds'][$this->omarsDraft]['number']);

        $this->logIn($this->client, $this->people['Omar']);
        $this->client->request('POST', '/t/SHOP-1/move/send_to_code_review', $this->moveFields(self::VERSION + 1, [TicketMoveController::DRAFT_CHOICE_FIELD => 'share']));

        self::assertStringEndsWith('Your draft is now round 2 for the team.', $this->toastAfterTheRedirect());
        self::assertSame(2, $this->stateOf()['rounds'][$this->omarsDraft]['number']);
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private function moveFields(int $version, array $overrides = []): array
    {
        return [
            TicketMoveController::CSRF_FIELD => $this->csrfTokenFor($this->client, TicketMoveController::csrfIdOf(self::KEY)),
            TicketMoveController::VERSION_FIELD => (string) $version,
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
        self::assertCount($this->seededRoundCount, $rounds);

        return ['ticket' => ['step_id' => $ticket['step_id'], 'version' => $ticket['version'], 'events' => $ticket['events']], 'rounds' => $rounds];
    }

    /**
     * The turn read model says Mia tests SHOP-1.
     */
    private function addTurnRowOfMia(): void
    {
        $miaId = $this->people['Mia']->id()->toString();
        $ticketId = $this->ticketId;
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO ins_ticket_turn (organization_id, ticket_id, team_id, project_id, grp, turn_user_id, turn_label, kind, base_score, waiting_since, visibility, reporter_id, assignee_id, tester_id)
             SELECT organization_id, id, team_id, project_id, 'check', ?, NULL, 'test', 10, '2026-11-06 11:00:00+00', visibility, reporter_id, assignee_id, tester_id FROM tkt_ticket WHERE id = ?",
            [$miaId, $ticketId],
        ));
    }

    /**
     * SHOP-1 is back In progress and the turn read model says Omar builds it.
     */
    private function giveOmarTheTurnInProgress(): void
    {
        $progressStepId = $this->steps['progress'];
        $omarId = $this->people['Omar']->id()->toString();
        $ticketId = $this->ticketId;
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($progressStepId, $omarId, $ticketId): int {
            $migrator->executeStatement('UPDATE tkt_ticket SET step_id = ? WHERE id = ?', [$progressStepId, $ticketId]);
            $migrator->executeStatement("UPDATE ins_ticket_turn SET grp = 'doing', kind = 'build', turn_user_id = ? WHERE ticket_id = ?", [$omarId, $ticketId]);

            return 0;
        });
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
}
