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

    private const string SHELL = '/_test/menu/shell';
    private const string NOW = '2026-11-06 12:00:00 UTC';
    private const string CREATED_THIS_YEAR = '2026-11-04 23:30:00+00';
    private const string ROUNDS_EMPTY = 'No rounds yet. The developer starts round 1 from Claude Code: it shoots the screens and puts them here.';
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
                $migrator->executeStatement('DELETE FROM ins_ticket_turn WHERE organization_id = ?', [$organizationId]);
                $migrator->executeStatement('DELETE FROM tkt_ticket WHERE organization_id = ?', [$organizationId]);
                $migrator->executeStatement('DELETE FROM wf_step WHERE organization_id = ?', [$organizationId]);

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
        self::assertSame(self::ROUNDS_EMPTY, $page->filter('[aria-labelledby="ticket-rounds-heading"] .empty-state')->text());
        self::assertSame([['Assignee', 'Nobody'], ['Priority', 'Major'], ['Created', '5 Nov']], $this->detailsOf($page));
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
        $this->logIn($this->client, $this->people['Sara']);

        $page = $this->client->request('GET', '/t/'.$key);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Waiting for input from Raj', $this->turnLineOf($page, 'now'));
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
    }

    /**
     * Beyond the shell, with the steps and the rules cached: the settings (step policy and triager, one query), the
     * ticket, its team's lead and people, the names of everyone in one batch, and the saved turn with the waiting
     * turns in one query.
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
        self::assertSame(6, $pageQueryCount);
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
    private function addTurnRow(string $ticketKey, string $turnPerson, string $kind): void
    {
        $this->committedAs($this->acmeFoods, fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO ins_ticket_turn (organization_id, ticket_id, team_id, project_id, grp, turn_user_id, kind, base_score, waiting_since, visibility, reporter_id, assignee_id)
             SELECT organization_id, id, team_id, project_id, 'check', ?, ?, 100, TIMESTAMPTZ '2026-11-05 10:00:00+00', visibility, reporter_id, assignee_id
             FROM tkt_ticket WHERE key = ?",
            [$this->people[$turnPerson]->id()->toString(), $kind, $ticketKey],
        ));
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
     * A major bug of Shop's team, for everyone, reported by Lena at CREATED_THIS_YEAR in To do.
     *
     * @param array{group?: string, type?: string, priority?: string, description?: string, release?: string, parent?: string, components?: string, assignee?: string, reporter?: string, visibility?: string, createdAt?: string, core?: string} $details
     *
     * @return string the ticket's key
     */
    private function addTicket(string $title, array $details = []): string
    {
        $number = $this->nextNumber++;
        $key = 'SHOP-'.$number;
        $priority = $details['priority'] ?? 'major';
        $parameters = [
            $this->acmeFoods->toString(), Uuid::v7()->toRfc4122(), $this->shopProject, $this->shop->toString(), $number, $key, $title,
            $details['description'] ?? self::HIDDEN_DESCRIPTION, $details['type'] ?? 'bug', $details['release'] ?? null, $priority,
            self::PRIORITY_RANKS[$priority], isset($details['core']) ? $this->stepsByCore[$details['core']] : $this->steps[$details['group'] ?? 'todo'], $this->people[$details['reporter'] ?? 'Lena']->id()->toString(),
            $details['visibility'] ?? 'all', isset($details['assignee']) ? $this->people[$details['assignee']]->id()->toString() : null,
            $details['parent'] ?? null, $details['components'] ?? '{}', $details['createdAt'] ?? self::CREATED_THIS_YEAR,
        ];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO tkt_ticket (organization_id, id, project_id, team_id, number, key, title, description, type, release, priority,
                 priority_rank, step_id, reporter_id, visibility, assignee_id, parent_id, components, check_reasons, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                 (SELECT parent.id FROM tkt_ticket AS parent WHERE parent.key = ?), CAST(? AS text[]), '{}', CAST(? AS timestamptz), TIMESTAMPTZ '2026-11-10 09:00:00+00')",
            $parameters,
        ));

        return $key;
    }
}
