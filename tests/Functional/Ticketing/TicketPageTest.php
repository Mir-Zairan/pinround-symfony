<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ticketing;

use App\Conversation\UI\Component\Thread;
use App\Identity\Domain\User;
use App\Review\UI\Component\RoundsSection;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\Tests\Functional\Assist\Laptop\StoresAiSettings;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use App\Ticketing\Application\MoveTicket\MoveTicket;
use App\Ticketing\Application\TicketHistory;
use App\Ticketing\Infrastructure\Scheduler\AddTicketEventPartitions;
use App\Ticketing\UI\MoveToasts;
use App\Ticketing\UI\TicketPageParts;
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
 * The ticket page at /t/{key} on Acme Foods' project SHOP through the real firewall, voters and database. The clock is
 * fixed on 6 Nov 2026, before today: a clock ahead of the login would end the session as idle. The test users' zone
 * is Europe/Berlin, so a ticket created at 23:30 UTC on 4 Nov reads "5 Nov".
 */
final class TicketPageTest extends WebTestCase
{
    use CreatesAcmeFoodsTeams;
    use CreatesTeams;
    use Factories;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }
    use StoresAiSettings;

    private const string SHELL = '/_test/menu/shell';
    private const string NOW = '2026-11-06 12:00:00 UTC';
    private const string CREATED_THIS_YEAR = '2026-11-04 23:30:00+00';
    private const string UPDATED_AT = '2026-11-10 09:00:00+00';
    /** The title and default text a refused viewer must never get a word of. */
    private const string HIDDEN_TITLE = 'Price rules for staff';
    private const string HIDDEN_DESCRIPTION = 'Steps to see it are in the title.';
    private const array PRIORITY_RANKS = ['blocker' => 0, 'critical' => 1, 'major' => 2, 'normal' => 3, 'minor' => 4];

    private KernelBrowser $client;

    /** @var array<string, User> */
    private array $people;

    private string $shopProject;

    /** @var array<string, string> step id by group code */
    private array $steps = [];

    /** @var array<string, string> step id by core code, for the tickets of the Now and Next tests */
    private array $stepsByCore = [];

    private int $nextNumber = 1;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTPS' => 'on']);
        $this->client->disableReboot();
        self::getContainer()->set('clock', new MockClock(new \DateTimeImmutable(self::NOW)));
        $this->people = $this->createAcmeFoodsPeople();
        $this->shopProject = $this->addProject($this->shop, 'SHOP', 'Shop');
        $this->addSteps();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->committedAs(new OrganizationId($organizationId), static function (Connection $migrator) use ($organizationId): int {
                foreach (['tkt_ticket_event', 'tkt_summary', 'ins_ticket_turn', 'ins_project_count_ticket', 'ins_project_counts', 'tkt_ticket', 'wf_step'] as $table) {
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
    public function aTeamMemberSeesTheHeaderTheAskTheRoundsAndOnlyTheDetailsThatHaveAValue(): void
    {
        $this->addTicket('Add a gift note', ['group' => 'todo']);
        $key = $this->addTicket('Search shows no results', [
            'group' => 'check',
            'type' => 'story',
            'description' => "Typing a product name finds nothing.\nIt worked last week.",
        ]);
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', '/t/'.$key);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('SHOP-2', $key);
        self::assertSame('no-cache', $page->filter('head meta[name="turbo-cache-control"]')->attr('content'));
        self::assertSame(['< Tickets', '/tickets', 'btn btn-text ticket__back'], [$page->filter('.ticket__back')->text(), $page->filter('.ticket__back')->attr('href'), $page->filter('.ticket__back')->attr('class')]);
        self::assertSame(['SHOP-2', 'Story · Shop'], [$page->filter('.ticket__key-line .ticket__key')->text(), $page->filter('.ticket__kind')->text()]);
        self::assertCount(0, $page->filter('.ticket__team-only'));
        self::assertSame('Search shows no results', $page->filter('h1')->text());
        self::assertSame(['Code review', 'pill pill-checking'], [$page->filter('.ticket__state .pill')->text(), $page->filter('.ticket__state .pill')->attr('class')]);
        self::assertSame('Reported in pinround · Group: Checking', $page->filter('.ticket__note')->text());
        self::assertSame('Original text from the report · not summarized yet', $page->filter('.ticket__ask-source')->text());
        self::assertSame("Typing a product name finds nothing.\nIt worked last week.", $page->filter('.ticket__ask-text')->text(normalizeWhitespace: false));
        self::assertCount(0, $page->filter('.ticket__ask-more'));
        self::assertSame('Asked by Lena · 5 Nov', $page->filter('.ticket__ask .ticket__asked')->text());
        $roundsFrame = $page->filter('[aria-labelledby="ticket-rounds-heading"] turbo-frame#ticket-rounds');
        self::assertSame('/t/'.$key.'/rounds', $roundsFrame->attr('src'));
        self::assertSame('lazy', $roundsFrame->attr('loading'));
        self::assertSame('Show rounds', $roundsFrame->filter('a[href="/t/'.$key.'/rounds"]')->text());
        self::assertCount(0, $page->filter('.round-card, .empty-state'), 'The page reads no round: the frame does.');
        self::assertCount(1, $page->filter('link[rel="stylesheet"][href*="/styles/review"]'));
        self::assertSame([['Assignee', 'Nobody'], ['Priority', 'Major'], ['Created', '5 Nov']], $this->detailsOf($page));
    }

    #[Test]
    public function onATicketPageTicketsIsTheCurrentItemOfTheMainMenu(): void
    {
        $key = $this->addTicket('Search shows no results', ['group' => 'check']);
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', '/t/'.$key);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Tickets'], $page->filter('header nav[aria-label="Main"] a[aria-current="page"]')->each(static fn (Crawler $link): string => $link->text()));
    }

    #[Test]
    public function everyDetailWithAValueShowsInOrderWithTheYearWhenItIsNotThisYears(): void
    {
        $parentKey = $this->addTicket('Checkout rework', ['group' => 'doing']);
        $key = $this->addTicket('Cart total shows the wrong sum', [
            'group' => 'doing',
            'priority' => 'critical',
            'assignee' => 'Omar',
            'release' => '2.4',
            'parent' => $parentKey,
            'components' => '{Checkout,"Cart page"}',
            'createdAt' => '2025-11-04 23:30:00+00',
        ]);
        $this->logIn($this->client, $this->people['Lena']);

        $page = $this->client->request('GET', '/t/'.$key);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([
            ['Assignee', 'Omar'],
            ['Priority', 'Critical'],
            ['Release', '2.4'],
            ['Parent', 'SHOP-1'],
            ['Components', 'Checkout, Cart page'],
            ['Created', '5 Nov 2025'],
        ], $this->detailsOf($page));
        self::assertSame('Asked by Lena · 5 Nov 2025', $page->filter('.ticket__asked')->text());
    }

    /**
     * Raj, a manager with no team role, may open the ticket for everyone but not its team-only parent, so its key is
     * left out for him; Lena, the team's lead, sees it.
     */
    #[Test]
    public function aParentTheViewerMayNotSeeIsLeftOutOfTheDetails(): void
    {
        $parentKey = $this->addTicket('Price rules for staff', ['visibility' => 'team']);
        $key = $this->addTicket('Staff price shows to everyone', ['parent' => $parentKey]);

        $this->logIn($this->client, $this->people['Raj']);
        $rajsPage = $this->client->request('GET', '/t/'.$key);
        self::assertResponseStatusCodeSame(200);
        $rajsDetails = $this->detailsOf($rajsPage);
        self::assertStringNotContainsString('SHOP-1', $rajsPage->filter('main')->text());

        $this->logIn($this->client, $this->people['Lena']);
        $lenasPage = $this->client->request('GET', '/t/'.$key);
        self::assertResponseStatusCodeSame(200);

        self::assertSame([['Assignee', 'Nobody'], ['Priority', 'Major'], ['Created', '5 Nov']], $rajsDetails);
        self::assertSame([['Assignee', 'Nobody'], ['Priority', 'Major'], ['Parent', 'SHOP-1'], ['Created', '5 Nov']], $this->detailsOf($lenasPage));
    }

    /**
     * 87 times "pay now " is 696 characters, so the 700th falls inside "checkout": the cut goes back to the end of the
     * last whole word. The full text stays in the page for a browser without JavaScript.
     */
    #[Test]
    public function aLongAskIsCutAtAWordEndWithAShowTheFullAskDisclosure(): void
    {
        $wholeWords = str_repeat('pay now ', 87);
        $description = $wholeWords.'checkout total is wrong';
        $key = $this->addTicket('Pay button is hidden', ['description' => $description]);
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', '/t/'.$key);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(rtrim($wholeWords).' …', $page->filter('.ticket__ask-text--short')->text());
        self::assertSame(['Show the full ask', 'Show less'], [$page->filter('.ticket__ask-more summary .ticket__ask-show')->text(), $page->filter('.ticket__ask-more summary .ticket__ask-hide')->text()]);
        self::assertSame($description, $page->filter('.ticket__ask-more .ticket__ask-text--full')->text());
    }

    #[Test]
    public function anAskOfExactly700CharactersIsShownWholeWithNoDisclosure(): void
    {
        $description = str_repeat('pay now ', 87).'pays';
        self::assertSame(700, mb_strlen($description));
        $key = $this->addTicket('Pay button is hidden', ['description' => $description]);
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', '/t/'.$key);

        self::assertResponseStatusCodeSame(200);
        self::assertSame($description, $page->filter('.ticket__ask-text')->text());
        self::assertCount(0, $page->filter('.ticket__ask-more, .ticket__ask-text--short'));
        self::assertStringNotContainsString('Show the full ask', $page->filter('main')->text());
    }

    #[Test]
    public function aTicketWithNoTextSaysSoWithNoEmptyCard(): void
    {
        $key = $this->addTicket('Footer links point nowhere', ['description' => '']);
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', '/t/'.$key);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('The report came with no text.', $page->filter('.ticket__ask-none')->text());
        self::assertSame('Asked by Lena · 5 Nov', $page->filter('.ticket__asked')->text());
        self::assertCount(0, $page->filter('.ticket__ask, .ticket__ask-source'));
    }

    /**
     * A reporter or assignee whose user row is gone still leaves a page, not an error, and the assignee is not
     * "Nobody", as someone has the ticket.
     */
    #[Test]
    public function aReporterAndAssigneeWithNoUserAnyMoreReadAsAnUnknownPerson(): void
    {
        $key = $this->addTicket('Footer links point nowhere');
        $goneUserId = Uuid::v7()->toRfc4122();
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            'UPDATE tkt_ticket SET reporter_id = ?, assignee_id = ? WHERE key = ?',
            [$goneUserId, $goneUserId, $key],
        ));
        $this->logIn($this->client, $this->people['Lena']);

        $page = $this->client->request('GET', '/t/'.$key);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Asked by an unknown person · 5 Nov', $page->filter('.ticket__asked')->text());
        self::assertSame(['Assignee', 'An unknown person'], $this->detailsOf($page)[0]);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function viewersOfATeamOnlyTicket(): iterable
    {
        yield 'Lena, the team\'s lead' => ['Lena', 200];
        yield 'Omar, a member of the team' => ['Omar', 200];
        yield 'Mia, a viewer of the team' => ['Mia', 200];
        yield 'Sara, an admin with no team role' => ['Sara', 200];
        yield 'Ada, the outside reporter' => ['Ada', 200];
        yield 'Raj, a manager with no team role' => ['Raj', 404];
        yield 'Noor, of no team and no link to it' => ['Noor', 404];
    }

    /**
     * The team-only tag shows to everyone who may open the ticket; anyone else gets the same 404 as for an unknown
     * key, and no word of the ticket.
     */
    #[Test]
    #[DataProvider('viewersOfATeamOnlyTicket')]
    public function aTeamOnlyTicketOpensWithItsTagOnlyForTheTeamAdminsAndItsPeople(string $viewer, int $expectedStatus): void
    {
        foreach (['Ada', 'Noor'] as $outsider) {
            $this->people[$outsider] = $this->createUserWithoutOrganization($outsider);
            $this->addMembership($this->people[$outsider], $this->acmeFoods);
        }
        $key = $this->addTicket(self::HIDDEN_TITLE, ['visibility' => 'team', 'reporter' => 'Ada']);
        $this->logIn($this->client, $this->people[$viewer]);

        $page = $this->client->request('GET', '/t/'.$key);

        if (404 === $expectedStatus) {
            $this->assertNotFoundWithNoWordOfTheTicket();

            return;
        }
        self::assertResponseStatusCodeSame(200);
        self::assertSame('Only the team and admins see this', $page->filter('.ticket__key-line .ticket__team-only')->text());
        self::assertSame(self::HIDDEN_TITLE, $page->filter('h1')->text());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function keysOfNoTicket(): iterable
    {
        yield 'a number no ticket has' => ['SHOP-99'];
        yield 'the key in lower case' => ['shop-1'];
        yield 'no number' => ['SHOP-'];
        yield 'number zero' => ['SHOP-0'];
        yield 'a leading zero' => ['SHOP-01'];
        yield 'a trailing newline' => ['SHOP-1%0A'];
        yield 'a trailing CR LF' => ['SHOP-1%0D%0A'];
        yield 'a number past the integer range' => ['SHOP-99999999999999999999'];
        yield '300 characters' => ['SHOP-'.str_repeat('1', 295)];
    }

    #[Test]
    #[DataProvider('keysOfNoTicket')]
    public function aKeyOfNoTicketIsNotFoundAndShowsNoTicket(string $key): void
    {
        $this->addTicket(self::HIDDEN_TITLE);
        $this->logIn($this->client, $this->people['Lena']);

        $this->client->request('GET', '/t/'.$key);

        $this->assertNotFoundWithNoWordOfTheTicket();
    }

    #[Test]
    public function aPersonOfAnotherOrganizationGetsNotFoundForAValidKey(): void
    {
        $key = $this->addTicket(self::HIDDEN_TITLE);
        $betaBakery = $this->createOrganization('Beta Bakery');
        $ben = $this->createUserWithoutOrganization('Ben');
        $this->addMembership($ben, $betaBakery);
        $this->setOrgRoles($ben, $betaBakery, '{admin}');
        $this->logIn($this->client, $ben);

        $this->client->request('GET', '/t/'.$key);

        $this->assertNotFoundWithNoWordOfTheTicket();
    }

    /**
     * The page follows its own ticket's topic and no other ticket's, and marks what a refresh signal fetches again: the
     * header's state, the thread's frame, the next step box, the rounds' frame, People and What happened.
     */
    #[Test]
    public function aMemberFollowsTheLiveUpdatesOfThisTicketOnly(): void
    {
        $otherKey = $this->addTicket('Add a gift note');
        $key = $this->addTicket('Search shows no results');
        $ticketId = $this->ticketIdOf($key);
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', '/t/'.$key);

        self::assertResponseStatusCodeSame(200);
        $liveUpdates = $page->filter('[data-controller="live-updates"]');
        self::assertCount(1, $liveUpdates);
        self::assertSame('["'.$ticketId.'"]', $liveUpdates->attr('data-live-updates-ticket-ids-value'));
        $subscribeUrl = (string) $liveUpdates->attr('data-live-updates-subscribe-url-value');
        self::assertStringContainsString('match='.rawurlencode('https://pinround.app/o/'.$this->acmeFoods->toString().'/t/'.$ticketId), $subscribeUrl);
        self::assertSame(1, substr_count($subscribeUrl, rawurlencode('/t/')));
        self::assertStringNotContainsString($this->ticketIdOf($otherKey), (string) $this->client->getResponse()->getContent());
        self::assertSame(
            [TicketPageParts::STATE_ID, Thread::FRAME_ID, TicketPageParts::NEXT_STEP_ID, RoundsSection::FRAME_ID, TicketPageParts::PEOPLE_ID, TicketPageParts::ACTIVITY_ID],
            $page->filter('[data-live-refresh]')->each(static fn (Crawler $part): ?string => $part->attr('id')),
        );
    }

    #[Test]
    public function aPersonWhoMayNotSeeTheTicketGetsNotFoundWithNoLiveUpdates(): void
    {
        $key = $this->addTicket(self::HIDDEN_TITLE, ['visibility' => 'team']);
        $this->logIn($this->client, $this->people['Raj']);

        $this->client->request('GET', '/t/'.$key);

        $this->assertNotFoundWithNoWordOfTheTicket();
        self::assertStringNotContainsString($this->ticketIdOf($key), (string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function anAnonymousVisitorIsSentToLogIn(): void
    {
        $key = $this->addTicket('Add a gift note');

        $this->client->request('GET', '/t/'.$key);

        self::assertResponseStatusCodeSame(302);
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    /**
     * @return iterable<string, array{string, string|null, string, string|null}> the step's core code, the assignee,
     *                                                                           the Now text and the Next text
     */
    public static function nowAndNextOfEachCoreStep(): iterable
    {
        $buildReviewTest = 'builds it, Lena reviews, Mia tests';
        yield 'Backlog' => ['backlog', null, 'Lena plans it', 'a developer '.$buildReviewTest];
        yield 'To do, assigned' => ['todo', 'Omar', 'Omar starts building', 'Omar '.$buildReviewTest];
        yield 'To do, unassigned' => ['todo', null, 'Lena picks a developer', 'a developer '.$buildReviewTest];
        yield 'In progress' => ['progress', 'Omar', 'Omar builds it', 'Lena reviews the code, then Mia tests it'];
        yield 'Code review' => ['review', 'Omar', 'Lena reviews the code', 'Mia tests it'];
        yield 'Testing' => ['test', 'Omar', 'Mia tests it', 'Done'];
        yield 'Needs input' => ['input', 'Omar', 'Waiting for input from Lena', 'it goes back to work'];
        yield 'On hold' => ['hold', 'Omar', 'Lena decides if it goes on', 'it goes back to work'];
        yield 'Done' => ['done', 'Omar', 'Done. Nobody needs to act.', null];
    }

    #[Test]
    #[DataProvider('nowAndNextOfEachCoreStep')]
    public function theHeaderTellsWhoseTurnItIsNowAndWhatComesNext(string $coreCode, ?string $assignee, string $expectedNow, ?string $expectedNext): void
    {
        $this->makeShopPeopleDevelopersAndTester();
        $key = $this->addTicket('Cart total shows the wrong sum', ['core' => $coreCode, ...(null === $assignee ? [] : ['assignee' => $assignee])]);
        $this->logIn($this->client, $this->people['Raj']);

        $page = $this->client->request('GET', '/t/'.$key);

        self::assertResponseStatusCodeSame(200);
        self::assertSame($expectedNow, $this->turnLineOf($page, 'now'));
        self::assertSame($expectedNext, $this->turnLineOf($page, 'next'));
        self::assertCount(null === $expectedNext ? 0 : 1, $page->filter('.ticket__next'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function pillOfEachCoreStep(): iterable
    {
        yield 'Backlog' => ['backlog', 'Backlog'];
        yield 'To do' => ['todo', 'To do'];
        yield 'In progress' => ['progress', 'In progress'];
        yield 'Code review' => ['review', 'Code review'];
        yield 'Testing' => ['test', 'Testing'];
        yield 'Needs input' => ['input', 'Needs input'];
        yield 'On hold' => ['hold', 'On hold'];
        yield 'Done' => ['done', 'Done'];
    }

    #[Test]
    #[DataProvider('pillOfEachCoreStep')]
    public function theStatusPillNamesTheStepNextToTheTurnLines(string $coreCode, string $expectedPill): void
    {
        $this->makeShopPeopleDevelopersAndTester();
        $key = $this->addTicket('Cart total shows the wrong sum', ['core' => $coreCode, 'assignee' => 'Omar']);
        $this->logIn($this->client, $this->people['Raj']);

        $page = $this->client->request('GET', '/t/'.$key);

        self::assertResponseStatusCodeSame(200);
        self::assertSame($expectedPill, $page->filter('.ticket__state .pill')->text());
        self::assertCount(1, $page->filter('.ticket__state .ticket__now'));
    }

    /**
     * The page names people by first name, never "you", whoever looks: the viewer is Mia, whose turn it is, and then Omar.
     */
    #[Test]
    public function thePersonWhoseTurnItIsReadsTheirFirstNameEvenForThemselves(): void
    {
        $this->makeShopPeopleDevelopersAndTester();
        $key = $this->addTicket('Cart total shows the wrong sum', ['core' => 'test', 'assignee' => 'Omar']);

        $this->logIn($this->client, $this->people['Mia']);
        $miasPage = $this->client->request('GET', '/t/'.$key);
        self::assertResponseStatusCodeSame(200);
        $miasNow = $this->turnLineOf($miasPage, 'now');

        $this->logIn($this->client, $this->people['Omar']);
        $omarsPage = $this->client->request('GET', '/t/'.$key);
        self::assertResponseStatusCodeSame(200);

        self::assertSame(['Mia tests it', 'Mia tests it'], [$miasNow, $this->turnLineOf($omarsPage, 'now')]);
    }

    #[Test]
    public function aNeedsInputTicketWaitingOnAnOutsideUserNamesThem(): void
    {
        $this->makeShopPeopleDevelopersAndTester();
        $key = $this->addTicket('Cart total shows the wrong sum', ['core' => 'input', 'assignee' => 'Omar']);
        $this->committedAs($this->acmeFoods, fn (Connection $migrator): int|string => $migrator->executeStatement(
            'UPDATE tkt_ticket SET waiting_on_user_id = ? WHERE key = ?',
            [$this->people['Raj']->id()->toString(), $key],
        ));
        $this->addTurnRow($key, 'Raj', 'input');
        $this->logIn($this->client, $this->people['Sara']);

        $page = $this->client->request('GET', '/t/'.$key);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Waiting for input from Raj', $this->turnLineOf($page, 'now'));
    }

    #[Test]
    public function aNeedsInputTicketWhoseSavedTurnIsALabelNamesTheLabel(): void
    {
        $this->makeShopPeopleDevelopersAndTester();
        $key = $this->addTicket('Cart total shows the wrong sum', ['core' => 'input', 'assignee' => 'Omar']);
        $this->addTurnRow($key, null, 'input', 'Customer');
        $this->logIn($this->client, $this->people['Sara']);

        $page = $this->client->request('GET', '/t/'.$key);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Waiting for input from Customer', $this->turnLineOf($page, 'now'));
    }

    /**
     * Raj is a Manager with no role in Shop and no part in the ticket, so the page holds no name for him and reads it
     * in one more query.
     */
    #[Test]
    public function aSavedTurnOfSomeoneOutsideTheTicketAndItsTeamNamesThemByFirstName(): void
    {
        $this->makeShopPeopleDevelopersAndTester();
        $key = $this->addTicket('Cart total shows the wrong sum', ['core' => 'hold', 'assignee' => 'Omar']);
        $this->addTurnRow($key, 'Raj', 'decide');
        $this->logIn($this->client, $this->people['Sara']);

        $page = $this->client->request('GET', '/t/'.$key);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Raj decides if it goes on', $this->turnLineOf($page, 'now'));
    }

    /**
     * Omar is the assignee, so Lena is the reviewer when the turn is worked out; the turn row Insight saved says Omar,
     * and Home shows Omar, so the page must name Omar.
     */
    #[Test]
    public function nowNamesThePersonOfTheSavedTurnNotOneWorkedOutAgain(): void
    {
        $this->makeShopPeopleDevelopersAndTester();
        $key = $this->addTicket('Cart total shows the wrong sum', ['core' => 'review', 'assignee' => 'Omar']);
        $this->addTurnRow($key, 'Omar', 'review');
        $this->logIn($this->client, $this->people['Sara']);

        $page = $this->client->request('GET', '/t/'.$key);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Omar reviews the code', 'Mia tests it'], [$this->turnLineOf($page, 'now'), $this->turnLineOf($page, 'next')]);
    }

    /**
     * @return iterable<string, array{string, string}> the tester with a test waiting, the tester Next names
     */
    public static function busyTesters(): iterable
    {
        yield 'Mia is busy' => ['Mia', 'Noor tests it'];
        yield 'Noor is busy' => ['Noor', 'Mia tests it'];
    }

    /**
     * Two testers could test it; the one with a test already waiting in the turn rows is the busier, so Next names the
     * other. Swapping who is busy swaps the answer, so a choice that ignored the waiting work fails one of the two.
     */
    #[Test]
    #[DataProvider('busyTesters')]
    public function nextNamesTheTesterWithTheFewerTestsWaiting(string $busyTester, string $expectedNext): void
    {
        $this->makeShopPeopleDevelopersAndTester();
        $this->addSecondShopTester();
        $key = $this->addTicket('Cart total shows the wrong sum', ['core' => 'review', 'assignee' => 'Omar']);
        $waitingKey = $this->addTicket('Cart page is slow', ['core' => 'test', 'assignee' => 'Omar']);
        $this->addTurnRow($waitingKey, $busyTester, 'test');
        $this->logIn($this->client, $this->people['Sara']);

        $page = $this->client->request('GET', '/t/'.$key);

        self::assertResponseStatusCodeSame(200);
        self::assertSame($expectedNext, $this->turnLineOf($page, 'next'));
    }

    /**
     * Lena wrote it, so Omar or Pia reviews, whoever has fewer reviews waiting. The ticket's own row is a review for
     * Omar and no other ticket waits for anyone: counted, it would make Omar the busier and send the review to Pia.
     */
    #[Test]
    public function theTicketsOwnTurnRowIsNoLoadOnItsReviewer(): void
    {
        $this->makeShopPeopleDevelopersAndTester();
        $this->addSecondShopDeveloper();
        $key = $this->addTicket('Cart total shows the wrong sum', ['core' => 'progress', 'assignee' => 'Lena']);
        $this->addTurnRow($key, 'Omar', 'review');
        $this->logIn($this->client, $this->people['Sara']);

        $page = $this->client->request('GET', '/t/'.$key);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Omar reviews the code, then Mia tests it', $this->turnLineOf($page, 'next'));
    }

    #[Test]
    public function aTicketNoTeamOwnsRendersWithNoNowOrNextLine(): void
    {
        $this->makeShopPeopleDevelopersAndTester();
        $key = $this->addTicket('Footer links point nowhere', ['core' => 'progress', 'assignee' => 'Omar']);
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            'UPDATE tkt_ticket SET team_id = NULL WHERE key = ?',
            [$key],
        ));
        $this->logIn($this->client, $this->people['Sara']);

        $page = $this->client->request('GET', '/t/'.$key);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Footer links point nowhere', $page->filter('h1')->text());
        self::assertSame('In progress', $page->filter('.ticket__state .pill')->text());
        self::assertCount(0, $page->filter('.ticket__now, .ticket__next'));
        self::assertCount(0, $page->filter('.next-step-box'));
    }

    /**
     * With no team there is no turn to read names with, so What happened reads the name of Sara, who is not among the
     * ticket's people, on its own.
     */
    #[Test]
    public function whatHappenedOnATicketNoTeamOwnsNamesAMoverOutsideItsPeople(): void
    {
        new AddTicketEventPartitions($this->migrator(), new MockClock(new \DateTimeImmutable(self::NOW)))();
        $key = $this->addTicket('Footer links point nowhere', ['core' => 'progress', 'assignee' => 'Omar']);
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            'UPDATE tkt_ticket SET team_id = NULL WHERE key = ?',
            [$key],
        ));
        $this->addHistoryRow($key, 'moved', 'Sara', 'todo', 'progress', [], '2026-11-06 10:00:00+00');
        $this->logIn($this->client, $this->people['Sara']);

        $page = $this->client->request('GET', '/t/'.$key);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['2 h ago · Sara moved it from To do to In progress', '5 Nov · Created by Lena'], self::activityLinesOf($page));
    }

    /**
     * SHOP-1 is in To do and assigned to Omar. Only a lead or member of Shop may move it; only its lead gets the move
     * list, which holds the organization's steps and Shop's own, never another team's.
     *
     * @return iterable<string, array{string, array<string, string>, bool}>
     */
    public static function nextStepOfEachRole(): iterable
    {
        yield 'Lena, the team\'s lead' => ['Lena', ['put_on_hold' => 'Put on hold'], true];
        yield 'Omar, the assignee and a member' => ['Omar', ['start_work' => 'Start work'], false];
        yield 'Mia, a viewer of the team' => ['Mia', [], false];
        yield 'Raj, a manager with no team role' => ['Raj', [], false];
        yield 'Sara, an admin with no team role' => ['Sara', [], false];
    }

    /**
     * @param array<string, string> $expectedVerbs each verb's button label by its code
     */
    #[Test]
    #[DataProvider('nextStepOfEachRole')]
    public function theNextStepBoxOffersMovesOnlyToThoseWhoMayMakeThem(string $viewer, array $expectedVerbs, bool $hasMoveList): void
    {
        $this->addTeamStep($this->shop, 'Pairing', 'doing');
        $this->addTeamStep($this->mobile, 'Store review', 'check');
        $key = $this->addTicket('Cart total shows the wrong sum', ['core' => 'todo', 'assignee' => 'Omar']);
        $this->logIn($this->client, $this->people[$viewer]);

        $page = $this->client->request('GET', '/t/'.$key);

        self::assertResponseStatusCodeSame(200);
        $box = $page->filter('.ticket__columns > .ticket__next-step > .next-step-box');
        self::assertCount(1, $box);
        self::assertNotSame('', trim($box->filter('.next-step-box__sentence')->text()));
        self::assertSame(
            array_map(static fn (string $verb, string $label): array => ['/t/'.$key.'/move/'.$verb, $label], array_keys($expectedVerbs), $expectedVerbs),
            $box->filter('.next-step-box__verbs form:not(#give-it-to-form)')->each(static fn (Crawler $form): array => [(string) $form->attr('action'), $form->filter('button')->text()]),
        );
        // Drive is off, so only a form that opts in is posted by Turbo and gets the move's Turbo Stream answer.
        self::assertCount($page->filter('form[action*="/move"]')->count(), $page->filter('form[action*="/move"][data-turbo="true"]'));
        // assets/app.js has no failure text of its own: after a reload it shows the form's data-failed-toast.
        $moveForms = $page->filter('form[action*="/move"]');
        self::assertSame(array_fill(0, $moveForms->count(), MoveToasts::MOVE_FAILED), $moveForms->each(static fn (Crawler $form): ?string => $form->attr('data-failed-toast')));
        $moveList = $box->filter('details.next-step-box__move-to');
        self::assertCount($hasMoveList ? 1 : 0, $page->filter('dialog#needs-input-'.$this->stepsByCore['input']));
        self::assertCount($hasMoveList ? 1 : 0, $page->filter('dialog:not(#give-it-to-picker):not([id^="ping-"])'));
        if (!$hasMoveList) {
            self::assertCount(0, $moveList);
            self::assertCount(\count($expectedVerbs), $page->filter('form[action*="/move"]'));

            return;
        }
        // The groups in their fixed order although addSteps numbers positions across groups from Needs input; inside a
        // group by position, so To do (3) comes before Backlog (5).
        self::assertSame(
            [
                ['To do', ['To do', 'Backlog']],
                ['Doing', ['In progress', 'Pairing']],
                ['Checking', ['Code review', 'Testing']],
                ['Waiting', ['Needs input', 'On hold']],
                ['Done', ['Done']],
            ],
            $moveList->filter('.next-step-box__group')->each(static fn (Crawler $group): array => [
                $group->filter('.next-step-box__group-label')->text(),
                $group->filter('button.chip')->each(static fn (Crawler $chip): string => $chip->text()),
            ]),
        );
        self::assertSame(['To do'], $moveList->filter('button[aria-current="step"]')->each(static fn (Crawler $chip): string => $chip->text()));
        self::assertStringNotContainsString('Store review', $box->text());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function viewersWhoseTurnItIsOrNot(): iterable
    {
        yield 'Omar, whose turn it is' => ['Omar'];
        yield 'Mia, a viewer whose turn it is not' => ['Mia'];
    }

    /**
     * Keyboard focus follows the markup (WCAG 2.4.3), and on a phone the Your next step box shows above the Rounds
     * (P14.T7). So the markup holds the columns' parts in that order and ticket.css places them with grid areas on a
     * wide screen, never with order: the main column, the box, the Rounds, then the aside.
     */
    #[Test]
    #[DataProvider('viewersWhoseTurnItIsOrNot')]
    public function theNextStepBoxComesBeforeTheRoundsInTheMarkup(string $viewer): void
    {
        $key = $this->addTicket('Cart total shows the wrong sum', ['core' => 'todo', 'assignee' => 'Omar']);
        $this->logIn($this->client, $this->people[$viewer]);

        $page = $this->client->request('GET', '/t/'.$key);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            ['ticket__main', 'ticket__next-step', 'ticket__rounds', 'ticket__aside'],
            $page->filter('.ticket__columns')->children()->each(static fn (Crawler $part): string => preg_match('/\bticket__(main|next-step|rounds|aside)\b/', (string) $part->attr('class'), $match) > 0 ? $match[0] : self::fail('A part of the columns has none of the classes ticket.css places: '.$part->attr('class'))),
        );
        self::assertSame(TicketPageParts::NEXT_STEP_ID, $page->filter('.ticket__columns > .ticket__next-step')->attr('id'));
        self::assertSame('ticket-rounds-heading', $page->filter('.ticket__columns > .ticket__rounds')->attr('aria-labelledby'));
        self::assertCount(1, $page->filter('.next-step-box'), 'One Your next step box, not a second copy.');
        self::assertCount(1, $page->filter('.ticket__next-step > .next-step-box'));
        self::assertSame(
            ['ticket__people', 'ticket__activity', 'ticket__section'],
            $page->filter('.ticket__columns > .ticket__aside')->children()->each(static fn (Crawler $part): string => preg_replace('/^.*?\b(ticket__[a-z-]+)\b.*$/', '$1', (string) $part->attr('class')) ?? self::fail('The class of an aside part could not be read.')),
        );
        self::assertCount(0, $page->filter('.ticket__main .ticket__rounds, .ticket__aside .next-step-box'));
    }

    /**
     * P14.T7: an empty thread is one muted line, not the dashed empty box, so the page stays short above the Rounds.
     */
    #[Test]
    public function aTicketWithNoMessagesShowsTheCompactEmptyThreadLine(): void
    {
        $key = $this->addTicket('Cart total shows the wrong sum', ['core' => 'todo', 'assignee' => 'Omar']);
        $this->logIn($this->client, $this->people['Lena']);

        $page = $this->client->request('GET', '/t/'.$key.'/thread', [], [], ['HTTP_TURBO_FRAME' => Thread::FRAME_ID]);

        self::assertResponseStatusCodeSame(200);
        $messages = $page->filter('turbo-frame#'.Thread::FRAME_ID.' #'.Thread::MESSAGES_ID);
        self::assertSame([Thread::EMPTY_THREAD], $messages->filter('p.thread__empty')->each(static fn (Crawler $line): string => $line->text()));
        self::assertCount(0, $messages->filter('.empty-state, .thread__messages'));
    }

    /**
     * Omar reported the ticket and has it, Mia reviews it; Lena, who looks, and Raj have no part in it, so they are not
     * offered although both are members.
     */
    #[Test]
    public function theNeedsInputDialogOffersTheTicketsPeopleWithTheirRolesAndSomeoneElse(): void
    {
        $key = $this->addTicket('Cart total shows the wrong sum', ['core' => 'progress', 'reporter' => 'Omar', 'assignee' => 'Omar']);
        $this->committedAs($this->acmeFoods, fn (Connection $migrator): int|string => $migrator->executeStatement(
            'UPDATE tkt_ticket SET reviewer_id = ? WHERE key = ?',
            [$this->people['Mia']->id()->toString(), $key],
        ));
        $this->logIn($this->client, $this->people['Lena']);

        $page = $this->client->request('GET', '/t/'.$key);

        self::assertResponseStatusCodeSame(200);
        $dialog = $page->filter('dialog#needs-input-'.$this->stepsByCore['input']);
        self::assertCount(1, $dialog);
        self::assertSame('Move to Needs input', $dialog->filter('.dialog__title')->text());
        self::assertSame(
            [
                ['Omar · Reporter · Assignee', $this->people['Omar']->id()->toString(), true],
                ['Mia · Code reviewer', $this->people['Mia']->id()->toString(), false],
                ['Someone else', '', false],
            ],
            self::waitingOnChoicesOf($dialog),
        );
        $labelField = $dialog->filter('input.waiting-on__label[type="text"]');
        self::assertCount(1, $labelField);
        self::assertSame(['100', 'Who else it waits on'], [$labelField->attr('maxlength'), $labelField->attr('aria-label')]);
        self::assertNotNull($labelField->attr('disabled'));
        self::assertStringNotContainsString('Raj', $dialog->text());
        self::assertStringNotContainsString('Lena', $dialog->text());
    }

    /**
     * Raj reported the ticket and left Acme Foods before Lena opened it, so he is not offered, as the move would be
     * refused; Omar, the next of the ticket's people, is chosen instead.
     */
    #[Test]
    public function theNeedsInputDialogLeavesOutAReporterWhoLeftAndChoosesTheNextPerson(): void
    {
        $key = $this->addTicket('Cart total shows the wrong sum', ['core' => 'progress', 'reporter' => 'Raj', 'assignee' => 'Omar']);
        $this->committedAs($this->acmeFoods, fn (Connection $migrator): int|string => $migrator->executeStatement(
            'UPDATE tkt_ticket SET reviewer_id = ? WHERE key = ?',
            [$this->people['Mia']->id()->toString(), $key],
        ));
        $this->removeMembership($this->people['Raj'], $this->acmeFoods);
        $this->logIn($this->client, $this->people['Lena']);

        $page = $this->client->request('GET', '/t/'.$key);

        self::assertResponseStatusCodeSame(200);
        $dialog = $page->filter('dialog#needs-input-'.$this->stepsByCore['input']);
        self::assertSame(
            [
                ['Omar · Assignee', $this->people['Omar']->id()->toString(), true],
                ['Mia · Code reviewer', $this->people['Mia']->id()->toString(), false],
                ['Someone else', '', false],
            ],
            self::waitingOnChoicesOf($dialog),
        );
        self::assertNotNull($dialog->filter('input.waiting-on__label')->attr('disabled'));
        self::assertStringNotContainsString('Raj', $dialog->text());
    }

    /**
     * Raj reported the ticket and Sara has it; both left Acme Foods, so only Someone else is left, chosen, with its
     * label to write.
     */
    #[Test]
    public function theNeedsInputDialogChoosesSomeoneElseWhenEveryoneOfTheTicketLeft(): void
    {
        $key = $this->addTicket('Cart total shows the wrong sum', ['core' => 'progress', 'reporter' => 'Raj', 'assignee' => 'Sara']);
        $this->removeMembership($this->people['Raj'], $this->acmeFoods);
        $this->removeMembership($this->people['Sara'], $this->acmeFoods);
        $this->logIn($this->client, $this->people['Lena']);

        $page = $this->client->request('GET', '/t/'.$key);

        self::assertResponseStatusCodeSame(200);
        $dialog = $page->filter('dialog#needs-input-'.$this->stepsByCore['input']);
        self::assertSame([['Someone else', '', true]], self::waitingOnChoicesOf($dialog));
        self::assertNull($dialog->filter('input.waiting-on__label')->attr('disabled'));
    }

    /**
     * Raj reported the ticket, Omar has it, and it waits in Code review on Lena, the lead the move stored as reviewer;
     * Mia, the team's tester, comes next. Each of the three sees the same rows, with "(you)" on their own.
     */
    #[Test]
    public function thePeopleSectionListsTheTicketsPeopleWithTheirRolesTheTurnAndTheViewer(): void
    {
        $this->makeShopPeopleDevelopersAndTester();
        $key = $this->addTicket('Cart total shows the wrong sum', ['core' => 'review', 'reporter' => 'Raj', 'assignee' => 'Omar']);
        $this->committedAs($this->acmeFoods, fn (Connection $migrator): int|string => $migrator->executeStatement(
            'UPDATE tkt_ticket SET reviewer_id = ? WHERE key = ?',
            [$this->people['Lena']->id()->toString(), $key],
        ));
        $rows = [
            'Omar' => ['Omar', 'Assignee · Developer'],
            'Raj' => ['Raj', 'Reporter'],
            'Lena' => ['Lena', 'Code reviewer · Lead · their turn'],
            'Mia' => ['Mia', 'Tester for the team'],
        ];

        foreach (['Lena', 'Omar', 'Mia'] as $viewer) {
            $this->logIn($this->client, $this->people[$viewer]);
            $page = $this->client->request('GET', '/t/'.$key);

            self::assertResponseStatusCodeSame(200);
            $expectedRows = $rows;
            $expectedRows[$viewer][0] .= ' (you)';
            self::assertSame(array_values($expectedRows), self::peopleRowsOf($page), $viewer.' sees the rows');
        }
    }

    /**
     * Done: the tester and the reviewer are no longer listed, and nobody's turn is marked.
     */
    #[Test]
    public function aDoneTicketListsOnlyItsAssigneeAndReporter(): void
    {
        $this->makeShopPeopleDevelopersAndTester();
        $key = $this->addTicket('Cart total shows the wrong sum', ['core' => 'done', 'reporter' => 'Mia', 'assignee' => 'Omar']);
        $this->logIn($this->client, $this->people['Raj']);

        $page = $this->client->request('GET', '/t/'.$key);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([['Omar', 'Assignee · Developer'], ['Mia', 'Reporter · Tester']], self::peopleRowsOf($page));
    }

    /**
     * Seven moves, the oldest one past the six shown, and the ticket's own "created" row, which the page words from
     * the ticket instead. Mia's take and Sara's move share a time, so the later id comes first; Sara moves as an admin
     * outside the ticket's people and its team, so her name comes from the extra names read.
     */
    #[Test]
    public function whatHappenedListsTheLatestSixMovesNewestFirstThenTheCreatedLine(): void
    {
        new AddTicketEventPartitions($this->migrator(), new MockClock(new \DateTimeImmutable(self::NOW)))();
        $key = $this->addTicket('Cart total shows the wrong sum', ['core' => 'progress', 'reporter' => 'Raj', 'assignee' => 'Omar']);
        $this->addHistoryRow($key, 'created', 'Raj', null, 'backlog', [], '2026-11-04 23:30:00+00');
        $this->addHistoryRow($key, 'moved', 'Lena', 'backlog', 'todo', ['verb' => 'plan'], '2026-11-05 08:00:00+00');
        $this->addHistoryRow($key, 'moved', 'Lena', 'todo', 'todo', ['verb' => MoveTicket::GIVE_IT_TO, TicketHistory::GIVEN_TO_PAYLOAD_KEY => $this->people['Omar']->id()->toString()], '2026-11-05 09:00:00+00');
        $this->addHistoryRow($key, 'moved', 'Omar', 'todo', 'progress', ['verb' => 'start'], '2026-11-05 10:00:00+00');
        $this->addHistoryRow($key, 'moved', 'Omar', 'progress', 'review', ['verb' => 'ready'], '2026-11-06 09:00:00+00');
        $this->addHistoryRow($key, 'moved', 'Lena', 'review', 'test', ['verb' => 'approve'], '2026-11-06 11:30:00+00');
        $this->addHistoryRow($key, 'moved', 'Mia', 'test', 'test', ['verb' => MoveTicket::GIVE_IT_TO, TicketHistory::GIVEN_TO_PAYLOAD_KEY => $this->people['Mia']->id()->toString()], '2026-11-06 11:59:30+00');
        $this->addHistoryRow($key, 'moved', 'Sara', 'test', 'progress', [], '2026-11-06 11:59:30+00');
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', '/t/'.$key);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([
            'now · Sara moved it from Testing to In progress',
            'now · Mia took it',
            '30 min ago · Lena moved it from Code review to Testing',
            '3 h ago · Omar moved it from In progress to Code review',
            '5 Nov · Omar moved it from To do to In progress',
            '5 Nov · Lena gave it to Omar',
            '5 Nov · Created by Raj',
        ], self::activityLinesOf($page));
    }

    #[Test]
    public function aTicketWithNoMovesShowsOnlyItsCreatedLine(): void
    {
        $key = $this->addTicket('Cart total shows the wrong sum', ['reporter' => 'Mia']);
        $this->logIn($this->client, $this->people['Lena']);

        $page = $this->client->request('GET', '/t/'.$key);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['5 Nov · Created by Mia'], self::activityLinesOf($page));
    }

    #[Test]
    public function aRenderedVerbFormMovesTheTicket(): void
    {
        new AddTicketEventPartitions($this->migrator(), new MockClock(new \DateTimeImmutable(self::NOW)))();
        $key = $this->addTicket('Add a gift note', ['core' => 'backlog']);
        [$stepBefore, $versionBefore] = $this->stepAndVersionOf($key);
        $this->logIn($this->client, $this->people['Lena']);
        $page = $this->client->request('GET', '/t/'.$key);
        self::assertResponseStatusCodeSame(200);

        $this->client->submit($page->selectButton('Plan it')->form());

        self::assertResponseStatusCodeSame(303);
        self::assertSame($this->stepsByCore['backlog'], $stepBefore);
        self::assertSame([$this->stepsByCore['todo'], $versionBefore + 1], $this->stepAndVersionOf($key));
        $page = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSame('Planned. It is in To do now.', $page->filter('.toast-region [data-toast-message-value]')->attr('data-toast-message-value'));
    }

    /**
     * One wrapping row: the back button, then the key with a dot, the type and the project, then the position last,
     * so it sits on the right edge.
     */
    #[Test]
    public function theBackButtonTheKeyLineAndThePositionShareOneRowInThatOrder(): void
    {
        $keys = $this->addTicketsToWalk();
        $this->logIn($this->client, $this->people['Lena']);

        $page = $this->client->request('GET', '/t/'.$keys['D'].'?project=SHOP');

        self::assertResponseStatusCodeSame(200);
        $row = $page->filter('.ticket__key-row');
        self::assertCount(1, $row);
        self::assertSame(['ticket__back', 'ticket__key-line', 'ticket__position'], $row->children()->each(static fn (Crawler $child): string => preg_replace('/^.*?(ticket__(?:back|key-line|position))\b.*$/', '$1', (string) $child->attr('class')) ?? self::fail('The class of a header child could not be read.')));
        self::assertSame('SHOP-5 · Bug · Shop', preg_replace('/\s+/', ' ', $row->filter('.ticket__key-line')->text()));
        self::assertSame('false', $row->filter('.ticket__position')->attr('data-turbo-prefetch'));
    }

    /**
     * SHOP's list holds seven open tickets; the third is D, after A in Code review and F in In progress, so Previous
     * crosses back into the earlier step. Following Next from A gives the list's whole order, with H before C and B
     * (newer), C before B (tied, higher id) and the Done and OPS tickets left out.
     */
    #[Test]
    public function aProjectsListShowsTheTicketsPlaceAndWalksItsOrderKeepingTheProject(): void
    {
        $keys = $this->addTicketsToWalk();
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', '/t/'.$keys['D'].'?project=SHOP');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['3 of 7', '/t/'.$keys['F'].'?project=SHOP', '/t/'.$keys['H'].'?project=SHOP'], $this->positionOf($page));
        self::assertSame('/tickets?project=SHOP', $page->filter('.ticket__back')->attr('href'));
        self::assertSame('false', $page->filter('.ticket__position')->attr('data-turbo-prefetch'));
        self::assertSame([$keys['A'], $keys['F'], $keys['D'], $keys['H'], $keys['C'], $keys['B'], $keys['E']], $this->keysWalkedFrom('/t/'.$keys['A'].'?project=SHOP'));
    }

    #[Test]
    public function thePreviousOfTheFirstTicketAndTheNextOfTheLastAreDisabledWithNoWrapAround(): void
    {
        $keys = $this->addTicketsToWalk();
        $this->logIn($this->client, $this->people['Omar']);

        $first = $this->client->request('GET', '/t/'.$keys['A'].'?project=SHOP');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['1 of 7', null, '/t/'.$keys['F'].'?project=SHOP'], $this->positionOf($first));
        self::assertSame(['true', 'link', 'Previous'], [$first->filter('.ticket__previous')->attr('aria-disabled'), $first->filter('.ticket__previous')->attr('role'), $first->filter('.ticket__previous')->text()]);

        $last = $this->client->request('GET', '/t/'.$keys['E'].'?project=SHOP');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['7 of 7', '/t/'.$keys['B'].'?project=SHOP', null], $this->positionOf($last));
        self::assertSame('true', $last->filter('.ticket__next-ticket')->attr('aria-disabled'));
        self::assertNull($first->filter('.ticket__next-ticket')->attr('aria-disabled'));
    }

    /**
     * R1 is critical, so the priority order would put it before R2; under the search the steps keep their order and
     * equal matches go by id, highest first. N matches nothing.
     */
    #[Test]
    public function aSearchListWalksTheSearchOrder(): void
    {
        $r1 = $this->addTicket('Refund email is late', ['priority' => 'critical']);
        $this->addTicket('Add a gift note');
        $r2 = $this->addTicket('Refund email is late', ['priority' => 'normal']);
        $r3 = $this->addTicket('Refund email is late', ['core' => 'review']);
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', '/t/'.$r2.'?q=refund');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['2 of 3', '/t/'.$r3.'?q=refund', '/t/'.$r1.'?q=refund'], $this->positionOf($page));
        self::assertSame('/tickets?q=refund', $page->filter('.ticket__back')->attr('href'));
        self::assertSame([$r3, $r2, $r1], $this->keysWalkedFrom('/t/'.$r3.'?q=refund'));
    }

    /**
     * The plain open list holds OPS-8, a blocker of another project, before D, and no Done ticket.
     */
    #[Test]
    public function withNoListTheTicketsPlaceIsInThePlainOpenList(): void
    {
        $keys = $this->addTicketsToWalk();
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', '/t/'.$keys['D']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['4 of 8', '/t/'.$keys['X'], '/t/'.$keys['H']], $this->positionOf($page));
        self::assertSame('/tickets', $page->filter('.ticket__back')->attr('href'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function listQueriesTheListRefuses(): iterable
    {
        yield 'a project of no key' => ['project=NOPE'];
        yield 'another organization\'s project' => ['project=BAKE'];
        yield 'a malformed person' => ['person=nobody'];
        yield 'a team of no one here' => ['team=0197a3c0-0000-7000-8000-00000000dead'];
        yield 'a team that is no uuid' => ['team=SHOP'];
        yield 'a search too long' => ['q='.str_repeat('a', 201)];
        yield 'a search that is not text' => ['q=check%C3out'];
        yield 'a search as an array' => ['q[]=refund'];
    }

    /**
     * A filter the list would answer with a 404 or a 400 only drops the list: the ticket still opens, in the plain
     * open list, and back goes to the plain list.
     */
    #[Test]
    #[DataProvider('listQueriesTheListRefuses')]
    public function aListQueryTheListRefusesFallsBackToThePlainOpenList(string $listQuery): void
    {
        $betaBakery = $this->createOrganization('Beta Bakery');
        $this->addProject(null, 'BAKE', 'Bakery', $betaBakery);
        $keys = $this->addTicketsToWalk();
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', '/t/'.$keys['D'].'?'.$listQuery);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['4 of 8', '/t/'.$keys['X'], '/t/'.$keys['H']], $this->positionOf($page));
        self::assertSame('/tickets', $page->filter('.ticket__back')->attr('href'));
    }

    /**
     * OPS-8 is not in SHOP's list, so it walks the plain open list; back still goes to the list the viewer came from.
     */
    #[Test]
    public function aTicketNotInItsListWalksThePlainOpenList(): void
    {
        $keys = $this->addTicketsToWalk();
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', '/t/'.$keys['X'].'?project=SHOP');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['3 of 8', '/t/'.$keys['F'], '/t/'.$keys['D']], $this->positionOf($page));
        self::assertSame('/tickets?project=SHOP', $page->filter('.ticket__back')->attr('href'));
    }

    #[Test]
    public function aDoneTicketHasAPlaceOnlyInAListWithDone(): void
    {
        $keys = $this->addTicketsToWalk();
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', '/t/'.$keys['G']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Footer links point nowhere', $page->filter('h1')->text());
        self::assertCount(0, $page->filter('.ticket__position'));

        $page = $this->client->request('GET', '/t/'.$keys['G'].'?done=1');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['9 of 9', '/t/'.$keys['E'].'?done=1', null], $this->positionOf($page));
    }

    /**
     * The costliest page: a project list that does not hold the ticket (the project lookup and the plain list's
     * position), a saved turn of someone outside the ticket's people and team and a move by another such person (one
     * more names query for both), What happened's moves (one query), the lead's Needs input dialog (one membership
     * read), and People's Ping button for Omar, whose ping limit comes with the settings and whose pinged state comes
     * from the ping window on Valkey, so it reads none. The ask's current summary and its count of earlier ones come
     * with the ticket, and its author, on the page for nothing else, comes in the names batch. Conversation's thread and the Rounds section load in lazy frames
     * of their own requests (TicketThreadTest, RoundsFrameTest), so the page reads none of them. Acme Foods' AI runs
     * in the cloud, so the ask reads no laptop summary proposal.
     */
    #[Test]
    public function theCostliestWarmPageStaysWithinTheTenQueryBudget(): void
    {
        self::assertSame(10, $this->queryCountOfTheCostliestWarmPage());
    }

    /**
     * The same page in an organization whose AI runs on laptops: the ask reads its laptop summary proposal in one more
     * indexed query (LocalAi::proposalOf); the AI settings come with the settings read the page makes anyway.
     */
    #[Test]
    public function theCostliestWarmPageOfALaptopAiOrganizationReadsTheProposalInOneMoreQuery(): void
    {
        $this->storeAiSettings($this->acmeFoods, self::laptopAi());

        self::assertSame(11, $this->queryCountOfTheCostliestWarmPage());
    }

    private function queryCountOfTheCostliestWarmPage(): int
    {
        new AddTicketEventPartitions($this->migrator(), new MockClock(new \DateTimeImmutable(self::NOW)))();
        $keys = $this->addTicketsToWalk();
        $this->committedAs($this->acmeFoods, fn (Connection $migrator): int|string => $migrator->executeStatement(
            'UPDATE tkt_ticket SET assignee_id = ? WHERE key = ?',
            [$this->people['Omar']->id()->toString(), $keys['X']],
        ));
        $this->addTurnRow($keys['X'], 'Raj', 'decide');
        $this->addHistoryRow($keys['X'], 'moved', 'Sara', 'todo', 'todo', [], '2026-11-06 11:00:00+00');
        // Mia, a viewer of Shop, is on the page only as the current summary's author.
        $this->addSummary($keys['X'], 'ai', 'Mia', true, '2026-11-05 10:00:00+00');
        $this->addSummary($keys['X'], 'ai', 'Omar', false, '2026-11-04 10:00:00+00');
        $this->addSummary($keys['X'], 'sync', null, false, '2026-11-03 10:00:00+00');
        $this->logIn($this->client, $this->people['Lena']);
        $this->queryCountOf(self::SHELL);
        $shellQueryCount = $this->queryCountOf(self::SHELL);
        $uri = '/t/'.$keys['X'].'?project=SHOP';
        $this->queryCountOf($uri);

        $pageQueryCount = $this->queryCountOf($uri) - $shellQueryCount;

        $ask = $this->client->getCrawler()->filter('turbo-frame#ticket-ask');
        self::assertSame('In short AI SUMMARY written on 5 Nov at Mia’s request', $ask->filter('.ticket__ask--summary > .ticket__ask-source')->text());
        self::assertSame('Show 2 earlier summaries', $ask->filter('.ticket__ask-earlier .ticket__ask-show')->text());
        self::assertStringContainsString('Raj', (string) $this->turnLineOf($this->client->getCrawler(), 'now'));
        self::assertSame(['1 h ago · Sara moved it to To do', '5 Nov · Created by Lena'], self::activityLinesOf($this->client->getCrawler()));
        self::assertCount(1, $this->client->getCrawler()->filter('.ticket__position'));
        self::assertSame('Ping Omar', trim($this->client->getCrawler()->filter('#ping-'.$this->people['Omar']->id()->toString().' .dialog__title')->text()));

        return $pageQueryCount;
    }

    /**
     * Beyond the shell, with the steps, the rules and the team's people cached: the settings (step policy and triager,
     * one query), the ticket, the names of everyone in one batch, What happened's moves in one, the saved turn with the
     * waiting turns in one query, the position in one, and the lead's Needs input dialog asks which of the ticket's
     * people are still members in one; a project's list adds the project by key, and a list the ticket is not in adds
     * the plain list's position.
     */
    #[Test]
    public function aWarmPageReadsOneQueryForItsPlaceInEachList(): void
    {
        $keys = $this->addTicketsToWalk();
        $this->logIn($this->client, $this->people['Lena']);
        $this->queryCountOf(self::SHELL);
        $shellQueryCount = $this->queryCountOf(self::SHELL);
        $this->queryCountOf('/t/'.$keys['D']);

        $queryCounts = [];
        foreach (['/t/'.$keys['D'], '/t/'.$keys['D'].'?project=SHOP', '/t/'.$keys['D'].'?q=checkout', '/t/'.$keys['X'].'?project=SHOP'] as $uri) {
            $queryCounts[] = $this->queryCountOf($uri) - $shellQueryCount;
            self::assertCount(1, $this->client->getCrawler()->filter('.ticket__position'), $uri);
        }

        self::assertSame([7, 8, 7, 9], $queryCounts);
    }

    /**
     * Beyond the shell, with the steps, the rules and the team's people cached: the settings (step policy and triager,
     * one query), the ticket, the names of everyone in one batch, What happened's moves in one, the saved turn with the
     * waiting turns in one query, the ticket's place in the plain open list in one, for the lead's Needs input dialog
     * which of the ticket's people are still members in one. People's Ping buttons read none: the ping limit comes with
     * the settings and the pinged state from the ping window on Valkey.
     */
    #[Test]
    public function aWarmPageReadsTheBudgetedQueriesBeyondTheShell(): void
    {
        $this->makeShopPeopleDevelopersAndTester();
        $parentKey = $this->addTicket('Checkout rework');
        $key = $this->addTicket('Cart total shows the wrong sum', ['assignee' => 'Omar', 'release' => '2.4', 'parent' => $parentKey, 'components' => '{Checkout}', 'core' => 'review']);
        $this->logIn($this->client, $this->people['Lena']);
        // logIn() leaves the user in the entity manager, so the first request skips the user refresh every later one pays.
        $this->queryCountOf(self::SHELL);
        $shellQueryCount = $this->queryCountOf(self::SHELL);
        // The first load fills the organization's cached steps and the team's cached rules and people.
        $this->queryCountOf('/t/'.$key);

        $pageQueryCount = $this->queryCountOf('/t/'.$key) - $shellQueryCount;

        self::assertSame([['Assignee', 'Omar'], ['Priority', 'Major'], ['Release', '2.4'], ['Parent', 'SHOP-1'], ['Components', 'Checkout'], ['Created', '5 Nov']], $this->detailsOf($this->client->getCrawler()));
        self::assertSame('Lena reviews the code', $this->turnLineOf($this->client->getCrawler(), 'now'));
        self::assertSame(
            ['Lena · Reporter', 'Omar · Assignee', 'Someone else'],
            $this->client->getCrawler()->filter('dialog#needs-input-'.$this->stepsByCore['input'].' .waiting-on__choice')->each(static fn (Crawler $choice): string => $choice->text()),
        );
        self::assertSame('Ping Omar', trim($this->client->getCrawler()->filter('#ping-'.$this->people['Omar']->id()->toString().' .dialog__title')->text()));
        self::assertSame(7, $pageQueryCount);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function viewersWithNoMoveList(): iterable
    {
        yield 'Omar, the assignee and a member' => ['Omar'];
        yield 'Raj, a manager with no team role' => ['Raj'];
    }

    /**
     * The same page as aWarmPageReadsTheBudgetedQueriesBeyondTheShell less the membership read: only a lead's move list
     * offers Needs input, so nobody else's page asks which of the ticket's people are still members.
     */
    #[Test]
    #[DataProvider('viewersWithNoMoveList')]
    public function aWarmPageWithNoNeedsInputDialogSkipsTheMembershipRead(string $viewer): void
    {
        $this->makeShopPeopleDevelopersAndTester();
        $parentKey = $this->addTicket('Checkout rework');
        $key = $this->addTicket('Cart total shows the wrong sum', ['assignee' => 'Omar', 'release' => '2.4', 'parent' => $parentKey, 'components' => '{Checkout}', 'core' => 'review']);
        $this->logIn($this->client, $this->people[$viewer]);
        $this->queryCountOf(self::SHELL);
        $shellQueryCount = $this->queryCountOf(self::SHELL);
        $this->queryCountOf('/t/'.$key);

        $pageQueryCount = $this->queryCountOf('/t/'.$key) - $shellQueryCount;

        self::assertSame('Lena reviews the code', $this->turnLineOf($this->client->getCrawler(), 'now'));
        self::assertCount(1, $this->client->getCrawler()->filter('.ticket__columns > .ticket__next-step > .next-step-box'));
        self::assertCount(0, $this->client->getCrawler()->filter('details.next-step-box__move-to, dialog:not([id^="ping-"])'));
        self::assertSame(6, $pageQueryCount);
    }

    /**
     * Inserted out of list order. SHOP's open list: A (Code review), F (In progress), then To do: D (critical), H
     * (major, newest), C and B (major, tied on updated_at, C with the higher id), E (minor). OPS-8, a blocker in To do
     * of project OPS, is only in the plain list; G is in Done.
     *
     * @return array<string, string> each ticket's key by its letter
     */
    private function addTicketsToWalk(): array
    {
        $opsProject = $this->addProject($this->shop, 'OPS', 'Operations');
        $keys = [];
        $keys['E'] = $this->addTicket('Coupon field takes any text', ['priority' => 'minor', 'updatedAt' => '2026-11-10 12:00:00+00']);
        $keys['H'] = $this->addTicket('Cart icon overlaps the menu', ['updatedAt' => '2026-11-10 10:00:00+00']);
        $keys['B'] = $this->addTicket('Add a gift note');
        $keys['F'] = $this->addTicket('Cart total shows the wrong sum', ['core' => 'progress']);
        $keys['D'] = $this->addTicket('Checkout button is hidden', ['priority' => 'critical', 'updatedAt' => '2026-11-09 08:00:00+00']);
        $keys['C'] = $this->addTicket('Add a gift wrap');
        $keys['A'] = $this->addTicket('Search shows no results', ['core' => 'review']);
        $keys['X'] = $this->addTicket('Stock count is off', ['priority' => 'blocker', 'project' => [$opsProject, 'OPS']]);
        $keys['G'] = $this->addTicket('Footer links point nowhere', ['group' => 'done']);
        self::assertSame('OPS-8', $keys['X']);

        return $keys;
    }

    /**
     * @return array{string, string|null, string|null} the "N of M" line and the Previous and Next links; null for a
     *                                                 disabled one
     */
    private function positionOf(Crawler $page): array
    {
        $position = $page->filter('.ticket__position');
        self::assertCount(1, $position, 'The page has no position line.');

        return [
            $position->filter('.ticket__position-line')->text(),
            $position->filter('.ticket__previous')->attr('href'),
            $position->filter('.ticket__next-ticket')->attr('href'),
        ];
    }

    /**
     * @return list<string> the key of each page from the first, following Next until it is disabled
     */
    private function keysWalkedFrom(string $uri): array
    {
        $keys = [];
        $nextUri = $uri;
        while (null !== $nextUri && \count($keys) < 20) {
            $page = $this->client->request('GET', $nextUri);
            self::assertResponseStatusCodeSame(200);
            $keys[] = (string) $page->filter('.ticket')->attr('data-ticket-key');
            $nextUri = $this->positionOf($page)[2];
        }

        return $keys;
    }

    /**
     * The test env's error page quotes the test's own source around each call, so the title is a constant: its text
     * shows in the body only when the page leaks it.
     */
    private function assertNotFoundWithNoWordOfTheTicket(): void
    {
        self::assertResponseStatusCodeSame(404);
        self::assertCount(0, $this->client->getCrawler()->filter('.ticket'));
        self::assertStringNotContainsString(self::HIDDEN_TITLE, (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString(self::HIDDEN_DESCRIPTION, (string) $this->client->getResponse()->getContent());
    }

    /**
     * @return string|null the text after "Now:" or "Next:"; null when the page has no such line
     */
    private function turnLineOf(Crawler $page, string $line): ?string
    {
        $paragraph = $page->filter('.ticket__'.$line);
        if (0 === $paragraph->count()) {
            return null;
        }

        return preg_replace('/^'.ucfirst($line).':\s+/', '', $paragraph->text());
    }

    /**
     * Asserts the People section sits in the aside, in its Turbo Stream target, and has rows.
     *
     * @return list<array{string, string}> each row's name and role line, in order
     */
    private static function peopleRowsOf(Crawler $page): array
    {
        $section = $page->filter('.ticket__aside #'.TicketPageParts::PEOPLE_ID.' section[aria-labelledby="ticket-people-heading"]');
        self::assertCount(1, $section);
        self::assertSame('People', $section->filter('#ticket-people-heading')->text());
        $rows = $section->filter('.ticket-people__row')->each(static fn (Crawler $row): array => [$row->filter('.ticket-people__name')->text(), $row->filter('.ticket-people__role')->text()]);
        self::assertNotEmpty($rows);

        return $rows;
    }

    /**
     * Asserts What happened sits in the aside, in its Turbo Stream target.
     *
     * @return list<string> each line's text, in order
     */
    private static function activityLinesOf(Crawler $page): array
    {
        $section = $page->filter('.ticket__aside #'.TicketPageParts::ACTIVITY_ID.' section[aria-labelledby="ticket-activity-heading"]');
        self::assertCount(1, $section);
        self::assertSame('What happened', $section->filter('#ticket-activity-heading')->text());

        return $section->filter('.activity__line')->each(static fn (Crawler $line): string => $line->text());
    }

    /**
     * A row of the ticket's history as TicketHistory writes it; its id is a uuid v7, so a later row has the higher id.
     *
     * @param array<string, string> $payload
     */
    private function addHistoryRow(string $ticketKey, string $type, string $actor, ?string $fromCore, string $toCore, array $payload, string $at): void
    {
        $parameters = [
            Uuid::v7()->toRfc4122(), $this->acmeFoods->toString(), $ticketKey, $this->people[$actor]->id()->toString(), $type,
            null === $fromCore ? null : $this->stepsByCore[$fromCore], $this->stepsByCore[$toCore], json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_FORCE_OBJECT), $at,
        ];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            'INSERT INTO tkt_ticket_event (id, organization_id, ticket_id, actor_id, type, from_step, to_step, payload, at)
             VALUES (?, ?, (SELECT id FROM tkt_ticket WHERE key = ?), ?, ?, ?, ?, CAST(? AS jsonb), CAST(? AS timestamptz))',
            $parameters,
        ));
    }

    /**
     * A summary of the ticket as Ticketing stores one; only an 'ai' summary names a model and prompt version.
     */
    private function addSummary(string $ticketKey, string $source, ?string $author, bool $isCurrent, string $createdAt): void
    {
        $isAi = 'ai' === $source;
        $parameters = [
            $this->acmeFoods->toString(), Uuid::v7()->toRfc4122(), $ticketKey, 'Pay does nothing on the last step.', $source,
            null === $author ? null : $this->people[$author]->id()->toString(), $isAi ? 'test-model' : null, $isAi ? 'summary.v1' : null,
            $isCurrent ? 'true' : 'false', $createdAt,
        ];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            'INSERT INTO tkt_summary (organization_id, id, ticket_id, text, source, author_id, model, prompt_version, is_current, created_at)
             VALUES (?, ?, (SELECT id FROM tkt_ticket WHERE key = ?), ?, ?, ?, ?, ?, ?, ?)',
            $parameters,
        ));
    }

    /**
     * Omar builds, Mia tests: Shop's team has one developer and one tester besides its lead, who reviews. Mia is a
     * member rather than the viewer the other tests make her.
     */
    private function makeShopPeopleDevelopersAndTester(): void
    {
        $this->committedAs($this->acmeFoods, function (Connection $migrator): int {
            $migrator->executeStatement("UPDATE org_team_member SET job = 'developer' WHERE team_id = ? AND user_id = ?", [$this->shop->toString(), $this->people['Omar']->id()->toString()]);

            return (int) $migrator->executeStatement("UPDATE org_team_member SET team_role = 'member', job = 'tester' WHERE team_id = ? AND user_id = ?", [$this->shop->toString(), $this->people['Mia']->id()->toString()]);
        });
    }

    private function addSecondShopTester(): void
    {
        $this->people['Noor'] = $this->createUserWithoutOrganization('Noor');
        $this->addMembership($this->people['Noor'], $this->acmeFoods);
        $this->addTeamRole($this->people['Noor'], $this->acmeFoods, $this->shop, 'member');
        $this->setShopJob('Noor', 'tester');
    }

    private function addSecondShopDeveloper(): void
    {
        $this->people['Pia'] = $this->createUserWithoutOrganization('Pia');
        $this->addMembership($this->people['Pia'], $this->acmeFoods);
        $this->addTeamRole($this->people['Pia'], $this->acmeFoods, $this->shop, 'member');
        $this->setShopJob('Pia', 'developer');
    }

    private function setShopJob(string $person, string $job): void
    {
        $this->committedAs($this->acmeFoods, fn (Connection $migrator): int|string => $migrator->executeStatement(
            'UPDATE org_team_member SET job = ? WHERE team_id = ? AND user_id = ?',
            [$job, $this->shop->toString(), $this->people[$person]->id()->toString()],
        ));
    }

    /**
     * The row Insight keeps for a ticket's turn, as the turn projector would have written it.
     */
    private function addTurnRow(string $ticketKey, ?string $turnPerson, string $kind, ?string $turnLabel = null): void
    {
        $this->committedAs($this->acmeFoods, fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO ins_ticket_turn (organization_id, ticket_id, team_id, project_id, grp, turn_user_id, turn_label, kind, base_score, waiting_since, visibility, reporter_id, assignee_id)
             SELECT organization_id, id, team_id, project_id, 'check', ?, ?, ?, 100, TIMESTAMPTZ '2026-11-05 10:00:00+00', visibility, reporter_id, assignee_id
             FROM tkt_ticket WHERE key = ?",
            [null === $turnPerson ? null : $this->people[$turnPerson]->id()->toString(), $turnLabel, $kind, $ticketKey],
        ));
    }

    /**
     * @return list<array{string, string|null, bool}> each choice's text, radio value and whether it is checked
     */
    private static function waitingOnChoicesOf(Crawler $dialog): array
    {
        self::assertCount(1, $dialog);

        return $dialog->filter('.waiting-on__choice')->each(static fn (Crawler $choice): array => [
            $choice->text(),
            $choice->filter('input[type="radio"]')->attr('value'),
            null !== $choice->filter('input[type="radio"]')->attr('checked'),
        ]);
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

    /**
     * @return list<array{string, string}> each Details row's term and value, in page order
     */
    private function detailsOf(Crawler $page): array
    {
        $rows = $page->filter('.ticket__details .ticket__detail')->each(static fn (Crawler $row): array => [$row->filter('dt')->text(), $row->filter('dd')->text()]);
        self::assertNotEmpty($rows);

        return $rows;
    }

    private function addTeamStep(TeamId $teamId, string $name, string $groupCode): void
    {
        $this->committedAs($this->acmeFoods, fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, ?, ?, ?, NULL, 'team', 9)",
            [$this->acmeFoods->toString(), Uuid::v7()->toRfc4122(), $teamId->toString(), $name, $groupCode],
        ));
    }

    /**
     * @return array{mixed, int}
     */
    private function stepAndVersionOf(string $key): array
    {
        $row = [];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($key, &$row): int {
            $row = $migrator->fetchAssociative('SELECT step_id, version FROM tkt_ticket WHERE key = ?', [$key]);

            return 0;
        });
        self::assertIsArray($row);
        self::assertIsInt($row['version']);

        return [$row['step_id'], $row['version']];
    }

    private function ticketIdOf(string $key): string
    {
        $ticketId = null;
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($key, &$ticketId): int {
            $ticketId = $migrator->fetchOne('SELECT id FROM tkt_ticket WHERE key = ?', [$key]);

            return 0;
        });
        self::assertIsString($ticketId);

        return $ticketId;
    }

    private function addSteps(): void
    {
        $organizationId = $this->acmeFoods->toString();
        $steps = [];
        foreach ([
            ['Needs input', 'wait', 'input'], ['Code review', 'check', 'review'], ['In progress', 'doing', 'progress'], ['To do', 'todo', 'todo'],
            ['Done', 'done', 'done'], ['Backlog', 'todo', 'backlog'], ['Testing', 'check', 'test'], ['On hold', 'wait', 'hold'],
        ] as [$name, $groupCode, $coreCode]) {
            $stepId = Uuid::v7()->toRfc4122();
            $this->steps[$groupCode] ??= $stepId;
            $this->stepsByCore[$coreCode] = $stepId;
            $steps[] = [$stepId, $name, $groupCode, $coreCode, \count($steps)];
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
     * A major bug of Shop's team in project SHOP, for everyone, reported by Lena at CREATED_THIS_YEAR in To do and
     * updated at UPDATED_AT. Its id is a uuid v7, so a later ticket has the higher id. Details' project is the id and
     * key of another project.
     *
     * @param array{group?: string, type?: string, priority?: string, description?: string, release?: string, parent?: string, components?: string, assignee?: string, reporter?: string, visibility?: string, createdAt?: string, core?: string, updatedAt?: string, project?: array{string, string}} $details
     *
     * @return string the ticket's key
     */
    private function addTicket(string $title, array $details = []): string
    {
        $number = $this->nextNumber++;
        [$projectId, $projectKey] = $details['project'] ?? [$this->shopProject, 'SHOP'];
        $key = $projectKey.'-'.$number;
        $priority = $details['priority'] ?? 'major';
        $parameters = [
            $this->acmeFoods->toString(), Uuid::v7()->toRfc4122(), $projectId, $this->shop->toString(), $number, $key, $title,
            $details['description'] ?? self::HIDDEN_DESCRIPTION, $details['type'] ?? 'bug', $details['release'] ?? null, $priority,
            self::PRIORITY_RANKS[$priority], isset($details['core']) ? $this->stepsByCore[$details['core']] : $this->steps[$details['group'] ?? 'todo'], $this->people[$details['reporter'] ?? 'Lena']->id()->toString(),
            $details['visibility'] ?? 'all', isset($details['assignee']) ? $this->people[$details['assignee']]->id()->toString() : null,
            $details['parent'] ?? null, $details['components'] ?? '{}', $details['createdAt'] ?? self::CREATED_THIS_YEAR, $details['updatedAt'] ?? self::UPDATED_AT,
        ];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO tkt_ticket (organization_id, id, project_id, team_id, number, key, title, description, type, release, priority,
                 priority_rank, step_id, reporter_id, visibility, assignee_id, parent_id, components, check_reasons, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                 (SELECT parent.id FROM tkt_ticket AS parent WHERE parent.key = ?), CAST(? AS text[]), '{}', CAST(? AS timestamptz), CAST(? AS timestamptz))",
            $parameters,
        ));

        return $key;
    }
}
