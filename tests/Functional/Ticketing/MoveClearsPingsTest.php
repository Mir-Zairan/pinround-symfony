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
use App\Ticketing\UI\TicketMoveController;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * Auto-clear on the ticket page's moves (FR-PNG-05, P04.T6): a move, a move to another step and a give clear the
 * mover's open pings on that ticket, mark their bell rows done, and say how many in the toast. Each test seeds pings
 * the move must leave alone: one to Mia on the same ticket, one to the mover on SHOP-2, and one to the mover already
 * cleared by Done. The clock is fixed on 6 Nov 2026, before today.
 */
final class MoveClearsPingsTest extends WebTestCase
{
    use CreatesAcmeFoodsTeams;
    use CreatesTeams;
    use Factories;
    use IssuesCsrfTokens;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }

    private const string NOW = '2026-11-06 12:00:00 UTC';
    private const string TICKET_PAGE = '/t/SHOP-1';
    /** The version the tickets are seeded at. */
    private const int VERSION = 3;

    private KernelBrowser $client;

    /** @var array<string, User> */
    private array $people;

    private string $shopProject;

    /** @var array<string, string> step id by core code */
    private array $steps = [];

    /** @var array<string, string> ticket id by key */
    private array $tickets = [];

    /** @var array<string, array{ping: string, notification: string}> the seeded ping's and its bell row's ids, by what it is */
    private array $pings = [];

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
                // ntf_ping and ntf_notification go with their tickets (ON DELETE CASCADE).
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

    #[Test]
    public function omarApprovingCodeClearsHisTwoPingsOnTheTicketAndTheToastSaysHowMany(): void
    {
        $this->addTicket(1, 'review', 'Lena', 'Omar');
        $this->addTicket(2, 'progress', 'Omar', null);
        $this->seedPings('Omar');
        $this->logIn($this->client, $this->people['Omar']);
        $pageBefore = $this->client->request('GET', self::TICKET_PAGE);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('3', $pageBefore->filter('header .bell__trigger .bell__badge')->text());

        $this->client->request('POST', '/t/SHOP-1/move/approve_code', $this->moveFields());

        $page = $this->pageAfterTheRedirect();
        self::assertSame('Code approved. Lena’s turn to test. 2 pings on this ticket cleared.', $this->toastOf($page));
        self::assertSame('1', $page->filter('header .bell__trigger .bell__badge')->text());
        self::assertSame($this->steps['test'], $this->stepOf('SHOP-1'));
        self::assertSame(
            [
                'Lena to Omar on SHOP-1, already done' => ['cleared' => 'done', 'done' => true],
                'Lena to Omar on SHOP-1, older' => ['cleared' => 'acted', 'done' => true],
                'Lena to Omar on SHOP-1, newer' => ['cleared' => 'acted', 'done' => true],
                'Lena to Mia on SHOP-1' => ['cleared' => null, 'done' => false],
                'Lena to Omar on SHOP-2' => ['cleared' => null, 'done' => false],
            ],
            $this->stateOfPings(),
        );
        self::assertSame(2, $this->pingClearedEventsInTheOutbox());
    }

    #[Test]
    public function withAutoClearOffTheMoveClearsNothingAndTheToastHasNoCount(): void
    {
        $this->addTicket(1, 'review', 'Lena', 'Omar');
        $this->addTicket(2, 'progress', 'Omar', null);
        $this->seedPings('Omar');
        $this->storeAutoClear(false);
        $pingsBefore = $this->stateOfPings();
        $this->logIn($this->client, $this->people['Omar']);

        $this->client->request('POST', '/t/SHOP-1/move/approve_code', $this->moveFields());

        $page = $this->pageAfterTheRedirect();
        self::assertSame('Code approved. Lena’s turn to test.', $this->toastOf($page));
        self::assertSame('3', $page->filter('header .bell__trigger .bell__badge')->text());
        self::assertSame($this->steps['test'], $this->stepOf('SHOP-1'));
        self::assertSame(['cleared' => null, 'done' => false], $pingsBefore['Lena to Omar on SHOP-1, older']);
        self::assertSame($pingsBefore, $this->stateOfPings());
        self::assertSame(0, $this->pingClearedEventsInTheOutbox());
    }

    #[Test]
    public function aMoveThePolicyRefusesClearsNothing(): void
    {
        $this->addTicket(1, 'review', 'Omar', 'Lena');
        $this->addTicket(2, 'progress', 'Omar', null);
        $this->seedPings('Omar');
        $pingsBefore = $this->stateOfPings();
        $this->logIn($this->client, $this->people['Omar']);

        $this->client->request('POST', '/t/SHOP-1/move/approve_code', $this->moveFields());

        $page = $this->pageAfterTheRedirect();
        self::assertSame('Team rule: nobody approves their own code. Lena reviews it.', $this->toastOf($page));
        self::assertSame('3', $page->filter('header .bell__trigger .bell__badge')->text());
        self::assertSame($this->steps['review'], $this->stepOf('SHOP-1'));
        self::assertSame(['cleared' => null, 'done' => false], $pingsBefore['Lena to Omar on SHOP-1, older']);
        self::assertSame($pingsBefore, $this->stateOfPings());
        self::assertSame(0, $this->pingClearedEventsInTheOutbox());
    }

    #[Test]
    public function theLeadsMoveToAnotherStepClearsHerPingsOnTheTicket(): void
    {
        $this->addTicket(1, 'todo', 'Omar', null);
        $this->addTicket(2, 'progress', 'Omar', null);
        $this->seedPings('Lena', 'Omar');
        $this->logIn($this->client, $this->people['Lena']);

        $this->client->request('POST', '/t/SHOP-1/move-to/'.$this->steps['progress'], $this->moveFields());

        self::assertSame('Moved to In progress. 2 pings on this ticket cleared.', $this->toastOf($this->pageAfterTheRedirect()));
        self::assertSame(
            [
                'Omar to Lena on SHOP-1, already done' => ['cleared' => 'done', 'done' => true],
                'Omar to Lena on SHOP-1, older' => ['cleared' => 'acted', 'done' => true],
                'Omar to Lena on SHOP-1, newer' => ['cleared' => 'acted', 'done' => true],
                'Omar to Mia on SHOP-1' => ['cleared' => null, 'done' => false],
                'Omar to Lena on SHOP-2' => ['cleared' => null, 'done' => false],
            ],
            $this->stateOfPings(),
        );
    }

    /**
     * A give inside To do leaves the ticket in its step, the one move that publishes no TicketMoved.
     */
    #[Test]
    public function theLeadsGiveInsideToDoClearsHerPingOnTheTicketAndTheToastSaysOne(): void
    {
        $this->addTicket(1, 'todo', 'Omar', null);
        $this->addTicket(2, 'progress', 'Omar', null);
        $this->seedPings('Lena', 'Omar', 1);
        $this->logIn($this->client, $this->people['Lena']);

        $this->client->request('POST', '/t/SHOP-1/assign', $this->moveFields([TicketMoveController::ASSIGNEE_FIELD => $this->people['Lena']->id()->toString()]));

        self::assertSame('Given to Lena. 1 ping on this ticket cleared.', $this->toastOf($this->pageAfterTheRedirect()));
        self::assertSame(
            [
                'Omar to Lena on SHOP-1, already done' => ['cleared' => 'done', 'done' => true],
                'Omar to Lena on SHOP-1, older' => ['cleared' => 'acted', 'done' => true],
                'Omar to Mia on SHOP-1' => ['cleared' => null, 'done' => false],
                'Omar to Lena on SHOP-2' => ['cleared' => null, 'done' => false],
            ],
            $this->stateOfPings(),
        );
        self::assertSame(1, $this->pingClearedEventsInTheOutbox());
    }

    /**
     * Open pings from $sender to $mover on SHOP-1 ($openCount of them, older first), one more already cleared by Done,
     * one to Mia on SHOP-1 and one to $mover on SHOP-2, each with its bell row.
     */
    private function seedPings(string $mover, string $sender = 'Lena', int $openCount = 2): void
    {
        $shop1 = $this->tickets['SHOP-1'];
        $shop2 = $this->tickets['SHOP-2'];
        $this->addPing($sender.' to '.$mover.' on SHOP-1, already done', $sender, $mover, $shop1, 90, true);
        $this->addPing($sender.' to '.$mover.' on SHOP-1, older', $sender, $mover, $shop1, 50, false);
        if ($openCount > 1) {
            $this->addPing($sender.' to '.$mover.' on SHOP-1, newer', $sender, $mover, $shop1, 20, false);
        }
        $this->addPing($sender.' to Mia on SHOP-1', $sender, 'Mia', $shop1, 30, false);
        $this->addPing($sender.' to '.$mover.' on SHOP-2', $sender, $mover, $shop2, 10, false);
    }

    private function addPing(string $name, string $sender, string $recipient, string $ticketId, int $minutesAgo, bool $isDone): void
    {
        $organizationId = $this->acmeFoods->toString();
        $createdAt = new \DateTimeImmutable(self::NOW)->modify(\sprintf('-%d minutes', $minutesAgo))->format('Y-m-d H:i:sP');
        $doneAt = $isDone ? new \DateTimeImmutable(self::NOW)->modify('-1 minute')->format('Y-m-d H:i:sP') : null;
        $ids = ['ping' => Uuid::v7()->toRfc4122(), 'notification' => Uuid::v7()->toRfc4122()];
        $ping = [
            'organization_id' => $organizationId,
            'id' => $ids['ping'],
            'ticket_id' => $ticketId,
            'from_id' => $this->people[$sender]->id()->toString(),
            'to_id' => $this->people[$recipient]->id()->toString(),
            'body' => 'Any news on this one?',
            'created_at' => $createdAt,
            'cleared_at' => $doneAt,
            'cleared_reason' => $isDone ? 'done' : null,
        ];
        $notification = [
            'organization_id' => $organizationId,
            'id' => $ids['notification'],
            'user_id' => $this->people[$recipient]->id()->toString(),
            'kind' => 'ping',
            'actor_id' => $this->people[$sender]->id()->toString(),
            'ticket_id' => $ticketId,
            'data' => json_encode(['actorName' => $sender, 'ticketKey' => array_search($ticketId, $this->tickets, true), 'pingId' => $ids['ping']], \JSON_THROW_ON_ERROR),
            'done_at' => $doneAt,
            'created_at' => $createdAt,
        ];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($ping, $notification): int|string {
            $migrator->insert('ntf_ping', $ping);

            return $migrator->insert('ntf_notification', $notification);
        });
        $this->pings[$name] = $ids;
    }

    /**
     * As an admin saving the settings page would; the other settings stay at their defaults.
     */
    private function storeAutoClear(bool $isAutoClearOn): void
    {
        $this->committedAs($this->acmeFoods, fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO org_settings (organization_id, data, created_at) VALUES (?, jsonb_build_object('auto_clear', ?::boolean), TIMESTAMPTZ '2026-11-01 08:00:00+00')
             ON CONFLICT (organization_id) DO UPDATE SET data = org_settings.data || EXCLUDED.data",
            [$this->acmeFoods->toString(), $isAutoClearOn ? 'true' : 'false'],
        ));
    }

    /**
     * @return array<string, array{cleared: string|null, done: bool}> each seeded ping's cleared reason and whether its bell
     *                                                                row is done, by what it is
     */
    private function stateOfPings(): array
    {
        self::assertNotSame([], $this->pings);
        $organizationId = $this->acmeFoods->toString();
        $rows = [];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId, &$rows): int {
            $rows = $migrator->fetchAllAssociativeIndexed(
                'SELECT p.id, p.cleared_reason, n.done_at IS NOT NULL AS done
                 FROM ntf_ping p JOIN ntf_notification n ON n.organization_id = p.organization_id AND n.data->>\'pingId\' = p.id::text
                 WHERE p.organization_id = ?',
                [$organizationId],
            );

            return 0;
        });
        self::assertCount(\count($this->pings), $rows);
        $states = [];
        foreach ($this->pings as $name => $ids) {
            self::assertArrayHasKey($ids['ping'], $rows);
            $cleared = $rows[$ids['ping']]['cleared_reason'];
            self::assertTrue(null === $cleared || \is_string($cleared));
            $states[$name] = ['cleared' => $cleared, 'done' => true === $rows[$ids['ping']]['done']];
        }

        return $states;
    }

    private function pingClearedEventsInTheOutbox(): int
    {
        $organizationId = $this->acmeFoods->toString();
        $count = 0;
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId, &$count): int {
            $count = $migrator->fetchOne(
                "SELECT count(*) FROM messenger_outbox WHERE queue_name = 'outbox' AND position(? in body) > 0 AND position('PingCleared' in body) > 0",
                [$organizationId],
            );

            return 0;
        });
        self::assertIsInt($count);

        return $count;
    }

    private function stepOf(string $key): mixed
    {
        $organizationId = $this->acmeFoods->toString();
        $stepId = null;
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId, $key, &$stepId): int {
            $stepId = $migrator->fetchOne('SELECT step_id FROM tkt_ticket WHERE organization_id = ? AND key = ?', [$organizationId, $key]);

            return 0;
        });

        return $stepId;
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string> SHOP-1's form token and version, then the overrides
     */
    private function moveFields(array $overrides = []): array
    {
        return [
            TicketMoveController::CSRF_FIELD => $this->csrfTokenFor($this->client, TicketMoveController::csrfIdOf('SHOP-1')),
            TicketMoveController::VERSION_FIELD => (string) self::VERSION,
            ...$overrides,
        ];
    }

    private function pageAfterTheRedirect(): Crawler
    {
        self::assertResponseStatusCodeSame(303);
        self::assertSame(self::TICKET_PAGE, $this->client->getResponse()->headers->get('Location'));
        $page = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);

        return $page;
    }

    private function toastOf(Crawler $page): string
    {
        $toast = $page->filter('.toast-region [data-toast-message-value]');
        self::assertCount(1, $toast);

        return (string) $toast->attr('data-toast-message-value');
    }

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

    /**
     * SHOP-$number, a major bug of Shop's team for everyone at VERSION, reported by Lena.
     */
    private function addTicket(int $number, string $step, string $assignee, ?string $reviewer): void
    {
        $key = 'SHOP-'.$number;
        $this->tickets[$key] = Uuid::v7()->toRfc4122();
        $parameters = [
            $this->acmeFoods->toString(), $this->tickets[$key], $this->shopProject, $this->shop->toString(), $number, $key, $this->steps[$step],
            $this->people['Lena']->id()->toString(), $this->people[$assignee]->id()->toString(), null === $reviewer ? null : $this->people[$reviewer]->id()->toString(), self::VERSION,
        ];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO tkt_ticket (organization_id, id, project_id, team_id, number, key, title, description, type, priority, priority_rank,
                 step_id, reporter_id, assignee_id, reviewer_id, visibility, version, components, check_reasons, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, 'Cart total shows the wrong sum', '', 'bug', 'major', 2, ?, ?, ?, ?, 'all', ?, '{}', '{}',
                 TIMESTAMPTZ '2026-11-04 10:00:00+00', TIMESTAMPTZ '2026-11-05 10:00:00+00')",
            $parameters,
        ));
    }
}
