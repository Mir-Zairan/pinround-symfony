<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ticketing;

use App\Identity\Domain\User;
use App\Organization\Infrastructure\Cache\TeamPeopleCache;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use App\Ticketing\UI\TicketListController;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
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
    private const string SHELL = '/_test/menu/shell';
    /** The project rail's reads: the team names, the team's projects and their counts. */
    private const string RAIL_QUERIES = '/FROM org_team WHERE organization_id = \? AND id = ANY|FROM tkt_project WHERE organization_id = \? AND team_id = ANY|FROM ins_project_counts/';
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
    public function anOlderUpdateShowsItsDateInTheViewersSavedTimeZone(): void
    {
        // 23:30 UTC on the 12th is already the 13th in Lena's Europe/Berlin (the test users' zone); 2020 keeps the year suffix whatever today is.
        $this->addTicket('todo', 'Add a gift note', 'normal', ['updatedAt' => '2020-03-12 23:30:00+00']);
        $this->logIn($this->client, $this->people['Lena']);

        $page = $this->client->request('GET', self::TICKETS);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('13 Mar 2020', $page->filter('[data-ticket-key="SHOP-1"] .tickets__updated')->text());
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
     * A row opens its ticket with every filter the list was asked for, so the ticket page walks this same list; it
     * leaves the list's Turbo frame for the whole page. The plain list's rows carry no query.
     */
    #[Test]
    public function aRowLinksToItsTicketWithTheListsFilters(): void
    {
        $this->addTicket('todo', 'Add a gift note', 'normal', ['assignee' => 'Omar']);
        $this->addTicket('todo', 'Add a gift wrap', 'normal');
        $this->logIn($this->client, $this->people['Lena']);
        $omar = $this->people['Omar']->id()->toString();
        $team = $this->shop->toString();
        $plain = $this->client->request('GET', self::TICKETS);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['/t/SHOP-2', '/t/SHOP-1'], $plain->filter('.tickets__row a.tickets__row-link')->each(static fn (Crawler $link): ?string => $link->attr('href')));
        self::assertSame(['false'], $plain->filter('ul.tickets__row-list')->each(static fn (Crawler $rows): ?string => $rows->attr('data-turbo-prefetch')));

        $page = $this->client->request('GET', self::TICKETS.'?team='.$team.'&q=gift&done=1&person='.$omar.'&project=SHOP');

        self::assertResponseStatusCodeSame(200);
        $link = $page->filter('[data-ticket-key="SHOP-1"] a.tickets__row-link');
        self::assertSame(
            ['/t/SHOP-1?project=SHOP&person='.$omar.'&done=1&q=gift&team='.$team, '_top', 'Add a gift note'],
            [$link->attr('href'), $link->attr('data-turbo-frame'), $link->text()],
        );
        self::assertCount(1, $page->filter('.tickets__row'));
    }

    /**
     * The rows of a "Show more" page sit in that page's own frame, so their links still break out to the whole page.
     */
    #[Test]
    public function aRowOfAShowMorePageLinksOutOfItsFrameWithTheFilters(): void
    {
        $this->addManyTickets('todo', 52);
        $this->logIn($this->client, $this->people['Lena']);
        $page = $this->client->request('GET', self::TICKETS.'?project=SHOP');
        self::assertResponseStatusCodeSame(200);
        $frameId = (string) $page->filter('[data-group="todo"] turbo-frame')->attr('id');

        $frame = $this->client->request('GET', (string) $page->filter('[data-group="todo"] [data-step-more]')->attr('href'), server: ['HTTP_TURBO_FRAME' => $frameId]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            [['/t/SHOP-2?project=SHOP', '_top'], ['/t/SHOP-1?project=SHOP', '_top']],
            $frame->filter('turbo-frame#'.$frameId.' a.tickets__row-link')->each(static fn (Crawler $link): array => [$link->attr('href'), $link->attr('data-turbo-frame')]),
        );
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
        yield 'a search cursor without the search' => ['step=0197a3c0-0000-7000-8000-00000000f0d0&after=3.1805000000.0197a3c0-0000-7000-8000-000000000001.0_0.169808963686'];
        yield 'an old ordinal match position with the search' => ['q=checkout&step=0197a3c0-0000-7000-8000-00000000f0d0&after=3.1805000000.0197a3c0-0000-7000-8000-000000000001.7'];
        yield 'a priority cursor with a search' => ['q=checkout&step=0197a3c0-0000-7000-8000-00000000f0d0&after=3.1805000000.0197a3c0-0000-7000-8000-000000000001'];
        yield 'a search as an array' => ['q[]=checkout'];
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
    public function aCollapsedStepStaysCollapsedOnTheNextRequestAndOtherStepsStayOpen(): void
    {
        $this->addTicket('todo', 'Add a gift note', 'normal');
        $this->addTicket('doing', 'Cart total shows the wrong sum', 'normal');
        $this->logIn($this->client, $this->people['Lena']);
        $page = $this->client->request('GET', self::TICKETS);
        self::assertSame(['true', 'true'], $page->filter('.tickets__step-toggle')->each(static fn (Crawler $toggle): ?string => $toggle->attr('aria-expanded')));

        $this->collapse($page, 'todo', '1');

        self::assertResponseStatusCodeSame(204);
        $reloaded = $this->client->request('GET', self::TICKETS);
        self::assertSame('false', $reloaded->filter('[data-group="todo"] .tickets__step-toggle')->attr('aria-expanded'));
        self::assertNotNull($reloaded->filter('[data-group="todo"] .tickets__rows')->getNode(0)?->attributes?->getNamedItem('hidden'));
        self::assertSame('true', $reloaded->filter('[data-group="doing"] .tickets__step-toggle')->attr('aria-expanded'));
        self::assertNull($reloaded->filter('[data-group="doing"] .tickets__rows')->getNode(0)?->attributes?->getNamedItem('hidden'));

        $this->collapse($reloaded, 'todo', '0');

        self::assertResponseStatusCodeSame(204);
        $expanded = $this->client->request('GET', self::TICKETS);
        self::assertSame('true', $expanded->filter('[data-group="todo"] .tickets__step-toggle')->attr('aria-expanded'));
    }

    #[Test]
    public function collapsingNeedsTheCsrfTokenAndARecognisedValue(): void
    {
        $this->addTicket('todo', 'Add a gift note', 'normal');
        $this->logIn($this->client, $this->people['Lena']);
        $page = $this->client->request('GET', self::TICKETS);
        $url = (string) $page->filter('[data-group="todo"]')->attr('data-step-collapse-url-value');

        $this->client->request('POST', $url, ['collapsed' => '1']);
        self::assertResponseStatusCodeSame(422);
        $this->client->request('POST', $url, ['collapsed' => '1', '_csrf_token' => 'forged']);
        self::assertResponseStatusCodeSame(422);
        $this->collapse($page, 'todo', 'maybe');
        self::assertResponseStatusCodeSame(422);

        $reloaded = $this->client->request('GET', self::TICKETS);
        self::assertSame('true', $reloaded->filter('[data-group="todo"] .tickets__step-toggle')->attr('aria-expanded'));
    }

    #[Test]
    public function collapsingByAnonymousVisitorIsRedirectedToLogin(): void
    {
        $this->client->request('POST', '/tickets/steps/'.Uuid::v7()->toRfc4122().'/collapse', ['collapsed' => '1']);

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    #[Test]
    public function collapsingAStepOfAnotherOrganizationNeedsItsOwnTokenAndChangesNothingForTheirPeople(): void
    {
        $betaBakery = $this->createOrganization('Beta Bakery');
        $ben = $this->createUserWithoutOrganization('Ben');
        $this->addMembership($ben, $betaBakery);
        $this->logIn($this->client, $ben);
        $page = $this->client->request('GET', self::TICKETS);
        self::assertCount(0, $page->filter('[data-step-collapse-token-value]'));

        $this->client->request('POST', '/tickets/steps/'.$this->steps['todo'].'/collapse', ['collapsed' => '1', '_csrf_token' => 'forged']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('The page was open too long. Try again.', $this->client->getResponse()->getContent());
        $this->logIn($this->client, $this->people['Lena']);
        $lenasPage = $this->client->request('GET', self::TICKETS);
        self::assertSame(0, $lenasPage->filter('.tickets__step-toggle[aria-expanded="false"]')->count());
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

        $frameId = $page->filter('[data-group="todo"] turbo-frame')->attr('id');
        $nextPage = $this->client->click($moreLink->link());

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['todo'], $nextPage->filter('.tickets__step')->each(static fn (Crawler $step): ?string => $step->attr('data-group')));
        // The oldest two by updated_at, newest first, after the 50 the first page showed.
        self::assertSame(['SHOP-3', 'SHOP-2'], $nextPage->filter('.tickets__row')->each(static fn (Crawler $row): ?string => $row->attr('data-ticket-key')));
        self::assertCount(0, $nextPage->filter('[data-step-more]'));
        self::assertMatchesRegularExpression('/^step-[0-9a-f-]{36}-after-[0-9a-f]{16}$/', (string) $frameId);
        self::assertSame($frameId, $nextPage->filter('[data-group="todo"] .tickets__rows > turbo-frame')->attr('id'));
        self::assertSame(2, $nextPage->filter('turbo-frame#'.$frameId.' .tickets__row')->count());
        // Focus moves to the new rows after Turbo swaps the frame; the first page takes none.
        self::assertCount(1, $nextPage->filter('turbo-frame#'.$frameId.' ul.tickets__row-list[tabindex="-1"][autofocus]'));
        self::assertCount(0, $page->filter('[autofocus]'));
    }

    /**
     * Turbo asks for Show more's URL as a request for the more frame, which it swaps for the frame of the same id in
     * the answer; that frame holds the next rows, and nothing of the page around it comes back.
     */
    #[Test]
    public function showMoreAsAFrameRequestGetsTheNextRowsInThatFrameAlone(): void
    {
        $this->addManyTickets('todo', 52);
        $this->logIn($this->client, $this->people['Lena']);
        $page = $this->client->request('GET', self::TICKETS.'?project=SHOP');
        self::assertResponseStatusCodeSame(200);
        $moreLink = $page->filter('[data-group="todo"] [data-step-more]');
        $frameId = (string) $page->filter('[data-group="todo"] turbo-frame')->attr('id');
        self::assertMatchesRegularExpression('/^step-[0-9a-f-]{36}-after-[0-9a-f]{16}$/', $frameId);

        $frame = $this->client->request('GET', (string) $moreLink->attr('href'), server: ['HTTP_TURBO_FRAME' => $frameId]);

        self::assertResponseStatusCodeSame(200);
        // The oldest two by updated_at, newest first, after the 50 the first page showed.
        self::assertSame(['SHOP-2', 'SHOP-1'], $frame->filter('turbo-frame#'.$frameId.' .tickets__row')->each(static fn (Crawler $row): ?string => $row->attr('data-ticket-key')));
        self::assertCount(2, $frame->filter('.tickets__row'));
        self::assertCount(1, $frame->filter('turbo-frame#'.$frameId.' ul.tickets__row-list[tabindex="-1"][autofocus]'));
        self::assertCount(0, $frame->filter('.ticket-filters, nav, header, title'));
    }

    /**
     * SHOP-40 matches by its title, is a blocker and newer, so the priority order would list it first; the exact key
     * comes first. SHOP-42, in the same step, does not match; the In progress tickets do not either.
     */
    #[Test]
    public function aSearchForAKeyShowsThatTicketFirstInItsStepAndOnlyMatches(): void
    {
        $this->addManyTickets('doing', 39);
        $this->addTicket('todo', 'Shop 41 sale', 'blocker');
        $this->addTicket('todo', 'Add a gift note', 'normal');
        $this->addTicket('todo', 'Add a gift wrap', 'normal');
        $this->logIn($this->client, $this->people['Lena']);
        $unsearched = $this->client->request('GET', self::TICKETS);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['SHOP-40', 'SHOP-42', 'SHOP-41'], $unsearched->filter('[data-group="todo"] .tickets__row')->each(static fn (Crawler $row): ?string => $row->attr('data-ticket-key')));

        $page = $this->client->request('GET', self::TICKETS.'?q=+shop-41+');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['todo'], $page->filter('.tickets__step')->each(static fn (Crawler $step): ?string => $step->attr('data-group')));
        self::assertSame(['SHOP-41', 'SHOP-40'], $page->filter('.tickets__row')->each(static fn (Crawler $row): ?string => $row->attr('data-ticket-key')));
        self::assertSame(['2', '2 tickets · grouped by step'], [$page->filter('.tickets__step-count')->text(), $page->filter('.tickets__total')->text()]);
    }

    #[Test]
    public function aSearchThatMatchesNothingSaysHowToFindMore(): void
    {
        $this->addTicket('todo', 'Add a gift note', 'normal');
        $this->logIn($this->client, $this->people['Lena']);

        $page = $this->client->request('GET', self::TICKETS.'?q=postcode');

        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $page->filter('.tickets__row'));
        self::assertSame('No tickets match. Clear the search or pick another person.', $page->filter('.empty-state')->text());
    }

    #[Test]
    public function aBlankSearchListsEveryTicket(): void
    {
        $this->addTicket('todo', 'Add a gift note', 'normal');
        $this->logIn($this->client, $this->people['Lena']);

        $page = $this->client->request('GET', self::TICKETS.'?q=+%09+');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['SHOP-1'], $page->filter('.tickets__row')->each(static fn (Crawler $row): ?string => $row->attr('data-ticket-key')));
    }

    /**
     * Omar's tickets sit in two steps among Lena's and an unassigned one, so a filter on the reporter, the turn or no
     * filter at all would list more.
     */
    #[Test]
    public function aPersonKeepsOnlyTheTicketsAssignedToThem(): void
    {
        $this->addTicket('todo', 'Add a gift note', 'normal', ['assignee' => 'Omar']);
        $this->addTicket('todo', 'Add a gift wrap', 'normal', ['assignee' => 'Lena', 'turn' => 'Omar']);
        $this->addTicket('doing', 'Cart total shows the wrong sum', 'critical', ['assignee' => 'Omar']);
        $this->addTicket('doing', 'Coupon field takes any text', 'minor');
        $this->logIn($this->client, $this->people['Lena']);
        $omar = $this->people['Omar']->id()->toString();

        $page = $this->client->request('GET', self::TICKETS.'?person='.$omar);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['SHOP-3', 'SHOP-1'], $page->filter('.tickets__row')->each(static fn (Crawler $row): ?string => $row->attr('data-ticket-key')));
        self::assertSame(['1', '1'], $page->filter('.tickets__step-count')->each(static fn (Crawler $count): string => $count->text()));
        self::assertSame('2 tickets · grouped by step', $page->filter('.tickets__total')->text());
        self::assertSame(['Omar'], $page->filter('.ticket-filters a.chip[aria-current="true"]')->each(static fn (Crawler $chip): string => $chip->text()));
    }

    #[Test]
    public function aPersonWithNoTicketHereSaysHowToFindMore(): void
    {
        $this->addTicket('todo', 'Add a gift note', 'normal', ['assignee' => 'Omar']);
        $this->logIn($this->client, $this->people['Lena']);

        $page = $this->client->request('GET', self::TICKETS.'?person='.$this->people['Mia']->id()->toString());

        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $page->filter('.tickets__row'));
        self::assertSame('No tickets match. Clear the search or pick another person.', $page->filter('.empty-state')->text());
    }

    #[Test]
    public function aPersonOutsideTheTeamFilteringByAnAssigneeStillSeesNoTeamOnlyTicket(): void
    {
        $this->addTicket('todo', 'Add a gift note', 'normal', ['assignee' => 'Omar']);
        $this->addTicket('todo', 'Price rules for staff', 'normal', ['visibility' => 'team', 'assignee' => 'Omar']);
        $this->addTicket('todo', 'Add a gift wrap', 'normal', ['assignee' => 'Lena']);
        $this->logIn($this->client, $this->people['Raj']);

        $page = $this->client->request('GET', self::TICKETS.'?person='.$this->people['Omar']->id()->toString());

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['SHOP-1'], $page->filter('.tickets__row')->each(static fn (Crawler $row): ?string => $row->attr('data-ticket-key')));
        self::assertSame('1', $page->filter('.tickets__step-count')->text());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedPeople(): iterable
    {
        yield 'a word' => ['nobody'];
        yield 'an uppercase uuid' => ['0197A3C0-0000-7000-8002-000000000001'];
        yield 'a uuid in braces' => ['%7B0197a3c0-0000-7000-8002-000000000001%7D'];
        yield 'a uuid and a NUL' => ['0197a3c0-0000-7000-8002-000000000001%00'];
        yield 'a uuid without hyphens' => ['0197a3c0000070008002000000000001'];
    }

    #[Test]
    #[DataProvider('malformedPeople')]
    public function aMalformedPersonIsNotFoundBeforeAnyTicketQuery(string $encodedPerson): void
    {
        $this->client->disableReboot();
        $this->addTicket('todo', 'Add a gift note', 'normal', ['assignee' => 'Omar']);
        $this->logIn($this->client, $this->people['Lena']);
        self::assertNotSame([], $this->ticketQueriesOf(self::TICKETS.'?person='.$this->people['Omar']->id()->toString()));
        self::assertResponseStatusCodeSame(200);

        $ticketQueries = $this->ticketQueriesOf(self::TICKETS.'?person='.$encodedPerson);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $ticketQueries);
    }

    #[Test]
    public function theShowDoneChipAddsDoneAndKeepsThePerson(): void
    {
        $this->addTicket('todo', 'Add a gift note', 'normal', ['assignee' => 'Omar']);
        $this->addTicket('done', 'Pay button is hidden on small screens', 'major', ['assignee' => 'Omar']);
        $this->addTicket('done', 'Coupon field takes any text', 'minor', ['assignee' => 'Lena']);
        $this->logIn($this->client, $this->people['Lena']);
        $omar = $this->people['Omar']->id()->toString();
        $page = $this->client->request('GET', self::TICKETS.'?person='.$omar);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['todo'], $page->filter('.tickets__step')->each(static fn (Crawler $step): ?string => $step->attr('data-group')));
        self::assertNull($page->filter('[data-done-chip]')->attr('aria-current'));

        $withDone = $this->client->click($page->filter('[data-done-chip]')->link());

        self::assertResponseStatusCodeSame(200);
        parse_str((string) parse_url($this->client->getRequest()->getUri(), \PHP_URL_QUERY), $query);
        self::assertEquals(['person' => $omar, 'done' => '1'], $query);
        self::assertSame(['SHOP-1', 'SHOP-2'], $withDone->filter('.tickets__row')->each(static fn (Crawler $row): ?string => $row->attr('data-ticket-key')));
        self::assertSame('true', $withDone->filter('[data-done-chip]')->attr('aria-current'));

        $withoutDone = $this->client->click($withDone->filter('[data-done-chip]')->link());

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['SHOP-1'], $withoutDone->filter('.tickets__row')->each(static fn (Crawler $row): ?string => $row->attr('data-ticket-key')));
    }

    /**
     * Omar is the assignee of every even ticket, Mia of every odd one: 52 of Omar's match, so his first page is 50.
     */
    #[Test]
    public function showMoreKeepsTheSearchThePersonAndDone(): void
    {
        $this->addManyTickets('todo', 104);
        $this->addTurnsAndAssignees();
        $this->logIn($this->client, $this->people['Lena']);
        $omar = $this->people['Omar']->id()->toString();

        $page = $this->client->request('GET', self::TICKETS.'?person='.$omar.'&done=1&q=checkout');

        self::assertResponseStatusCodeSame(200);
        self::assertCount(50, $page->filter('.tickets__row'));
        self::assertSame('52', $page->filter('.tickets__step-count')->text());
        $moreLink = $page->filter('[data-group="todo"] [data-step-more]');
        parse_str((string) parse_url((string) $moreLink->attr('href'), \PHP_URL_QUERY), $moreQuery);
        self::assertSame([$omar, '1', 'checkout'], [$moreQuery['person'] ?? null, $moreQuery['done'] ?? null, $moreQuery['q'] ?? null]);

        $nextPage = $this->client->click($moreLink->link());

        self::assertResponseStatusCodeSame(200);
        $nextKeys = $nextPage->filter('.tickets__row')->each(static fn (Crawler $row): ?string => $row->attr('data-ticket-key'));
        self::assertCount(2, $nextKeys);
        foreach ($nextKeys as $key) {
            self::assertSame(0, (int) substr((string) $key, 5) % 2, $key.' is not one of Omar\'s tickets.');
        }
    }

    /**
     * The best match, "Checkout", is a minor ticket updated first, so the priority order would show it last; the 52
     * others tie on the rank and come by id, highest first. The ticket without the word is not listed.
     */
    #[Test]
    public function showMoreUnderASearchKeepsTheSearchAndGoesOnInSearchOrder(): void
    {
        $this->addManyTickets('todo', 52);
        $this->addTicket('todo', 'Checkout', 'minor', ['updatedAt' => '2027-03-15 07:00:00+00']);
        $this->addTicket('todo', 'Cart total shows the wrong sum', 'blocker');
        $this->logIn($this->client, $this->people['Lena']);

        $page = $this->client->request('GET', self::TICKETS.'?project=SHOP&q=checkout');

        self::assertResponseStatusCodeSame(200);
        $firstPageKeys = $page->filter('.tickets__row')->each(static fn (Crawler $row): ?string => $row->attr('data-ticket-key'));
        self::assertCount(50, $firstPageKeys);
        self::assertSame(['SHOP-53', 'SHOP-52', 'SHOP-51'], \array_slice($firstPageKeys, 0, 3));
        self::assertSame('SHOP-4', $firstPageKeys[49]);
        self::assertSame('53', $page->filter('.tickets__step-count')->text());
        $moreLink = $page->filter('[data-group="todo"] [data-step-more]');
        parse_str((string) parse_url((string) $moreLink->attr('href'), \PHP_URL_QUERY), $moreQuery);
        self::assertSame(['SHOP', 'checkout'], [$moreQuery['project'] ?? null, $moreQuery['q'] ?? null]);
        $after = $moreQuery['after'] ?? null;
        self::assertIsString($after);
        self::assertMatchesRegularExpression('/^\d\.\d+\.[0-9a-f-]{36}\.0_0\.\d{12}\z/', $after);

        $nextPage = $this->client->click($moreLink->link());

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['SHOP-3', 'SHOP-2', 'SHOP-1'], $nextPage->filter('.tickets__row')->each(static fn (Crawler $row): ?string => $row->attr('data-ticket-key')));
        self::assertCount(0, $nextPage->filter('[data-step-more]'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedSearches(): iterable
    {
        yield '201 characters' => [rawurlencode(str_repeat('é', 201)), 'The search is too long. Use 200 characters or fewer.'];
        yield 'invalid UTF-8' => ['check%C3out', 'The search is not valid text.'];
        yield 'a NUL inside' => ['check%00out', 'The search is not valid text.'];
    }

    #[Test]
    #[DataProvider('refusedSearches')]
    public function aRefusedSearchIsABadRequestWithItsReasonBeforeAnyTicketQuery(string $encodedSearch, string $reason): void
    {
        $this->client->disableReboot();
        $this->addTicket('todo', 'Add a gift note', 'normal');
        $this->logIn($this->client, $this->people['Lena']);
        self::assertNotSame([], $this->ticketQueriesOf(self::TICKETS.'?q=gift'));
        self::assertResponseStatusCodeSame(200);

        $ticketQueries = $this->ticketQueriesOf(self::TICKETS.'?q='.$encodedSearch);

        self::assertResponseStatusCodeSame(400);
        self::assertSame($reason, $this->client->getResponse()->getContent());
        self::assertSame([], $ticketQueries);
    }

    /**
     * @return list<string> the SQL of the request's queries that read tickets, the search or the triager
     */
    private function ticketQueriesOf(string $uri): array
    {
        $debugData = self::getContainer()->get('doctrine.debug_data_holder');
        $debugData->reset();
        $this->client->request('GET', $uri);

        $ticketQueries = [];
        foreach ($debugData->getData() as $queriesOfOneConnection) {
            foreach ($queriesOfOneConnection as $query) {
                $sql = \is_string($query['sql'] ?? null) ? $query['sql'] : '';
                if (1 === preg_match('/tkt_ticket|search_ticket_ids|org_settings/', $sql)) {
                    $ticketQueries[] = $sql;
                }
            }
        }

        return $ticketQueries;
    }

    #[Test]
    public function aSearchOf200CharactersAroundSpacesIsTaken(): void
    {
        $this->addTicket('todo', 'Add a gift note', 'normal');
        $this->logIn($this->client, $this->people['Lena']);

        $page = $this->client->request('GET', self::TICKETS.'?q='.rawurlencode(' '.str_repeat('é', 200).' '));

        self::assertResponseStatusCodeSame(200);
        self::assertSame('No tickets match. Clear the search or pick another person.', $page->filter('.empty-state')->text());
    }

    /**
     * The page's budget is eight queries beyond the shell: the list's five (performance-and-scale.md) and the project
     * rail's three, the team names, the team's projects and their counts. The toolbar's people and names come from the
     * team people cache, so a warm page adds none for them. The search runs inside the rows' query, so it adds none.
     */
    #[Test]
    public function aWarmSearchPageReadsTheListsFourQueriesAndTheRailsThree(): void
    {
        $this->client->disableReboot();
        $this->addManyTickets('todo', 60);
        $this->addTurnsAndAssignees();
        $this->logIn($this->client, $this->people['Lena']);

        [$pageQueryCount, $listQueryCount] = $this->pageAndListQueryCountsMoreThanTheShellOf(self::TICKETS.'?q=checkout', true);

        self::assertLessThanOrEqual(5, $listQueryCount);
        self::assertSame([7, 4], [$pageQueryCount, $listQueryCount]);
    }

    #[Test]
    public function aWarmPageReadsTheListsFourQueriesAndTheRailsThreeFor200OpenTickets(): void
    {
        $this->client->disableReboot();
        foreach (['wait', 'check', 'doing', 'todo'] as $groupCode) {
            $this->addManyTickets($groupCode, 50);
        }
        $this->addTurnsAndAssignees();
        $this->logIn($this->client, $this->people['Lena']);

        [$pageQueryCount, $listQueryCount] = $this->pageAndListQueryCountsMoreThanTheShellOf(self::TICKETS, true);

        self::assertLessThanOrEqual(5, $listQueryCount);
        self::assertSame([7, 4], [$pageQueryCount, $listQueryCount]);
    }

    /**
     * A project page reads the project too, which gives the rail its team: the list's five and the rail's three.
     */
    #[Test]
    public function aWarmProjectPageReadsTheListsFiveQueriesAndTheRailsThree(): void
    {
        $this->client->disableReboot();
        $this->addManyTickets('todo', 60);
        $this->addTurnsAndAssignees();
        $this->logIn($this->client, $this->people['Lena']);

        [$pageQueryCount, $listQueryCount] = $this->pageAndListQueryCountsMoreThanTheShellOf(self::TICKETS.'?project=SHOP', true);

        self::assertLessThanOrEqual(5, $listQueryCount);
        self::assertSame([8, 5], [$pageQueryCount, $listQueryCount]);
    }

    /**
     * The first load fills both caches: the steps (one query, inside the list's five) and the toolbar's team people
     * and their names (two more), beside the rail's three, so 10.
     */
    #[Test]
    public function aColdPageReadsTheStepsAndTheTeamsPeopleToo(): void
    {
        $this->client->disableReboot();
        $this->addManyTickets('todo', 60);
        $this->addTurnsAndAssignees();
        $this->logIn($this->client, $this->people['Lena']);

        self::assertSame([10, 7], $this->pageAndListQueryCountsMoreThanTheShellOf(self::TICKETS, false));
    }

    /**
     * A project page reads the project too: 6 cold for the list (performance-and-scale.md), the team people's 2 and
     * the rail's 3.
     */
    #[Test]
    public function aColdProjectPageReadsTheStepsAndTheTeamsPeopleToo(): void
    {
        $this->client->disableReboot();
        $this->addManyTickets('todo', 60);
        $this->addTurnsAndAssignees();
        $this->logIn($this->client, $this->people['Lena']);

        self::assertSame([11, 8], $this->pageAndListQueryCountsMoreThanTheShellOf(self::TICKETS.'?project=SHOP', false));
    }

    /**
     * A change of Shop's people (TeamRoleChanged) clears its cached people, so the next load reads them and their
     * names again, two queries more than the warm page, and the one after is warm again.
     */
    #[Test]
    public function theFirstLoadAfterAChangeOfTheTeamReadsItsPeopleAndNamesAgain(): void
    {
        $this->client->disableReboot();
        $this->addManyTickets('todo', 60);
        $this->addTurnsAndAssignees();
        $this->logIn($this->client, $this->people['Lena']);
        $warmQueryCount = $this->warmQueryCountMoreThanTheShellOf(self::TICKETS);
        self::assertSame(7, $warmQueryCount);

        self::getContainer()->get(TeamPeopleCache::class)->invalidateTeamNow($this->shop);

        self::assertSame([9, 7], [$this->queryCountOf(self::TICKETS) - $this->queryCountOf(self::SHELL), $this->queryCountOf(self::TICKETS) - $this->queryCountOf(self::SHELL)]);
    }

    /**
     * A filter change reloads only the list frame: the answer is that frame alone, with the list's own queries and
     * neither the menu's teams and badge, the toolbar's people nor the rail, which the whole page reads (people from
     * the cache when warm).
     */
    #[Test]
    public function aFrameRequestGetsOnlyTheListFrameAndRunsOnlyTheListsQueries(): void
    {
        $this->client->disableReboot();
        $this->addManyTickets('todo', 60);
        $this->addTurnsAndAssignees();
        $this->logIn($this->client, $this->people['Lena']);
        $uri = self::TICKETS.'?person='.$this->people['Omar']->id()->toString();
        $this->queriesOf($uri);
        $pageQueries = $this->queriesOf($uri);
        self::assertCount(1, $this->client->getCrawler()->filter('.ticket-filters'));

        $frameQueries = $this->queriesOf($uri, ['HTTP_TURBO_FRAME' => 'ticket-list-frame']);

        $frame = $this->client->getCrawler();
        self::assertSame('Turbo-Frame', $this->client->getResponse()->headers->get('Vary'));
        self::assertCount(1, $frame->filter('turbo-frame#ticket-list-frame'));
        self::assertCount(0, $frame->filter('.ticket-filters, nav, header, title'));
        self::assertCount(30, $frame->filter('.tickets__row'));
        self::assertSame('30 tickets · grouped by step', $frame->filter('[data-total-line]')->attr('data-total-line'));
        $toolbarAndMenuQueries = '/FROM org_team_member WHERE team_id = ANY|FROM org_team WHERE/';
        self::assertSame([2, 0], [self::countMatching($toolbarAndMenuQueries, $pageQueries), self::countMatching($toolbarAndMenuQueries, $frameQueries)]);
        // The rail's three and the menu's team names, which match the rail's.
        self::assertSame([4, 0], [self::countMatching(self::RAIL_QUERIES, $pageQueries), self::countMatching(self::RAIL_QUERIES, $frameQueries)]);
        self::assertSame([1, 1], [self::countMatching('/FROM idn_user WHERE id = ANY/', $pageQueries), self::countMatching('/FROM idn_user WHERE id = ANY/', $frameQueries)]);
        // The header's Local AI chip reads the AI settings; a frame has no header.
        self::assertSame([1, 0], [self::countMatching("/data->'ai'/", $pageQueries), self::countMatching("/data->'ai'/", $frameQueries)]);
        // The menu's My reports badge; a frame has no menu.
        self::assertSame([1, 0], [self::countMatching('/AS questions_waiting FROM ins_ticket_turn/', $pageQueries), self::countMatching('/AS questions_waiting FROM ins_ticket_turn/', $frameQueries)]);
        // The menu's team names and My reports badge, the rail's three and the chip's AI settings.
        self::assertCount(\count($pageQueries) - 6, $frameQueries);
    }

    /**
     * The rail's frame reads the project of the URL and the rail's three, the team names, the team's projects and their
     * counts: neither the triager, the steps, the rows, the turn users nor their names. Beyond them run only the
     * request's own: the user and credential, its organizations, the transaction, the tenant and the actor's roles and
     * teams.
     */
    #[Test]
    public function theRailsFrameRequestRunsOnlyTheProjectAndTheRailsQueries(): void
    {
        $this->client->disableReboot();
        $this->addManyTickets('todo', 60);
        $this->addTurnsAndAssignees();
        $this->logIn($this->client, $this->people['Lena']);
        $railFrame = ['HTTP_TURBO_FRAME' => TicketListController::RAIL_FRAME_ID];
        $this->queriesOf(self::TICKETS, $railFrame);

        $warmQueries = $this->queriesOf(self::TICKETS, $railFrame);
        $projectQueries = $this->queriesOf(self::TICKETS.'?project=SHOP', $railFrame);

        self::assertCount(1, $this->client->getCrawler()->filter('turbo-frame#project-rail-frame nav.project-rail'));
        self::assertSame(
            [['org_team', 'tkt_project', 'ins_project_counts'], ['tkt_project', 'org_team', 'tkt_project', 'ins_project_counts']],
            [self::tablesReadBeyondTheRequestsOwn($warmQueries), self::tablesReadBeyondTheRequestsOwn($projectQueries)],
        );
        self::assertSame([11, 12], [\count($warmQueries), \count($projectQueries)]);
        self::assertSame(0, self::countMatching('/FROM tkt_ticket|FROM wf_step|FROM idn_user WHERE id = ANY|search_ticket_ids/', [...$warmQueries, ...$projectQueries]));
    }

    /**
     * @param list<string> $queries
     *
     * @return list<string> the table each query reads first, leaving out the request's own (the user, the tenant and
     *                      the actor)
     */
    private static function tablesReadBeyondTheRequestsOwn(array $queries): array
    {
        $requestsOwn = '/FROM idn_user t0 WHERE t0\.id = \?|FROM idn_credential|resolve_user_orgs|TRANSACTION|COMMIT|set_config|FROM org_membership|FROM org_team_member member/';
        $tables = [];
        foreach ($queries as $sql) {
            if (1 !== preg_match($requestsOwn, $sql) && 1 === preg_match('/ FROM (\w+)/', $sql, $match)) {
                $tables[] = $match[1];
            }
        }

        return $tables;
    }

    /**
     * @param list<string> $queries
     */
    private static function countMatching(string $pattern, array $queries): int
    {
        return \count(array_filter($queries, static fn (string $sql): bool => 1 === preg_match($pattern, $sql)));
    }

    /**
     * @param array<string, string> $server
     *
     * @return list<string> the SQL of every query the request ran
     */
    private function queriesOf(string $uri, array $server = []): array
    {
        $debugData = self::getContainer()->get('doctrine.debug_data_holder');
        $debugData->reset();
        $this->client->request('GET', $uri, server: $server);
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
     * @return array{int, int} the page's queries beyond the shell's, and those without the rail's (RAIL_QUERIES): the
     *                         list's own
     */
    private function pageAndListQueryCountsMoreThanTheShellOf(string $uri, bool $isStepCacheWarm): array
    {
        $this->queryCountOf(self::SHELL);
        $shellQueries = $this->queriesOf(self::SHELL);
        if ($isStepCacheWarm) {
            $this->queryCountOf($uri);
        }
        $pageQueries = $this->queriesOf($uri);
        self::assertGreaterThan(0, $this->client->getCrawler()->filter('.tickets__row .av[title]')->count());
        $pageQueryCount = \count($pageQueries) - \count($shellQueries);
        // The menu reads the same team names as the rail for a person in two teams, so the shell's are left out.
        $railQueryCount = self::countMatching(self::RAIL_QUERIES, $pageQueries) - self::countMatching(self::RAIL_QUERIES, $shellQueries);

        return [$pageQueryCount, $pageQueryCount - $railQueryCount];
    }

    private function warmQueryCountMoreThanTheShellOf(string $uri): int
    {
        return $this->queryCountMoreThanTheShellOf($uri, true);
    }

    private function queryCountMoreThanTheShellOf(string $uri, bool $isStepCacheWarm): int
    {
        // logIn() leaves the user in the entity manager, so the first request skips the user refresh (idn_user,
        // idn_credential) that every later one pays after the kernel resets its services; this one is not counted.
        $this->queryCountOf(self::SHELL);
        $shellQueryCount = $this->queryCountOf(self::SHELL);
        if ($isStepCacheWarm) {
            // The first load fills the organization's cached list of steps, as the first load after any step change does.
            $this->queryCountOf($uri);
        }

        return $this->queryCountOf($uri) - $shellQueryCount;
    }

    private function queryCountOf(string $uri): int
    {
        $debugData = self::getContainer()->get('doctrine.debug_data_holder');
        $debugData->reset();
        $page = $this->client->request('GET', $uri);
        self::assertResponseStatusCodeSame(200);
        if (str_starts_with($uri, self::TICKETS)) {
            self::assertGreaterThan(0, $page->filter('.tickets__row .av[title]')->count());
            self::assertGreaterThan(0, $page->filter('.tickets__meta:contains("Turn: you")')->count());
        }

        $queryCount = 0;
        foreach ($debugData->getData() as $queriesOfOneConnection) {
            $queryCount += \count($queriesOfOneConnection);
        }

        return $queryCount;
    }

    private function collapse(Crawler $page, string $groupCode, string $collapsed): void
    {
        $step = $page->filter('[data-group="'.$groupCode.'"]');
        $this->client->request('POST', (string) $step->attr('data-step-collapse-url-value'), [
            'collapsed' => $collapsed,
            '_csrf_token' => $step->attr('data-step-collapse-token-value'),
        ]);
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
     * @param array{assignee?: string, turn?: string, type?: string, release?: string, visibility?: string, project?: string, key?: string, updatedAt?: string} $details
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
            $details['updatedAt'] ?? \sprintf('2027-03-15 10:%02d:00+00', $number),
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
