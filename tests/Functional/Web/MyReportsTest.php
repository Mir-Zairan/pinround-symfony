<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\Identity\Domain\User;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\Tests\Functional\Identity\LogsInUsers;
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
 * My reports (P14.T15) for Mia, a member of Acme Foods' team Shop, who reports tickets on project SHOP of Shop and on
 * project APP of team Mobile, which she is not in. The clock is fixed on 6 Nov 2026 12:00 UTC, before today: a clock
 * ahead of the login would end the session as idle, and a page that read the real clock would group differently.
 */
final class MyReportsTest extends WebTestCase
{
    use CreatesAcmeFoodsTeams;
    use CreatesTeams;
    use Factories;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }

    private const string NOW = '2026-11-06 12:00:00 UTC';
    private const string MY_REPORTS = '/my-reports';

    private KernelBrowser $client;

    /** @var array<string, User> */
    private array $people;

    private string $shopProject;

    private string $mobileProject;

    private string $doingStep;

    private string $doneStep;

    private int $nextNumber = 1;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTPS' => 'on']);
        $this->client->disableReboot();
        self::getContainer()->set('clock', new MockClock(new \DateTimeImmutable(self::NOW)));
        $this->people = $this->createAcmeFoodsPeople('member');
        $this->shopProject = $this->addProject($this->shop, 'SHOP', 'Shop');
        $this->mobileProject = $this->addProject($this->mobile, 'APP', 'App');
        $this->doingStep = $this->addStep($this->acmeFoods, 'In progress', 'doing', 'progress');
        $this->doneStep = $this->addStep($this->acmeFoods, 'Done', 'done', 'done');
    }

    protected function tearDown(): void
    {
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->committedAs(new OrganizationId($organizationId), static function (Connection $migrator) use ($organizationId): int {
                foreach (['ins_ticket_turn', 'tkt_ticket', 'wf_step'] as $table) {
                    $migrator->executeStatement(\sprintf('DELETE FROM %s WHERE organization_id = ?', $table), [$organizationId]);
                }

                return 0;
            });
        }
        $this->removeCommittedProjects();
        $this->removeUsersAndOrganizations();
    }

    /**
     * Rows are added out of order. Needs you holds an old report with a question to Mia, not the fresh one whose
     * question is to Lena; six stuck reports show four and fold two, two of them made at the same second (the higher
     * id first); Done is folded. Omar's reports, a team-only one of Mobile among them, and a Beta Bakery ticket that
     * names Mia as its reporter never show; Mia's own team-only report of Mobile does.
     */
    #[Test]
    public function eachGroupShowsItsReportsNewestFirstAndNoOneElsesReports(): void
    {
        $this->addReport('Mia', 'SHOP-3', '2026-09-03 09:00:00', '2026-10-25 09:00:00');
        $this->addReport('Mia', 'SHOP-1', '2026-09-01 09:00:00', '2026-10-01 09:00:00', turn: ['Mia', 'answer']);
        $this->addReport('Mia', 'SHOP-5', '2026-09-05 09:00:00', '2026-10-27 09:00:00');
        $this->addReport('Mia', 'SHOP-2', '2026-09-02 09:00:00', '2026-11-05 09:00:00', turn: ['Lena', 'answer']);
        $this->addReport('Mia', 'SHOP-4', '2026-09-04 09:00:00', '2026-10-26 09:00:00');
        $this->addReport('Mia', 'SHOP-6', '2026-09-06 09:00:00', '2026-10-28 09:00:00');
        $tiedFirst = $this->addReport('Mia', 'SHOP-7', '2026-09-07 09:00:00', '2026-10-29 09:00:00');
        $tiedSecond = $this->addReport('Mia', 'SHOP-8', '2026-09-07 09:00:00', '2026-10-29 09:00:00');
        $this->addReport('Mia', 'SHOP-9', '2026-09-09 09:00:00', '2026-11-04 09:00:00', closedAt: '2026-11-04 09:00:00');
        $this->addReport('Mia', 'SHOP-10', '2026-09-10 09:00:00', '2026-11-05 09:00:00', closedAt: '2026-11-05 09:00:00');
        $this->addReport('Mia', 'APP-1', '2026-09-11 09:00:00', '2026-11-06 09:00:00', isTeamOnly: true);
        $this->addReport('Omar', 'APP-2', '2026-09-12 09:00:00', '2026-11-06 09:00:00', isTeamOnly: true);
        $this->addReport('Omar', 'SHOP-11', '2026-09-13 09:00:00', '2026-11-06 09:00:00', turn: ['Mia', 'answer']);
        $this->addBetaBakeryTicketReportedByMia();
        $this->logIn($this->client, $this->people['Mia']);

        $page = $this->client->request('GET', self::MY_REPORTS);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('My reports', $page->filter('main h1')->text());
        self::assertSame(['Needs you 1', 'Stuck 6', 'Moving 2'], self::textsOf($page, '.my-reports__group-name'));
        self::assertSame(['SHOP-1'], self::keysOf($page->filter('.my-reports__group--needs_you')));
        [$tiedHigher, $tiedLower] = strcmp($tiedSecond, $tiedFirst) > 0 ? ['SHOP-8', 'SHOP-7'] : ['SHOP-7', 'SHOP-8'];
        $stuck = $page->filter('.my-reports__group--stuck');
        self::assertSame([$tiedHigher, $tiedLower, 'SHOP-6', 'SHOP-5'], self::keysOf($stuck->filter('.my-reports__group-header + .my-reports__rows')));
        self::assertSame(['SHOP-4', 'SHOP-3'], self::keysOf($stuck->filter('details.my-reports__more:not([open])')));
        self::assertSame('Show 2 more', $stuck->filter('details summary')->text());
        $moving = $page->filter('.my-reports__group--moving');
        self::assertSame(['APP-1', 'SHOP-2'], self::keysOf($moving));
        self::assertCount(0, $moving->filter('details'));
        self::assertSame('Show 2 done', $page->filter('details.my-reports__done:not([open]) > summary')->text());
        self::assertSame(['SHOP-10', 'SHOP-9'], self::keysOf($page->filter('details.my-reports__done')));
        self::assertSame('/t/SHOP-1', $page->filter('.my-reports__group--needs_you .my-reports__link')->attr('href'));
        self::assertSame(['SHOP-1', $tiedHigher, $tiedLower, 'SHOP-6', 'SHOP-5', 'SHOP-4', 'SHOP-3', 'APP-1', 'SHOP-2', 'SHOP-10', 'SHOP-9'], self::keysOf($page));
        self::assertCount(0, $page->filter('.my-reports__pages'));
    }

    #[Test]
    public function groupsWithNoReportsAreLeftOut(): void
    {
        $this->addReport('Mia', 'SHOP-1', '2026-09-01 09:00:00', '2026-11-05 09:00:00');
        $this->logIn($this->client, $this->people['Mia']);

        $page = $this->client->request('GET', self::MY_REPORTS);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Moving 1'], self::textsOf($page, '.my-reports__group-name'));
        self::assertSame(['Nothing to do. Just follow along.'], self::textsOf($page, '.my-reports__hint'));
        self::assertCount(0, $page->filter('details'));
        self::assertCount(0, $page->filter('.empty-state'));
    }

    #[Test]
    public function aPersonWhoReportedNothingSeesHowTheListFills(): void
    {
        $this->addReport('Omar', 'SHOP-1', '2026-09-01 09:00:00', '2026-11-05 09:00:00');
        $this->logIn($this->client, $this->people['Mia']);

        $page = $this->client->request('GET', self::MY_REPORTS);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('When you report a problem, you can follow it here until it is fixed.', $page->filter('main .empty-state')->text());
        self::assertCount(0, $page->filter('.my-reports__group, .my-reports__pages, .my-reports__key'));
    }

    /**
     * Sixty open reports made at seven times, many at the same second, added in no order: the first page holds the
     * newest fifty, Show more the other ten, with no gap and no repeat across the tie at the page's edge.
     */
    #[Test]
    public function showMoreOpensTheNextFiftyByTheKeysetWithNoGapOrRepeat(): void
    {
        $reportedAtByKey = [];
        foreach ([4, 0, 6, 2, 5, 1, 3] as $minute) {
            for ($number = $minute + 1; $number <= 60; $number += 7) {
                $key = 'SHOP-'.$number;
                $reportedAtByKey[$key] = [\sprintf('2026-09-01 09:%02d:00', $minute), $this->addReport('Mia', $key, \sprintf('2026-09-01 09:%02d:00', $minute), '2026-11-05 09:00:00')];
            }
        }
        uasort($reportedAtByKey, static fn (array $one, array $other): int => [$other[0], $other[1]] <=> [$one[0], $one[1]]);
        $expectedKeys = array_map(strval(...), array_keys($reportedAtByKey));
        self::assertCount(60, $expectedKeys);
        $this->logIn($this->client, $this->people['Mia']);

        $firstPage = $this->client->request('GET', self::MY_REPORTS);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(\array_slice($expectedKeys, 0, 50), self::keysOf($firstPage));
        self::assertSame(['Moving 50'], self::textsOf($firstPage, '.my-reports__group-name'));
        self::assertSame('Show 46 more', $firstPage->filter('.my-reports__group--moving details summary')->text());
        self::assertCount(0, $firstPage->filter('.my-reports__first-page'));
        $showMore = $firstPage->filter('.my-reports__pages a.my-reports__next-page');
        self::assertSame('Show more', $showMore->text());

        $secondPage = $this->client->request('GET', (string) $showMore->attr('href'));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(\array_slice($expectedKeys, 50), self::keysOf($secondPage));
        self::assertCount(0, $secondPage->filter('.my-reports__next-page'));
        self::assertSame(self::MY_REPORTS, $secondPage->filter('.my-reports__pages a.my-reports__first-page')->attr('href'));
    }

    #[Test]
    public function aPageAfterTheLastReportSaysSoAndLinksTheNewest(): void
    {
        $ticketId = $this->addReport('Mia', 'SHOP-1', '2026-09-01 09:00:00', '2026-11-05 09:00:00');
        $this->logIn($this->client, $this->people['Mia']);

        $page = $this->client->request('GET', self::MY_REPORTS.'?after='.new \DateTimeImmutable('2026-09-01 09:00:00 UTC')->getTimestamp().'.'.$ticketId);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('No more reports. Back to your newest reports', $page->filter('main .empty-state')->text());
        self::assertSame(self::MY_REPORTS, $page->filter('main .empty-state a')->attr('href'));
        self::assertCount(0, $page->filter('.my-reports__key, .my-reports__pages'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedCursors(): iterable
    {
        yield 'no separator' => ['1788253200'];
        yield 'no time' => ['.0199a000-0000-7000-8000-000000000001'];
        yield 'a negative time' => ['-1.0199a000-0000-7000-8000-000000000001'];
        yield 'a time of twelve digits' => ['178825320000.0199a000-0000-7000-8000-000000000001'];
        yield 'an id that is no uuid' => ['1788253200.SHOP-1'];
        yield 'an uppercase id' => ['1788253200.0199A000-0000-7000-8000-000000000001'];
        yield 'a uuid that is not version 7' => ['1788253200.0199a000-0000-4000-8000-000000000001'];
        yield 'a newline after the id' => ["1788253200.0199a000-0000-7000-8000-000000000001\n"];
    }

    #[Test]
    #[DataProvider('malformedCursors')]
    public function aMalformedCursorIsNotFound(string $after): void
    {
        $this->addReport('Mia', 'SHOP-1', '2026-09-01 09:00:00', '2026-11-05 09:00:00');
        $this->logIn($this->client, $this->people['Mia']);

        $this->client->request('GET', self::MY_REPORTS, ['after' => $after]);

        self::assertResponseStatusCodeSame(404);
        self::assertSelectorNotExists('.my-reports__key');
    }

    #[Test]
    public function aLoggedOutVisitorIsSentToLogIn(): void
    {
        $this->addReport('Mia', 'SHOP-1', '2026-09-01 09:00:00', '2026-11-05 09:00:00');

        $this->client->request('GET', self::MY_REPORTS);

        self::assertResponseRedirects('/login');
    }

    #[Test]
    public function myReportsRunsTheSameQueriesForTwoReportsAndForSixty(): void
    {
        $this->addReport('Mia', 'SHOP-1', '2026-09-01 09:00:00', '2026-11-05 09:00:00', turn: ['Mia', 'answer']);
        $this->addReport('Mia', 'SHOP-2', '2026-09-02 09:00:00', '2026-10-01 09:00:00');
        $this->logIn($this->client, $this->people['Mia']);
        // The first request skips the user refresh every later one pays.
        $this->queriesOf(self::MY_REPORTS);
        $twoReportsQueries = $this->queriesOf(self::MY_REPORTS);
        self::assertCount(2, $this->client->getCrawler()->filter('.my-reports__key'));
        for ($number = 3; $number <= 60; ++$number) {
            $this->addReport('Mia', 'SHOP-'.$number, \sprintf('2026-09-%02d 09:00:00', 1 + $number % 28), 0 === $number % 3 ? '2026-10-01 09:00:00' : '2026-11-05 09:00:00', closedAt: 0 === $number % 5 ? '2026-11-05 09:00:00' : null);
        }

        $sixtyReportsQueries = $this->queriesOf(self::MY_REPORTS);

        self::assertCount(50, $this->client->getCrawler()->filter('.my-reports__key'));
        self::assertNotSame([], $twoReportsQueries);
        self::assertSame(\count($twoReportsQueries), \count($sixtyReportsQueries));
    }

    /**
     * @return list<string> the SQL of every query the request ran
     */
    private function queriesOf(string $uri): array
    {
        $debugData = self::getContainer()->get('doctrine.debug_data_holder');
        $debugData->reset();
        $this->client->request('GET', $uri);
        self::assertResponseStatusCodeSame(200);

        $queries = [];
        foreach ($debugData->getData() as $queriesOfOneConnection) {
            foreach ($queriesOfOneConnection as $query) {
                $queries[] = \is_string($query['sql'] ?? null) ? $query['sql'] : '';
            }
        }

        return $queries;
    }

    /**
     * @return list<string>
     */
    private static function textsOf(Crawler $page, string $selector): array
    {
        return $page->filter($selector)->each(static fn (Crawler $node): string => $node->text());
    }

    /**
     * @return list<string> the ticket keys in the part of the page, in its order
     */
    private static function keysOf(Crawler $part): array
    {
        return self::textsOf($part, '.my-reports__key');
    }

    /**
     * @return string the step's id
     */
    private function addStep(OrganizationId $organizationId, string $name, string $group, string $coreCode): string
    {
        $stepId = Uuid::v7()->toRfc4122();
        $this->committedAs($organizationId, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, ?, ?, ?, 'core', 0)",
            [$organizationId->toString(), $stepId, $name, $group, $coreCode],
        ));

        return $stepId;
    }

    /**
     * A ticket of Acme Foods: on SHOP of Shop, or for a team-only one on APP of Mobile; open in progress, or done when
     * it has a close time. Times are UTC.
     *
     * @param array{string, string}|null $turn the person whose turn it is and the turn's kind; null for no turn row
     *
     * @return string the ticket's id
     */
    private function addReport(string $reporter, string $key, string $reportedAt, string $updatedAt, ?string $closedAt = null, bool $isTeamOnly = false, ?array $turn = null): string
    {
        [$projectId, $teamId] = $isTeamOnly ? [$this->mobileProject, $this->mobile] : [$this->shopProject, $this->shop];
        $ticketId = $this->insertTicket($this->acmeFoods, $projectId, $teamId, $key, null === $closedAt ? $this->doingStep : $this->doneStep, $this->people[$reporter], $reportedAt, $updatedAt, $closedAt, $isTeamOnly ? 'team' : 'all');
        if (null !== $turn) {
            $values = [$this->acmeFoods->toString(), $ticketId, $teamId->toString(), $projectId, $this->people[$turn[0]]->id()->toString(), $turn[1], $updatedAt.'+00', $isTeamOnly ? 'team' : 'all', $this->people[$reporter]->id()->toString()];
            $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
                "INSERT INTO ins_ticket_turn (organization_id, ticket_id, team_id, project_id, grp, turn_user_id, turn_label, kind, base_score, waiting_since, visibility, reporter_id)
                 VALUES (?, ?, ?, ?, 'doing', ?, NULL, ?, 100, CAST(? AS timestamptz), ?, ?)",
                $values,
            ));
        }

        return $ticketId;
    }

    private function addBetaBakeryTicketReportedByMia(): void
    {
        $betaBakery = $this->createOrganization('Beta Bakery');
        $projectId = $this->addProject(null, 'BAKE', 'Bakery', $betaBakery);
        $stepId = $this->addStep($betaBakery, 'In progress', 'doing', 'progress');
        $this->insertTicket($betaBakery, $projectId, null, 'BAKE-1', $stepId, $this->people['Mia'], '2026-11-01 09:00:00', '2026-11-06 09:00:00', null, 'all');
    }

    /**
     * @return string the ticket's id
     */
    private function insertTicket(OrganizationId $organizationId, string $projectId, ?TeamId $teamId, string $key, string $stepId, User $reporter, string $reportedAt, string $updatedAt, ?string $closedAt, string $visibility): string
    {
        $ticketId = Uuid::v7()->toRfc4122();
        $values = [
            $organizationId->toString(), $ticketId, $projectId, $teamId?->toString(), $this->nextNumber++, $key, 'Ticket '.$key, $stepId, $reporter->id()->toString(),
            $visibility, null === $closedAt ? null : $closedAt.'+00', $reportedAt.'+00', $updatedAt.'+00',
        ];
        $this->committedAs($organizationId, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO tkt_ticket (organization_id, id, project_id, team_id, number, key, title, description, type, priority,
                 priority_rank, step_id, reporter_id, visibility, closed_at, components, check_reasons, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, '', 'bug', 'major', 2, ?, ?, ?, CAST(? AS timestamptz), '{}', '{}', CAST(? AS timestamptz), CAST(? AS timestamptz))",
            $values,
        ));

        return $ticketId;
    }
}
