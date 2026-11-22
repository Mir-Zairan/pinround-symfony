<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\Identity\Domain\User;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Ticketing\UI\TicketShowController;
use App\Ticketing\UI\WhoseTurnController;
use App\Web\UI\Controller\HomeController;
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
 * Home's "Needs you now", "Also waiting on you" and "Your team" (P14.T14) on Acme Foods' project SHOP of team Shop,
 * against the viewer's card on Whose turn?. The clock is fixed on 6 Nov 2026, before today: a clock ahead of the login
 * would end the session as idle. Lena leads Shop and Mobile, Omar is a member of Shop and Mia its viewer.
 */
final class HomeNeedsYouNowTest extends WebTestCase
{
    use CreatesAcmeFoodsTeams;
    use CreatesTeams;
    use Factories;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }

    private const string NOW = '2026-11-06 12:00:00 UTC';
    private const string HOME = '/';
    private const string TURN = '/tickets/turn';

    private KernelBrowser $client;

    /** @var array<string, User> */
    private array $people;

    private string $shopProject;

    private string $doingStep;

    private int $nextNumber = 1;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTPS' => 'on']);
        $this->client->disableReboot();
        self::getContainer()->set('clock', new MockClock(new \DateTimeImmutable(self::NOW)));
        $this->people = $this->createAcmeFoodsPeople();
        $this->shopProject = $this->addProject($this->shop, 'SHOP', 'Shop');
        $this->doingStep = $this->addStep($this->acmeFoods, 'In progress', 'doing', 'progress');
    }

    protected function tearDown(): void
    {
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->committedAs(new OrganizationId($organizationId), static function (Connection $migrator) use ($organizationId): int {
                foreach (['ins_ticket_turn', 'tkt_ticket', 'wf_step', 'web_home_focus'] as $table) {
                    $migrator->executeStatement(\sprintf('DELETE FROM %s WHERE organization_id = ?', $table), [$organizationId]);
                }

                return 0;
            });
        }
        $this->removeCommittedProjects();
        $this->removeUsersAndOrganizations();
    }

    /**
     * @return iterable<string, array{string, string, string, list<string>}>
     */
    public static function peopleWithTurns(): iterable
    {
        yield 'Lena' => ['Lena', '3 tickets wait on you, Lena', '3 tickets wait on you · the oldest for 5 days', ['waiting 5 days', 'waiting 1 day', 'today']];
        yield 'Omar' => ['Omar', '2 tickets wait on you, Omar', '2 tickets wait on you · the oldest for 7 days', ['waiting 7 days', 'waiting 3 days']];
        // A viewer cannot move tickets, so Whose turn? counts Mia outside the team.
        yield 'Mia' => ['Mia', '1 ticket waits on you, Mia', '1 ticket waits on you · for 4 days · outside the team', ['waiting 4 days']];
    }

    /**
     * Beside each person's own tickets wait one on Raj and one on the label Customer, both older than any of theirs; a
     * Done ticket Lena is assigned, which has no turn row; and a ticket of Beta Bakery whose turn row names Lena's id.
     *
     * @param list<string> $waits
     */
    #[Test]
    #[DataProvider('peopleWithTurns')]
    public function homeAndWhoseTurnAgreeOnTheTicketsThatWaitOnThePerson(string $person, string $headline, string $turnSentence, array $waits): void
    {
        $keysOf = [
            'Lena' => [$this->addWaitingTicket('Lena', 5), $this->addWaitingTicket('Lena', 1), $this->addWaitingTicket('Lena', 0)],
            'Omar' => [$this->addWaitingTicket('Omar', 7), $this->addWaitingTicket('Omar', 3)],
            'Mia' => [$this->addWaitingTicket('Mia', 4)],
        ];
        $this->addWaitingTicket('Raj', 30);
        $this->addWaitingTicket(null, 40);
        $this->addDoneTicketAssignedToLena();
        $this->addBetaBakeryTicketWaitingOnLena();
        $this->logIn($this->client, $this->people[$person]);

        $home = $this->client->request('GET', self::HOME);

        self::assertResponseStatusCodeSame(200);
        self::assertSame($headline, $home->filter('h1')->text());
        $homeKeys = self::textsOf($home, '.home__need-key');
        self::assertSame($keysOf[$person], $homeKeys);
        self::assertSame($waits, self::textsOf($home, '.home__need-wait'));
        self::assertSame('/t/'.$keysOf[$person][0], $home->filter('.home__need-open')->attr('href'));
        self::assertCount(0, $home->filter('.home__see-all'));
        self::assertSame('Nothing else waits on you.', $home->filter('section[aria-labelledby="home-also-waiting"] .empty-state')->text());
        $personId = $this->people[$person]->id()->toString();

        $turn = $this->client->request('GET', self::TURN.'?open='.$personId);

        self::assertResponseStatusCodeSame(200);
        $yourCard = $turn->filter('.turn__card--you');
        self::assertCount(1, $yourCard);
        self::assertSame($turnSentence, $yourCard->filter('.turn__sentence')->text());
        self::assertSame($homeKeys, self::textsOf($yourCard, '.turn__key'));
    }

    /**
     * Whose turn? puts the oldest card first when the viewer has none, so it must not become theirs.
     */
    #[Test]
    public function aPersonNothingWaitsOnIsAllClearWhileOthersWait(): void
    {
        $this->addWaitingTicket('Omar', 12);
        $this->addWaitingTicket(null, 9);
        $this->logIn($this->client, $this->people['Lena']);

        $home = $this->client->request('GET', self::HOME);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('All clear, Lena', $home->filter('h1')->text());
        self::assertCount(0, $home->filter('.home__need'));
        self::assertSame('Nothing waits on you right now.', $home->filter('section[aria-labelledby="home-needs-you-now"] .empty-state')->text());
        self::assertSame('Nothing else waits on you.', $home->filter('section[aria-labelledby="home-also-waiting"] .empty-state')->text());
    }

    /**
     * All of Lena's tickets have one base score, so the longest waits score highest.
     */
    #[Test]
    public function twelveTicketsShowTheTopFiveAndSeeAllOpensTheViewersCard(): void
    {
        foreach ([3, 12, 1, 7, 10, 5, 2, 11, 8, 4, 9, 6] as $waitDays) {
            $this->addWaitingTicket('Lena', $waitDays);
        }
        $this->addWaitingTicket('Omar', 20);
        $lenaId = $this->people['Lena']->id()->toString();
        $this->logIn($this->client, $this->people['Lena']);

        $home = $this->client->request('GET', self::HOME);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('12 tickets wait on you, Lena', $home->filter('h1')->text());
        self::assertSame(
            'Do the top 5 first. You lead the team, so reviews, decisions and unclear new tickets come to you.',
            $home->filter('.home__subline')->text(),
        );
        self::assertSame(
            ['waiting 12 days', 'waiting 11 days', 'waiting 10 days', 'waiting 9 days', 'waiting 8 days'],
            self::textsOf($home, '.home__need-wait'),
        );
        self::assertSame(['SHOP-2', 'SHOP-8', 'SHOP-5', 'SHOP-11', 'SHOP-9'], self::textsOf($home, '.home__need-key'));
        self::assertSame('top 5 of 12', $home->filter('.home__section-note')->text());
        $seeAll = $home->filter('section[aria-labelledby="home-needs-you-now"] .home__section-header a.home__see-all');
        self::assertSame('See all', $seeAll->text());
        $seeAllUrl = (string) $seeAll->attr('href');
        self::assertSame(self::TURN.'?open='.$lenaId.'#turn-'.$lenaId, $seeAllUrl);
        self::assertSame('7 more tickets wait on you.', $home->filter('.home__also-waiting')->text());

        $turn = $this->client->request('GET', $seeAllUrl);

        self::assertResponseStatusCodeSame(200);
        $yourCard = $turn->filter('#turn-'.$lenaId);
        self::assertSame('turn__card turn__card--you', $yourCard->attr('class'));
        self::assertCount(12, $yourCard->filter('.turn__row'));
    }

    /**
     * Scores (base + half a point a day): Omar's question to Lena 140 (Critical answer) + 0, the check 78 + 1, the review
     * of Omar's code 50 + 1.5 (Major), and the build 18 + 10 (Normal, 20 days); added lowest first.
     */
    #[Test]
    public function cardsGoByScoreAndSayTheTaskWhyAndWait(): void
    {
        $reviewStep = $this->addStep($this->acmeFoods, 'Code review', 'check', 'review');
        $this->addLenasScoredTicket($this->doingStep, 'build', 18, 'normal', 20);
        $this->addLenasScoredTicket($reviewStep, 'review', 50, 'major', 3, assignee: 'Omar');
        $this->addLenasScoredTicket($this->doingStep, 'check', 78, 'normal', 2);
        $this->addLenasScoredTicket($this->doingStep, 'answer', 140, 'critical', 0, asker: 'Omar');
        $this->logIn($this->client, $this->people['Lena']);

        $home = $this->client->request('GET', self::HOME);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['SHOP-4', 'SHOP-3', 'SHOP-2', 'SHOP-1'], self::textsOf($home, '.home__need-key'));
        self::assertSame(['Answer Omar’s question', 'Check the new ticket', 'Review Omar’s code', 'Build it'], self::textsOf($home, '.home__need-task'));
        self::assertSame(
            ['Omar is blocked until you answer · Critical · came to you today', 'came to you on 4 Nov', 'Major · came to you on 3 Nov', 'with you for 20 days'],
            self::textsOf($home, '.home__need-reasons'),
        );
        self::assertSame(['Open ticket SHOP-4', 'Check it SHOP-3', 'Open ticket SHOP-2', 'Open ticket SHOP-1'], self::textsOf($home, '.home__need-open'));
        self::assertSame(
            ['btn btn-filled btn-sm home__need-open', 'btn btn-tonal btn-sm home__need-open', 'btn btn-tonal btn-sm home__need-open', 'btn btn-tonal btn-sm home__need-open'],
            $home->filter('.home__need-open')->each(static fn (Crawler $button): string => (string) $button->attr('class')),
        );
        self::assertSame(['today', 'waiting 2 days', 'waiting 3 days', 'waiting 20 days'], self::textsOf($home, '.home__need-wait'));
        self::assertSame('AI ORDER who is blocked, priority, release, how long it waits', $home->filter('.home__order-note')->text());
        self::assertCount(0, $home->filter('.home__section-note'));
    }

    /**
     * "Today" is Lena's calendar day in Berlin, not the last 24 hours: at 13:00 there on 6 Nov, a turn that came at 00:30
     * came today, and one that came at 23:30 on 5 Nov, 13 hours and a half ago, came yesterday.
     */
    #[Test]
    public function aTurnCameTodayOnlyOnThePersonsCalendarDay(): void
    {
        self::assertSame('Europe/Berlin', $this->people['Lena']->timeZone());
        $lenaId = $this->people['Lena']->id()->toString();
        foreach (['2026-11-05 23:30:00+00', '2026-11-05 22:30:00+00'] as $waitingSince) {
            $key = 'SHOP-'.$this->nextNumber;
            $ticketId = $this->insertTicket($this->acmeFoods, $this->shopProject, $this->shop->toString(), $key, $this->doingStep, null, null, 'normal');
            $this->insertTurnRow($this->acmeFoods, $ticketId, $this->shop->toString(), $this->shopProject, $lenaId, 0, waitingSince: $waitingSince);
        }
        $this->logIn($this->client, $this->people['Lena']);

        $home = $this->client->request('GET', self::HOME);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['SHOP-2', 'SHOP-1'], self::textsOf($home, '.home__need-key'));
        self::assertSame(['came to you on 5 Nov', 'came to you today'], self::textsOf($home, '.home__need-reasons'));
        self::assertSame(['since yesterday', 'today'], self::textsOf($home, '.home__need-wait'));
    }

    /**
     * @return iterable<string, array{int, int, string}>
     */
    public static function ticketCountsUpToSix(): iterable
    {
        yield 'exactly 5' => [5, 0, 'Nothing else waits on you.'];
        yield '6' => [6, 1, '1 more ticket waits on you.'];
    }

    #[Test]
    #[DataProvider('ticketCountsUpToSix')]
    public function seeAllShowsOnlyWhenMoreThanFiveWait(int $ticketCount, int $seeAllCount, string $alsoWaiting): void
    {
        for ($waitDays = 1; $waitDays <= $ticketCount; ++$waitDays) {
            $this->addWaitingTicket('Lena', $waitDays);
        }
        $this->logIn($this->client, $this->people['Lena']);

        $home = $this->client->request('GET', self::HOME);

        self::assertResponseStatusCodeSame(200);
        self::assertCount(5, $home->filter('.home__need'));
        self::assertCount($seeAllCount, $home->filter('.home__see-all'));
        self::assertCount($seeAllCount, $home->filter('.home__section-note'));
        self::assertSame($alsoWaiting, $home->filter('section[aria-labelledby="home-also-waiting"] .home__section-header ~ *')->text());
    }

    /**
     * Web_UI may not import Ticketing's UI, so Home keeps its own copies of the route names and the open query; the
     * See all test proves the open query and the card id by following the link.
     */
    #[Test]
    public function homesRouteNamesAreTheRoutesOfTicketingsPages(): void
    {
        $routes = self::getContainer()->get('router')->getRouteCollection();

        self::assertSame(
            [WhoseTurnController::class.'::show', TicketShowController::class.'::show'],
            [$routes->get(HomeController::WHOSE_TURN_ROUTE)?->getDefault('_controller'), $routes->get(HomeController::TICKET_ROUTE)?->getDefault('_controller')],
        );
    }

    /**
     * Every ticket has an assignee, so each card's names are read in both cases.
     */
    #[Test]
    public function homeRunsTheSameQueriesForTwoTicketsAndForTwelve(): void
    {
        $this->addWaitingTicket('Lena', 2, 'Omar');
        $this->addWaitingTicket('Lena', 3, 'Omar');
        $this->logIn($this->client, $this->people['Lena']);
        // The first request skips the user refresh every later one pays, and fills the team people's cache.
        $this->queriesOf(self::HOME);
        $twoTicketsQueries = $this->queriesOf(self::HOME);
        self::assertCount(2, $this->client->getCrawler()->filter('.home__need'));
        for ($waitDays = 4; $waitDays <= 13; ++$waitDays) {
            $this->addWaitingTicket('Lena', $waitDays, 'Omar');
        }

        $twelveTicketsQueries = $this->queriesOf(self::HOME);

        self::assertCount(5, $this->client->getCrawler()->filter('.home__need'));
        self::assertSame('12 tickets wait on you, Lena', $this->client->getCrawler()->filter('h1')->text());
        self::assertNotSame([], $twoTicketsQueries);
        self::assertSame(\count($twoTicketsQueries), \count($twelveTicketsQueries));
    }

    #[Test]
    public function yourTeamShowsAStackPerTeamByNameWithItsPeopleAndLink(): void
    {
        $this->logIn($this->client, $this->people['Lena']);

        $home = $this->client->request('GET', self::HOME);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Mobile', 'Shop'], self::textsOf($home, '.home__team-stack-name'));
        self::assertSame(['1 person · see who has what', '2 people · see who has what'], self::textsOf($home, '.home__team-link'));
        self::assertSame(['/teams/mobile', '/teams/shop'], $home->filter('.home__team-link')->each(static fn (Crawler $link): string => (string) $link->attr('href')));
        self::assertSame(['Lena', 'Omar'], $home->filter('.home__team-stack')->eq(1)->filter('.av')->each(static fn (Crawler $avatar): string => (string) $avatar->attr('aria-label')));
    }

    #[Test]
    public function aPersonOfOneTeamGetsItsStackWithoutItsName(): void
    {
        $this->logIn($this->client, $this->people['Omar']);

        $home = $this->client->request('GET', self::HOME);

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $home->filter('.home__team-stack'));
        self::assertCount(0, $home->filter('.home__team-stack-name'));
        self::assertSame('2 people · see who has what', $home->filter('.home__team-link')->text());
        self::assertSame('/teams/shop', $home->filter('.home__team-link')->attr('href'));
    }

    #[Test]
    public function yourTeamShowsAtMostThreeTeams(): void
    {
        $this->createTeam($this->acmeFoods, 'Billing', $this->people['Lena']);
        $this->createTeam($this->acmeFoods, 'Apps', $this->people['Lena']);
        $this->logIn($this->client, $this->people['Lena']);

        $home = $this->client->request('GET', self::HOME);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Apps', 'Billing', 'Mobile'], self::textsOf($home, '.home__team-stack-name'));
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
     * A SHOP ticket of Shop's team, reported by Lena, and its turn row: waiting on $turnPerson, or on the label
     * "Customer" for null, for $waitDays whole days and an hour, a build of a Major ticket in progress.
     *
     * @return string the ticket's key
     */
    private function addWaitingTicket(?string $turnPerson, int $waitDays, ?string $assignee = null): string
    {
        $key = 'SHOP-'.$this->nextNumber;
        $assigneeId = null === $assignee ? null : $this->people[$assignee]->id()->toString();
        $ticketId = $this->insertTicket($this->acmeFoods, $this->shopProject, $this->shop->toString(), $key, $this->doingStep, $assigneeId, null);
        $turnUserId = null === $turnPerson ? null : $this->people[$turnPerson]->id()->toString();
        $this->insertTurnRow($this->acmeFoods, $ticketId, $this->shop->toString(), $this->shopProject, $turnUserId, $waitDays, assigneeId: $assigneeId);

        return $key;
    }

    /**
     * A SHOP ticket of Shop's team in the step, reported by Lena, whose turn row waits on Lena with the kind and base
     * score; with $asker, it holds an open question from that person to Lena.
     *
     * @return string the ticket's key
     */
    private function addLenasScoredTicket(string $stepId, string $kind, int $baseScore, string $priority, int $waitDays, ?string $asker = null, ?string $assignee = null): string
    {
        $key = 'SHOP-'.$this->nextNumber;
        $lenaId = $this->people['Lena']->id()->toString();
        $assigneeId = null === $assignee ? null : $this->people[$assignee]->id()->toString();
        $ticketId = $this->insertTicket($this->acmeFoods, $this->shopProject, $this->shop->toString(), $key, $stepId, $assigneeId, null, $priority);
        if (null !== $asker) {
            $askerId = $this->people[$asker]->id()->toString();
            $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
                'UPDATE tkt_ticket SET asked_user_id = ?, asker_id = ? WHERE id = ?',
                [$lenaId, $askerId, $ticketId],
            ));
        }
        $this->insertTurnRow($this->acmeFoods, $ticketId, $this->shop->toString(), $this->shopProject, $lenaId, $waitDays, $kind, $baseScore, $assigneeId);

        return $key;
    }

    /**
     * Done tickets have no turn row; one assigned to Lena must still not wait on her.
     */
    private function addDoneTicketAssignedToLena(): void
    {
        $doneStep = $this->addStep($this->acmeFoods, 'Done', 'done', 'done');
        $this->insertTicket($this->acmeFoods, $this->shopProject, $this->shop->toString(), 'SHOP-'.$this->nextNumber, $doneStep, $this->people['Lena']->id()->toString(), '2026-11-01 09:00:00+00');
    }

    private function addBetaBakeryTicketWaitingOnLena(): void
    {
        $betaBakery = $this->createOrganization('Beta Bakery');
        $projectId = $this->addProject(null, 'BAKE', 'Bakery', $betaBakery);
        $stepId = $this->addStep($betaBakery, 'In progress', 'doing', 'progress');
        $ticketId = $this->insertTicket($betaBakery, $projectId, null, 'BAKE-1', $stepId, null, null);
        $this->insertTurnRow($betaBakery, $ticketId, null, $projectId, $this->people['Lena']->id()->toString(), 50);
    }

    /**
     * @return string the ticket's id
     */
    private function insertTicket(OrganizationId $organizationId, string $projectId, ?string $teamId, string $key, string $stepId, ?string $assigneeId, ?string $closedAt, string $priority = 'major'): string
    {
        $number = $this->nextNumber++;
        $ticketId = Uuid::v7()->toRfc4122();
        $priorityRank = ['blocker' => 0, 'critical' => 1, 'major' => 2, 'normal' => 3, 'minor' => 4][$priority];
        $values = [$organizationId->toString(), $ticketId, $projectId, $teamId, $number, $key, 'Ticket '.$key, $priority, $priorityRank, $stepId, $this->people['Lena']->id()->toString(), $assigneeId, $closedAt];
        $this->committedAs($organizationId, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO tkt_ticket (organization_id, id, project_id, team_id, number, key, title, description, type, priority,
                 priority_rank, step_id, reporter_id, visibility, assignee_id, closed_at, components, check_reasons, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, '', 'bug', ?, ?, ?, ?, 'all', ?, CAST(? AS timestamptz), '{}', '{}', TIMESTAMPTZ '2026-08-01 09:00:00+00', TIMESTAMPTZ '2026-08-01 09:00:00+00')",
            $values,
        ));

        return $ticketId;
    }

    /**
     * @param string|null $waitingSince when the turn came, instead of $waitDays and an hour before NOW
     */
    private function insertTurnRow(OrganizationId $organizationId, string $ticketId, ?string $teamId, string $projectId, ?string $turnUserId, int $waitDays, string $kind = 'build', int $baseScore = 100, ?string $assigneeId = null, ?string $waitingSince = null): void
    {
        $waitingSince ??= new \DateTimeImmutable(self::NOW)->modify(\sprintf('-%d days -1 hour', $waitDays))->format('Y-m-d H:i:sP');
        $values = [$organizationId->toString(), $ticketId, $teamId, $projectId, $turnUserId, null === $turnUserId ? 'Customer' : null, $kind, $baseScore, $waitingSince, $this->people['Lena']->id()->toString(), $assigneeId];
        $this->committedAs($organizationId, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO ins_ticket_turn (organization_id, ticket_id, team_id, project_id, grp, turn_user_id, turn_label, kind, base_score, waiting_since, visibility, reporter_id, assignee_id)
             VALUES (?, ?, ?, ?, 'doing', ?, ?, ?, ?, CAST(? AS timestamptz), 'all', ?, ?)",
            $values,
        ));
    }
}
