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
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * The whose-turn view (FR-TKT-03, FR-TKT-18) on Acme Foods' project SHOP of team Shop, through the real firewall,
 * voters and database. The clock is fixed on 6 Nov 2026, before today: a clock ahead of the login would end the
 * session as idle. Lena leads Shop and Omar is a member; Raj, a manager of no team, is outside it.
 */
final class WhoseTurnTest extends WebTestCase
{
    use CreatesAcmeFoodsTeams;
    use CreatesTeams;
    use Factories;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }

    private const string NOW = '2026-11-06 12:00:00 UTC';
    private const string TURN = '/tickets/turn';
    private const string SHELL = '/_test/menu/shell';
    /** A refused viewer must never get a word of it. */
    private const string HIDDEN_TITLE = 'Price rules for staff';

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
        $this->doingStep = $this->addDoingStep();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->committedAs(new OrganizationId($organizationId), static function (Connection $migrator) use ($organizationId): int {
                foreach (['ins_ticket_turn', 'ins_project_count_ticket', 'ins_project_counts', 'tkt_ticket', 'wf_step'] as $table) {
                    $migrator->executeStatement(\sprintf('DELETE FROM %s WHERE organization_id = ?', $table), [$organizationId]);
                }

                return 0;
            });
        }
        $this->removeCommittedProjects();
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function theViewersCardComesFirstThenTheOldestWaitWithTheirSentencesAndVerbs(): void
    {
        $this->addWaitingTicket('Omar', 'review', 12, 'Search shows no results');
        $this->addWaitingTicket('Raj', 'decide', 5, 'Coupon field takes any text');
        $this->addWaitingTicket('Lena', 'answer', 2, 'Cart total shows the wrong sum');
        $this->addWaitingTicket(null, 'input', 41, 'Add a gift note');
        $this->addWaitingTicket('Omar', 'test', 0, 'Checkout button is hidden');
        $this->logIn($this->client, $this->people['Lena']);

        $page = $this->client->request('GET', self::TURN);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['You', 'Customer', 'Omar', 'Raj'], $page->filter('.turn__name')->each(static fn (Crawler $name): string => $name->text()));
        self::assertSame([
            '1 ticket waits on you · for 2 days',
            '1 ticket waits on Customer · for 41 days · outside the team',
            '2 tickets wait on Omar · the oldest for 12 days',
            '1 ticket waits on Raj · for 5 days · outside the team',
        ], $page->filter('.turn__sentence')->each(static fn (Crawler $sentence): string => $sentence->text()));
        self::assertSame(['you', 'outside', 'team', 'outside'], $page->filter('.turn__card')->each(
            static fn (Crawler $card): string => substr((string) $card->attr('class'), \strlen('turn__card turn__card--')),
        ));
        self::assertSame(
            ['Review the code · SHOP-1 Search shows no results', 'Test it · SHOP-5 Checkout button is hidden'],
            $page->filter('.turn__card')->eq(2)->filter('.turn__row-link')->each(static fn (Crawler $row): string => $row->text()),
        );
        self::assertSame(['12 days', 'today'], $page->filter('.turn__card')->eq(2)->filter('.turn__wait')->each(static fn (Crawler $wait): string => $wait->text()));
        self::assertSame('5 tickets · grouped by who acts next', $page->filter('.tickets__total')->text());
        self::assertSame('Each ticket sits with the one person who must act next. Longest waits first. Done tickets are left out.', $page->filter('.turn__intro')->text());
        self::assertSame('/t/SHOP-1', $page->filter('.turn__row-link')->eq(2)->attr('href'));
    }

    /**
     * @return iterable<string, array{int, string, string, bool}>
     */
    public static function oldestWaits(): iterable
    {
        yield '10 days is still calm' => [10, '10', 'calm', false];
        yield '11 days is amber' => [11, '11', 'busy', false];
        yield '30 days is still amber' => [30, '30', 'busy', false];
        yield '31 days is red' => [31, '31', 'too-much', true];
        yield '60 days fills the meter' => [60, '60', 'too-much', true];
        yield '75 days still fills it' => [75, '60', 'too-much', true];
    }

    #[Test]
    #[DataProvider('oldestWaits')]
    public function theMeterFillsAtSixtyDaysAndTurnsAmberAfterTenAndRedAfterThirty(int $waitDays, string $meterValue, string $meterLevel, bool $isWaitTooLong): void
    {
        $this->addWaitingTicket('Omar', 'build', $waitDays, 'Search shows no results');
        $this->logIn($this->client, $this->people['Lena']);

        $page = $this->client->request('GET', self::TURN);

        self::assertResponseStatusCodeSame(200);
        $meter = $page->filter('.turn__meter');
        self::assertCount(1, $meter);
        self::assertSame('60', $meter->attr('max'));
        self::assertSame($meterValue, $meter->attr('value'));
        self::assertSame('turn__meter turn__meter--'.$meterLevel, $meter->attr('class'));
        self::assertSame('Oldest wait: '.$waitDays.' days', $meter->attr('title'));
        self::assertSame($isWaitTooLong, str_contains((string) $page->filter('.turn__wait')->attr('class'), 'turn__wait--too-long'));
    }

    #[Test]
    public function showMoreOpensOneCardToAllItsTicketsAndShowFewerClosesIt(): void
    {
        foreach ([3, 9, 1, 7, 5, 2, 8] as $waitDays) {
            $this->addWaitingTicket('Omar', 'build', $waitDays, 'Omar waits '.$waitDays);
        }
        foreach ([4, 6, 10, 11, 12, 13] as $waitDays) {
            $this->addWaitingTicket('Lena', 'review', $waitDays, 'Lena waits '.$waitDays);
        }
        $this->logIn($this->client, $this->people['Lena']);

        $page = $this->client->request('GET', self::TURN.'?project=SHOP');

        self::assertResponseStatusCodeSame(200);
        $omarsCard = $page->filter('.turn__card')->eq(1);
        self::assertSame(['9 days', '8 days', '7 days', '5 days', '3 days'], $omarsCard->filter('.turn__wait')->each(static fn (Crawler $wait): string => $wait->text()));
        self::assertSame('Show 2 more', $omarsCard->filter('.turn__more')->text());
        self::assertSame('Show 1 more', $page->filter('.turn__card')->eq(0)->filter('.turn__more')->text());
        $openUrl = (string) $omarsCard->filter('.turn__more')->attr('href');
        self::assertSame(self::TURN.'?project=SHOP&open='.$this->people['Omar']->id()->toString(), $openUrl);

        $opened = $this->client->request('GET', $openUrl);

        self::assertResponseStatusCodeSame(200);
        $openedCard = $opened->filter('.turn__card')->eq(1);
        self::assertSame(['9 days', '8 days', '7 days', '5 days', '3 days', '2 days', '1 day'], $openedCard->filter('.turn__wait')->each(static fn (Crawler $wait): string => $wait->text()));
        self::assertSame('Show fewer', $openedCard->filter('.turn__more')->text());
        self::assertSame(self::TURN.'?project=SHOP', $openedCard->filter('.turn__more')->attr('href'));
        self::assertCount(5, $opened->filter('.turn__card')->eq(0)->filter('.turn__row'));
        self::assertSame('Show 1 more', $opened->filter('.turn__card')->eq(0)->filter('.turn__more')->text());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedQueries(): iterable
    {
        yield 'a project key of no project' => ['?project=NOPE'];
        yield 'an opened group of 101 characters' => ['?open='.str_repeat('a', 101)];
        yield 'an opened group that is not UTF-8' => ['?open=%FF'];
    }

    #[Test]
    #[DataProvider('refusedQueries')]
    public function aQueryThePageRefusesIsNotFound(string $query): void
    {
        $this->addWaitingTicket('Omar', 'build', 3, self::HIDDEN_TITLE);
        $this->logIn($this->client, $this->people['Lena']);

        $this->client->request('GET', self::TURN.$query);

        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString(self::HIDDEN_TITLE, (string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function anOpenedGroupOfAHundredCharactersOpensNoneAndShowsThePage(): void
    {
        $this->addWaitingTicket('Omar', 'build', 3, 'Search shows no results');
        $this->logIn($this->client, $this->people['Lena']);

        $page = $this->client->request('GET', self::TURN.'?open='.str_repeat('a', 100));

        self::assertResponseStatusCodeSame(200);
        self::assertSame('1 ticket waits on Omar · for 3 days', $page->filter('.turn__sentence')->text());
    }

    #[Test]
    public function aViewerOutsideTheTeamCountsNoTeamOnlyTicket(): void
    {
        $this->addWaitingTicket('Omar', 'build', 3, 'Search shows no results');
        $this->addWaitingTicket('Omar', 'build', 40, self::HIDDEN_TITLE, 'team');
        $this->logIn($this->client, $this->people['Lena']);
        $lenasPage = $this->client->request('GET', self::TURN);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('2 tickets wait on Omar · the oldest for 40 days', $lenasPage->filter('.turn__sentence')->text());
        $this->logIn($this->client, $this->people['Raj']);

        $page = $this->client->request('GET', self::TURN);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['1 ticket waits on Omar · for 3 days'], $page->filter('.turn__sentence')->each(static fn (Crawler $sentence): string => $sentence->text()));
        self::assertSame('3', $page->filter('.turn__meter')->attr('value'));
        self::assertSame('1 ticket · grouped by who acts next', $page->filter('.tickets__total')->text());
        self::assertStringNotContainsString(self::HIDDEN_TITLE, (string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function aPersonOfAnotherOrganizationSeesNoneOfItsTickets(): void
    {
        $this->addWaitingTicket('Omar', 'build', 3, self::HIDDEN_TITLE);
        $betaBakery = $this->createOrganization('Beta Bakery');
        $ben = $this->createUserWithoutOrganization('Ben');
        $this->addMembership($ben, $betaBakery);
        $this->logIn($this->client, $ben);

        $page = $this->client->request('GET', self::TURN);

        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $page->filter('.turn__card'));
        self::assertSame('No tickets here yet.', $page->filter('.empty-state')->text());
        self::assertSame('0 tickets · grouped by who acts next', $page->filter('.tickets__total')->text());
        self::assertStringNotContainsString(self::HIDDEN_TITLE, (string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function anAnonymousVisitorIsSentToLogIn(): void
    {
        $this->client->request('GET', self::TURN);

        self::assertResponseStatusCodeSame(302);
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    #[Test]
    public function theListAndThisPageSwitchViewsKeepingTheProject(): void
    {
        $this->logIn($this->client, $this->people['Lena']);

        $list = $this->client->request('GET', '/tickets?project=SHOP&q=cart');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['List', 'Whose turn?'], $list->filter('.tickets__header a.seg')->each(static fn (Crawler $link): string => $link->text()));
        self::assertSame('List', $list->filter('.tickets__header a.seg[aria-current="page"]')->text());
        self::assertSame(self::TURN.'?project=SHOP', $list->filter('.tickets__header a.seg')->eq(1)->attr('href'));

        $page = $this->client->request('GET', self::TURN.'?project=SHOP');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Whose turn?', $page->filter('.tickets__header a.seg[aria-current="page"]')->text());
        self::assertSame('/tickets?project=SHOP', $page->filter('.tickets__header a.seg')->eq(0)->attr('href'));
        self::assertSame('SHOP · Shop', $page->filter('.tickets__title')->text());
        self::assertSame('No tickets here yet.', $page->filter('.empty-state')->text());
    }

    #[Test]
    public function thePageRunsTheSameQueriesForTwoCardsAndForSix(): void
    {
        $this->addWaitingTicket('Lena', 'review', 2, 'Cart total shows the wrong sum');
        $this->addWaitingTicket('Omar', 'build', 3, 'Search shows no results');
        $this->logIn($this->client, $this->people['Lena']);
        // logIn() leaves the user in the entity manager, so the first request skips the user refresh every later one pays.
        $this->queryCountOf(self::SHELL);
        $shellQueryCount = $this->queryCountOf(self::SHELL);
        $this->queryCountOf(self::TURN);
        $twoCardsQueryCount = $this->queryCountOf(self::TURN) - $shellQueryCount;
        self::assertCount(2, $this->client->getCrawler()->filter('.turn__card'));
        $this->addWaitingTicket('Mia', 'test', 4, 'Coupon field takes any text');
        $this->addWaitingTicket('Raj', 'decide', 5, 'Add a gift note');
        $this->addWaitingTicket('Sara', 'assign', 6, 'Checkout button is hidden');
        $this->addWaitingTicket(null, 'input', 7, 'Price shows in the wrong currency');

        $sixCardsQueryCount = $this->queryCountOf(self::TURN) - $shellQueryCount;

        self::assertCount(6, $this->client->getCrawler()->filter('.turn__card'));
        self::assertSame($twoCardsQueryCount, $sixCardsQueryCount);
        // The triager (RoleTicketVisibilities), the cards, and every card's names; the team's people are cached.
        self::assertSame(3, $sixCardsQueryCount);
    }

    private function queryCountOf(string $uri): int
    {
        $debugData = self::getContainer()->get('doctrine.debug_data_holder');
        $debugData->reset();
        $this->client->request('GET', $uri);
        self::assertResponseStatusCodeSame(200);

        $queryCount = 0;
        foreach ($debugData->getData() as $queriesOfOneConnection) {
            $queryCount += \count($queriesOfOneConnection);
        }

        return $queryCount;
    }

    private function addDoingStep(): string
    {
        $organizationId = $this->acmeFoods->toString();
        $stepId = Uuid::v7()->toRfc4122();
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, 'In progress', 'doing', 'progress', 'core', 0)",
            [$organizationId, $stepId],
        ));

        return $stepId;
    }

    /**
     * A major bug of Shop's team in SHOP, reported by Lena, and its turn row: waiting on $turnPerson, or on the label
     * "Customer" for null, for $waitDays whole days and an hour.
     */
    private function addWaitingTicket(?string $turnPerson, string $kind, int $waitDays, string $title, string $visibility = 'all'): void
    {
        $number = $this->nextNumber++;
        $ticketId = Uuid::v7()->toRfc4122();
        $organizationId = $this->acmeFoods->toString();
        $shopTeam = $this->shop->toString();
        $lena = $this->people['Lena']->id()->toString();
        $turnUserId = null === $turnPerson ? null : $this->people[$turnPerson]->id()->toString();
        $waitingSince = new \DateTimeImmutable(self::NOW)->modify(\sprintf('-%d days -1 hour', $waitDays))->format('Y-m-d H:i:sP');
        $projectId = $this->shopProject;
        $stepId = $this->doingStep;
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId, $ticketId, $projectId, $shopTeam, $number, $title, $stepId, $lena, $visibility, $turnUserId, $kind, $waitingSince): int {
            $migrator->executeStatement(
                "INSERT INTO tkt_ticket (organization_id, id, project_id, team_id, number, key, title, description, type, priority,
                     priority_rank, step_id, reporter_id, visibility, assignee_id, components, check_reasons, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, '', 'bug', 'major', 2, ?, ?, ?, NULL, '{}', '{}', TIMESTAMPTZ '2026-08-01 09:00:00+00', TIMESTAMPTZ '2026-08-01 09:00:00+00')",
                [$organizationId, $ticketId, $projectId, $shopTeam, $number, 'SHOP-'.$number, $title, $stepId, $lena, $visibility],
            );
            $migrator->executeStatement(
                "INSERT INTO ins_ticket_turn (organization_id, ticket_id, team_id, project_id, grp, turn_user_id, turn_label, kind, base_score, waiting_since, visibility, reporter_id)
                 VALUES (?, ?, ?, ?, 'doing', ?, ?, ?, 100, CAST(? AS timestamptz), ?, ?)",
                [$organizationId, $ticketId, $shopTeam, $projectId, $turnUserId, null === $turnUserId ? 'Customer' : null, $kind, $waitingSince, $visibility, $lena],
            );

            return 0;
        });
    }
}
