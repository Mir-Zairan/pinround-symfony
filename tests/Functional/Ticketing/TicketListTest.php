<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ticketing;

use App\Identity\Domain\User;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * The tickets list at /tickets on Acme Foods' project SHOP through the real firewall, voters and database. Test
 * organizations have no core steps, so each test adds one step per group: Needs input, Code review, In progress, To do
 * and Done.
 */
final class TicketListTest extends WebTestCase
{
    use CreatesAcmeFoodsTeams;
    use CreatesTeams;
    use Factories;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }

    private const string TICKETS = '/tickets';
    private const array PRIORITY_RANKS = ['blocker' => 0, 'critical' => 1, 'major' => 2, 'normal' => 3, 'minor' => 4];

    private KernelBrowser $client;

    /** @var array<string, User> */
    private array $people;

    private string $shopProject;

    /** @var array<string, string> step id by group code */
    private array $steps = [];

    private int $nextNumber = 1;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTPS' => 'on']);
        $this->people = $this->createAcmeFoodsPeople();
        $this->shopProject = $this->addProject($this->shop, 'SHOP', 'Shop');
        $this->addSteps();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->committedAs(new OrganizationId($organizationId), static function (Connection $migrator) use ($organizationId): int {
                $migrator->executeStatement('DELETE FROM tkt_ticket WHERE organization_id = ?', [$organizationId]);
                $migrator->executeStatement('DELETE FROM wf_step WHERE organization_id = ?', [$organizationId]);

                return 0;
            });
        }
        $this->removeCommittedProjects();
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function theLeadSeesOneSectionPerStepInGroupOrderWithItsTagCountHintAndRows(): void
    {
        $this->addTicket('todo', 'Add a gift note', 'normal');
        $this->addTicket('doing', 'Cart total shows the wrong sum', 'critical', ['assignee' => 'Omar', 'turn' => 'Omar']);
        $this->addTicket('doing', 'Coupon field takes any text', 'minor');
        $this->addTicket('check', 'Search shows no results', 'major', ['type' => 'story', 'release' => '2.4', 'turn' => 'Lena']);
        $this->addTicket('wait', 'Pay button is hidden on small screens', 'blocker');
        $this->addTicket('done', 'Footer links point nowhere', 'normal');
        $this->logIn($this->client, $this->people['Lena']);

        $page = $this->client->request('GET', self::TICKETS);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('All tickets', $page->filter('h1')->text());
        self::assertSame('5 tickets · grouped by step', $page->filter('.tickets__total')->text());
        self::assertSame([
            ['Needs input', '1', 'Waiting', 'Someone must answer first'],
            ['Code review', '1', 'Checking', 'Being checked'],
            ['In progress', '2', 'Doing', 'Being built'],
            ['To do', '1', 'To do', 'Not started'],
        ], $page->filter('.tickets__step')->each(static fn (Crawler $step): array => [
            $step->filter('.tickets__step-name')->text(),
            $step->filter('.tickets__step-count')->text(),
            $step->filter('.tag')->text(),
            $step->filter('.tickets__hint')->text(),
        ]));
        self::assertSame('true', $page->filter('.tickets__step-toggle')->first()->attr('aria-expanded'));
        self::assertSame([
            ['SHOP-2', 'Cart total shows the wrong sum', 'Bug · Turn: Omar', 'Critical', true, 'Omar'],
            ['SHOP-3', 'Coupon field takes any text', 'Bug', 'Minor', false, 'Nobody assigned'],
        ], $this->rowsOf($page->filter('[data-group="doing"]')));
        self::assertSame(
            [['SHOP-4', 'Search shows no results', 'Story · Release 2.4 · Turn: you', 'Major', false, 'Nobody assigned']],
            $this->rowsOf($page->filter('[data-group="check"]')),
        );
        self::assertSame('Blocker', $page->filter('[data-group="wait"] .tickets__priority--urgent')->text());
        self::assertSame('Assigned to Omar', $page->filter('[data-ticket-key="SHOP-2"] .av')->attr('title'));
    }

    #[Test]
    public function doneIsListedLastWithNoHintAndNoTurnOnlyWhenAskedFor(): void
    {
        $this->addTicket('todo', 'Add a gift note', 'normal');
        $this->addTicket('done', 'Footer links point nowhere', 'normal', ['turn' => 'Lena']);
        $this->logIn($this->client, $this->people['Lena']);

        $page = $this->client->request('GET', self::TICKETS.'?done=1');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['todo', 'done'], $page->filter('.tickets__step')->each(static fn (Crawler $step): ?string => $step->attr('data-group')));
        self::assertSame('2 tickets · grouped by step', $page->filter('.tickets__total')->text());
        self::assertCount(0, $page->filter('[data-group="done"] .tickets__hint'));
        self::assertSame('Bug', $page->filter('[data-ticket-key="SHOP-2"] .tickets__meta')->text());
    }

    #[Test]
    public function aProjectKeyShowsOnlyThatProjectUnderItsKeyAndName(): void
    {
        $appProject = $this->addProject($this->mobile, 'APP', 'Mobile app');
        $this->addTicket('todo', 'Add a gift note', 'normal');
        $this->addTicket('todo', 'Login hangs on start', 'normal', ['project' => $appProject, 'key' => 'APP']);
        $this->logIn($this->client, $this->people['Lena']);

        $page = $this->client->request('GET', self::TICKETS.'?project=SHOP');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('SHOP · Shop', $page->filter('h1')->text());
        self::assertSame(['SHOP-1'], $page->filter('.tickets__row')->each(static fn (Crawler $row): ?string => $row->attr('data-ticket-key')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function keysOfNoProjectOfAcmeFoods(): iterable
    {
        yield 'unknown' => ['NOPE'];
        yield 'lowercase' => ['shop'];
        yield 'of Beta Bakery' => ['BAKE'];
    }

    #[Test]
    #[DataProvider('keysOfNoProjectOfAcmeFoods')]
    public function aKeyOfNoProjectTheViewerMaySeeIsNotFound(string $projectKey): void
    {
        $betaBakery = $this->createOrganization('Beta Bakery');
        $this->addProject(null, 'BAKE', 'Bakery', $betaBakery);
        $this->logIn($this->client, $this->people['Lena']);

        $this->client->request('GET', self::TICKETS.'?project='.$projectKey);

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function aPersonOfAnotherOrganizationSeesNoneOfItsTickets(): void
    {
        $this->addTicket('todo', 'Add a gift note', 'normal');
        $betaBakery = $this->createOrganization('Beta Bakery');
        $ben = $this->createUserWithoutOrganization('Ben');
        $this->addMembership($ben, $betaBakery);
        $this->logIn($this->client, $ben);

        $page = $this->client->request('GET', self::TICKETS);

        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $page->filter('.tickets__row'));
        self::assertSame('No tickets here yet.', $page->filter('.empty-state')->text());
        self::assertSame('0 tickets · grouped by step', $page->filter('.tickets__total')->text());
    }

    #[Test]
    public function aPersonOutsideTheTeamSeesNoTeamOnlyTicket(): void
    {
        $this->addTicket('todo', 'Add a gift note', 'normal');
        $this->addTicket('todo', 'Price rules for staff', 'normal', ['visibility' => 'team']);
        $this->logIn($this->client, $this->people['Raj']);

        $page = $this->client->request('GET', self::TICKETS);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['SHOP-1'], $page->filter('.tickets__row')->each(static fn (Crawler $row): ?string => $row->attr('data-ticket-key')));
        self::assertSame('1 ticket · grouped by step', $page->filter('.tickets__total')->text());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badShowMoreQueries(): iterable
    {
        yield 'step not a uuid' => ['step=SHOP&after=3.1805000000.0197a3c0-0000-7000-8000-000000000001'];
        yield 'rank out of range' => ['step=0197a3c0-0000-7000-8000-00000000f0d0&after=9.1805000000.0197a3c0-0000-7000-8000-000000000001'];
        yield 'ticket id not a uuid' => ['step=0197a3c0-0000-7000-8000-00000000f0d0&after=3.1805000000.SHOP-1'];
        yield 'after without step' => ['after=3.1805000000.0197a3c0-0000-7000-8000-000000000001'];
        yield 'step without after' => ['step=0197a3c0-0000-7000-8000-00000000f0d0'];
        yield 'after as an array' => ['step=0197a3c0-0000-7000-8000-00000000f0d0&after[]=1'];
    }

    #[Test]
    #[DataProvider('badShowMoreQueries')]
    public function aMalformedShowMoreLinkIsABadRequest(string $query): void
    {
        $this->logIn($this->client, $this->people['Lena']);

        $this->client->request('GET', self::TICKETS.'?'.$query);

        self::assertResponseStatusCodeSame(400);
    }

    #[Test]
    public function showMoreListsTheNextRowsOfThatStepOnly(): void
    {
        $this->addTicket('doing', 'Cart total shows the wrong sum', 'normal');
        $this->addManyTickets('todo', 52);
        $this->logIn($this->client, $this->people['Lena']);

        $page = $this->client->request('GET', self::TICKETS.'?project=SHOP');
        self::assertResponseStatusCodeSame(200);
        self::assertCount(50, $page->filter('[data-group="todo"] .tickets__row'));
        self::assertCount(0, $page->filter('[data-group="doing"] [data-step-more]'));
        $moreLink = $page->filter('[data-group="todo"] [data-step-more]');
        self::assertSame('Show more', $moreLink->text());
        self::assertStringContainsString('project=SHOP', (string) $moreLink->attr('href'));

        $nextPage = $this->client->click($moreLink->link());

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['todo'], $nextPage->filter('.tickets__step')->each(static fn (Crawler $step): ?string => $step->attr('data-group')));
        // The oldest two by updated_at, newest first, after the 50 the first page showed.
        self::assertSame(['SHOP-3', 'SHOP-2'], $nextPage->filter('.tickets__row')->each(static fn (Crawler $row): ?string => $row->attr('data-ticket-key')));
        self::assertCount(0, $nextPage->filter('[data-step-more]'));
    }

    #[Test]
    public function thePageReadsAtMostFiveQueriesMoreThanTheShellFor200OpenTickets(): void
    {
        $this->client->disableReboot();
        foreach (['wait', 'check', 'doing', 'todo'] as $groupCode) {
            $this->addManyTickets($groupCode, 50);
        }
        $this->addTurnsAndAssignees();
        $this->logIn($this->client, $this->people['Lena']);

        $shellQueryCount = $this->queryCountOf('/_test/menu/shell');
        $pageQueryCount = $this->queryCountOf(self::TICKETS);

        self::assertLessThanOrEqual(5, $pageQueryCount - $shellQueryCount);
    }

    #[Test]
    public function aProjectPageReadsAtMostFiveQueriesMoreThanTheShell(): void
    {
        $this->client->disableReboot();
        $this->addManyTickets('todo', 60);
        $this->addTurnsAndAssignees();
        $this->logIn($this->client, $this->people['Lena']);

        $shellQueryCount = $this->queryCountOf('/_test/menu/shell');
        $pageQueryCount = $this->queryCountOf(self::TICKETS.'?project=SHOP');

        self::assertLessThanOrEqual(5, $pageQueryCount - $shellQueryCount);
    }

    private function queryCountOf(string $uri): int
    {
        $debugData = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $debugData);
        $debugData->reset();
        $page = $this->client->request('GET', $uri);
        self::assertResponseStatusCodeSame(200);
        if (str_starts_with($uri, self::TICKETS)) {
            self::assertGreaterThan(0, $page->filter('.tickets__row .av[title]')->count());
            self::assertGreaterThan(0, $page->filter('.tickets__meta:contains("Turn: you")')->count());
        }

        $queryCount = 0;
        foreach ($debugData->getData() as $queriesOfOneConnection) {
            self::assertIsArray($queriesOfOneConnection);
            $queryCount += \count($queriesOfOneConnection);
        }

        return $queryCount;
    }

    /**
     * @return list<list<string|bool|null>> key, title, meta, priority, whether it is urgent, and the assignee's label
     */
    private function rowsOf(Crawler $step): array
    {
        return $step->filter('.tickets__row')->each(static fn (Crawler $row): array => [
            $row->attr('data-ticket-key'),
            $row->filter('.tickets__row-title')->text(),
            $row->filter('.tickets__meta')->text(),
            $row->filter('.tickets__priority')->text(),
            1 === $row->filter('.tickets__priority--urgent')->count(),
            $row->filter('.tickets__assignee')->attr('aria-label'),
        ]);
    }

    private function addSteps(): void
    {
        $organizationId = $this->acmeFoods->toString();
        $steps = [];
        foreach (['wait' => ['Needs input', 'input'], 'check' => ['Code review', 'review'], 'doing' => ['In progress', 'progress'], 'todo' => ['To do', 'todo'], 'done' => ['Done', 'done']] as $groupCode => [$name, $coreCode]) {
            $this->steps[$groupCode] = Uuid::v7()->toRfc4122();
            $steps[] = [$this->steps[$groupCode], $name, $groupCode, $coreCode, \count($steps)];
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
     * A bug of Shop's team, visible to all, updated a minute later than the one added before it.
     *
     * @param array{assignee?: string, turn?: string, type?: string, release?: string, visibility?: string, project?: string, key?: string} $details
     */
    private function addTicket(string $groupCode, string $title, string $priority, array $details = []): void
    {
        $number = $this->nextNumber++;
        $organizationId = $this->acmeFoods->toString();
        $ticketId = Uuid::v7()->toRfc4122();
        $parameters = [
            $organizationId, $ticketId, $details['project'] ?? $this->shopProject, $this->shop->toString(), $number,
            ($details['key'] ?? 'SHOP').'-'.$number, $title, $details['type'] ?? 'bug', $details['release'] ?? null, $priority,
            self::PRIORITY_RANKS[$priority], $this->steps[$groupCode], $this->people['Lena']->id()->toString(),
            $details['visibility'] ?? 'all', isset($details['assignee']) ? $this->people[$details['assignee']]->id()->toString() : null,
            \sprintf('2027-03-15 10:%02d:00+00', $number),
        ];
        $turnUserId = isset($details['turn']) ? $this->people[$details['turn']]->id()->toString() : null;
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($parameters, $turnUserId, $groupCode, $ticketId): int {
            $migrator->executeStatement(
                "INSERT INTO tkt_ticket (organization_id, id, project_id, team_id, number, key, title, description, type, release, priority,
                     priority_rank, step_id, reporter_id, visibility, assignee_id, components, check_reasons, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, '', ?, ?, ?, ?, ?, ?, ?, ?, '{}', '{}', TIMESTAMPTZ '2027-03-15 09:00:00+00', CAST(? AS timestamptz))",
                $parameters,
            );
            if (null !== $turnUserId) {
                // A done ticket keeps no turn row in the product; one given here proves the page leaves Done's out itself.
                self::insertTurn($migrator, $ticketId, 'done' === $groupCode ? 'todo' : $groupCode, $turnUserId);
            }

            return 0;
        });
    }

    /**
     * Normal bugs of Shop's team in one statement, each updated a minute later than the one before.
     */
    private function addManyTickets(string $groupCode, int $count): void
    {
        $firstNumber = $this->nextNumber;
        $this->nextNumber += $count;
        $parameters = [$this->acmeFoods->toString(), $this->shopProject, $this->shop->toString(), $this->steps[$groupCode], $this->people['Lena']->id()->toString(), $firstNumber, $firstNumber + $count - 1];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO tkt_ticket (organization_id, id, project_id, team_id, number, key, title, description, type, priority, priority_rank,
                 step_id, reporter_id, visibility, components, check_reasons, created_at, updated_at)
             SELECT CAST(? AS uuid), CAST('0197a3c0-0000-7000-8002-' || lpad(to_hex(number), 12, '0') AS uuid), CAST(? AS uuid), CAST(? AS uuid), number, 'SHOP-' || number, 'Checkout button is hidden', '',
                 'bug', 'normal', 3, CAST(? AS uuid), CAST(? AS uuid), 'all', '{}', '{}',
                 TIMESTAMPTZ '2027-03-15 08:00:00+00', TIMESTAMPTZ '2027-03-15 08:00:00+00' + number * INTERVAL '1 minute'
             FROM generate_series(CAST(? AS int), CAST(? AS int)) AS number",
            $parameters,
        ));
    }

    /**
     * Gives every open ticket a turn, Lena's or Omar's by turns, and Omar or Mia as assignee.
     */
    private function addTurnsAndAssignees(): void
    {
        $organizationId = $this->acmeFoods->toString();
        $lena = $this->people['Lena']->id()->toString();
        $omar = $this->people['Omar']->id()->toString();
        $mia = $this->people['Mia']->id()->toString();
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId, $lena, $omar, $mia): int {
            $migrator->executeStatement(
                'UPDATE tkt_ticket SET assignee_id = CASE WHEN number % 2 = 0 THEN CAST(? AS uuid) ELSE CAST(? AS uuid) END WHERE organization_id = ?',
                [$omar, $mia, $organizationId],
            );
            $migrator->executeStatement(
                "INSERT INTO ins_ticket_turn (organization_id, ticket_id, team_id, project_id, grp, turn_user_id, kind, base_score, waiting_since, visibility, reporter_id)
                 SELECT ticket.organization_id, ticket.id, ticket.team_id, ticket.project_id, step.grp,
                     CASE WHEN ticket.number % 2 = 0 THEN CAST(? AS uuid) ELSE CAST(? AS uuid) END, 'build', 10, ticket.updated_at, ticket.visibility, ticket.reporter_id
                 FROM tkt_ticket ticket JOIN wf_step step ON step.organization_id = ticket.organization_id AND step.id = ticket.step_id
                 WHERE ticket.organization_id = ? AND step.grp <> 'done'",
                [$lena, $omar, $organizationId],
            );

            return 0;
        });
    }

    private static function insertTurn(Connection $migrator, string $ticketId, string $groupCode, string $turnUserId): int
    {
        return (int) $migrator->executeStatement(
            "INSERT INTO ins_ticket_turn (organization_id, ticket_id, team_id, project_id, grp, turn_user_id, kind, base_score, waiting_since, visibility, reporter_id)
             SELECT organization_id, id, team_id, project_id, ?, ?, 'build', 10, updated_at, visibility, reporter_id FROM tkt_ticket WHERE id = ?",
            [$groupCode, $turnUserId, $ticketId],
        );
    }
}
