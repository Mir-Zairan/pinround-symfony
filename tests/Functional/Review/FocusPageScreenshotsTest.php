<?php

declare(strict_types=1);

namespace App\Tests\Functional\Review;

use App\Identity\Domain\User;
use App\Review\UI\Component\RoundsSection;
use App\Review\UI\Controller\RoundController;
use App\Review\UI\RoundPage\RoundPages;
use App\Review\UI\RoundPage\ScreenshotPager;
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
 * The full-screen review of a round without screen areas (P14.T8 step 2): a shared draft, the report (Round 0) and a
 * checklist round, one screenshot at a time with its own pins, opened from the round page and from the report's card on
 * the ticket page, through the real firewall, voters, Media signing and database, and as a browser without JavaScript
 * uses it. The clock is fixed on 6 Nov 2026, before today. Omar owns the drafts; Mia is the Shop team's tester and Lena
 * its lead.
 */
final class FocusPageScreenshotsTest extends WebTestCase
{
    use CreatesAcmeFoodsTeams;
    use CreatesTeams;
    use Factories;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }
    use SeedsTicketRounds;

    private const string NOW = '2026-11-06 12:00:00 UTC';
    private const string SHARED_EARLIER = '2026-11-05 09:00:00+00';
    private const string SHARED_TODAY = '2026-11-06 09:12:00+00';
    private const string DRAFT_FOCUS = '/t/SHOP-1/rounds/1/focus';
    private const string OMARS_PRIVATE_TITLE = 'Omar private draft picture';
    /**
     * With the position, the titles a 404 must not show. The debug error page quotes the lines of this file around the
     * request, so a title checked for there is never spelled out in them.
     */
    private const string HIDDEN_TITLE_PREFIX = 'Screenshot titled ';
    private const array TURBO_FRAME_HEADERS = ['HTTP_TURBO_FRAME' => RoundsSection::FRAME_ID];
    /**
     * Fewer than the round page's (RoundPageScreenshotsTest::DRAFT_PAGE_QUERIES): no move-on buttons, no "Ready for"
     * header. Measured, and the same for 2 and 6 screenshots; the report's read as the first shared draft's Befores (four
     * queries, ADR-0048 amended 2026-11-21) raised it from 24, and the read of the check items of the walked pins (the
     * checklist round, then its items, RoundPages::checkRowsOf) from 28, and the one query that finds the shown picture is
     * no copy (RoundsVisibleTo::copySourceOf, RoundPages::pairedBeforeOf) from 30.
     */
    private const int DRAFT_FOCUS_QUERIES = 31;

    private KernelBrowser $client;

    /** @var array<string, User> */
    private array $people;

    private string $shopProject;
    private string $stepId;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTPS' => 'on']);
        $this->client->disableReboot();
        self::getContainer()->set('clock', new MockClock(new \DateTimeImmutable(self::NOW)));
        $this->people = $this->createAcmeFoodsPeople('member');
        $miaId = $this->people['Mia']->id()->toString();
        $organizationId = $this->acmeFoods->toString();
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "UPDATE org_team_member SET job = 'tester' WHERE organization_id = ? AND user_id = ?",
            [$organizationId, $miaId],
        ));
        $this->shopProject = $this->addProject($this->shop, 'SHOP', 'Shop');
        $this->stepId = Uuid::v7()->toRfc4122();
        $stepId = $this->stepId;
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, 'To do', 'todo', 'todo', 'core', 1)",
            [$organizationId, $stepId],
        ));
    }

    protected function tearDown(): void
    {
        $this->removeSeededRounds();
        $this->removeCommittedProjects();
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function aSharedDraftWithThreeScreenshotsOpensAtTheFirstWithItsOwnPinsAndPagesByTheScreenshotQuery(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $this->seedSharedDraft($ticketId, 1, 'Draft shot ', [2, 0, 1]);
        $this->logIn($this->client, $this->people['Mia']);

        $page = $this->client->request('GET', self::DRAFT_FOCUS);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('dark', $page->filter('html')->attr('data-theme'));
        self::assertSame('SHOP-1 · Round 1 · Draft shot 0 · screenshot 1 of 3', $page->filter('h1.focus-review__line')->text());
        self::assertSame('Draft shot 0', $page->filter('.focus-review__picture img.screenshot__image')->attr('alt'), 'Stored last first, shown in position order.');
        self::assertCount(0, $page->filter('.screenshot__text, .focus-review__sides'), 'No title above the picture and no earlier picture to switch to.');
        self::assertSame(
            [['Pin 1: Note 1', 'true'], ['Pin 2: Note 2', 'false']],
            $page->filter('.focus-review__picture button.pin')->each(static fn (Crawler $pin): array => [(string) $pin->attr('aria-label'), (string) $pin->attr('aria-pressed')]),
            'The screenshot\'s own pins, a dismissed AI guess left out, the first selected.',
        );
        self::assertSame(
            [['Pin 1 of 2 · Mia', 'Note 1', false], ['Pin 2 of 2 · Mia', 'Note 2', true]],
            $page->filter('.focus-bar__stop')->each(static fn (Crawler $stop): array => [$stop->filter('.focus-bar__label')->text(), $stop->filter('.focus-bar__note')->text(), null !== $stop->attr('hidden')]),
        );
        self::assertSame(
            ['/t/SHOP-1/rounds/1/focus?screenshot=1&pin=2', '/t/SHOP-1/rounds/1/focus?screenshot=1&pin=2'],
            $page->filter('.focus-bar__stop')->first()->filter('a.focus-bar__step')->each(static fn (Crawler $link): string => (string) $link->attr('href')),
            'Two pins wrap to each other, on the same screenshot, with no side to switch.',
        );
        self::assertCount(0, $page->filter('.focus-bar form'), 'A round without areas takes no verdict here.');
        self::assertSame(ScreenshotPager::NAV_LABEL, $page->filter('nav.focus-review__pager')->attr('aria-label'));
        $back = $page->filter('.focus-review__pager .focus-review__back');
        self::assertSame('true', $back->attr('aria-disabled'), 'There is no screenshot before the first.');
        self::assertNull($back->attr('href'));
        self::assertSame('/t/SHOP-1/rounds/1/focus?screenshot=2', $page->filter('.focus-review__pager .focus-review__next')->attr('href'));
        self::assertSame('/t/SHOP-1/rounds/1?screenshot=1#'.RoundController::FULL_SCREEN_CONTROL_ID, $page->filter('a.focus-review__exit')->attr('href'));

        $page = $this->client->request('GET', self::DRAFT_FOCUS.'?'.RoundController::SCREENSHOT_PARAMETER.'=2&'.RoundController::PIN_PARAMETER.'=2');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('SHOP-1 · Round 1 · Draft shot 1 · screenshot 2 of 3', $page->filter('h1.focus-review__line')->text());
        self::assertSame('Draft shot 1', $page->filter('.focus-review__picture img.screenshot__image')->attr('alt'));
        self::assertSame('This screenshot has no pins.', $page->filter('.focus-review__none')->text());
        self::assertCount(0, $page->filter('.focus-bar, button.pin'));
        self::assertSame('/t/SHOP-1/rounds/1/focus?screenshot=1', $page->filter('.focus-review__back')->attr('href'));
        self::assertSame('/t/SHOP-1/rounds/1/focus?screenshot=3', $page->filter('.focus-review__next')->attr('href'));
        self::assertSame('/t/SHOP-1/rounds/1?screenshot=2#'.RoundController::FULL_SCREEN_CONTROL_ID, $page->filter('a.focus-review__exit')->attr('href'));

        $page = $this->client->request('GET', self::DRAFT_FOCUS.'?'.RoundController::SCREENSHOT_PARAMETER.'=3');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Pin 1 of 1 · Mia', $page->filter('.focus-bar__stop:not([hidden]) .focus-bar__label')->text());
        $next = $page->filter('.focus-review__next');
        self::assertSame('true', $next->attr('aria-disabled'), 'There is no screenshot after the last.');
        self::assertNull($next->attr('href'));
    }

    /**
     * The full screen of Omar's first shared draft offers the same Before as its round page, the report's same-numbered
     * picture with its own pins; Screenshot 2 is numbered past the report's last and has none, even when the address
     * asks for it.
     */
    #[Test]
    public function aFirstSharedDraftsFullScreenSwitchesToTheReportsSameNumberedPicture(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $this->seedRoundOfScreenshots($ticketId, 0, 'report', 'Mia', 'Report shot ', [1]);
        $this->seedSharedDraft($ticketId, 1, 'Draft shot ', [2, 0]);
        $this->logIn($this->client, $this->people['Mia']);

        $page = $this->client->request('GET', self::DRAFT_FOCUS);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            [['After', '/t/SHOP-1/rounds/1/focus?screenshot=1&side=after'], ['Before', '/t/SHOP-1/rounds/1/focus?screenshot=1&side=before']],
            $page->filter('.focus-review__sides a')->each(static fn (Crawler $link): array => [$link->text(), (string) $link->attr('href')]),
        );
        self::assertSame('After, round 1: Draft shot 0', $page->filter('.focus-review__picture img.screenshot__image')->attr('alt'));
        self::assertCount(2, $page->filter('.focus-review__picture button.pin'));

        $page = $this->client->request('GET', self::DRAFT_FOCUS.'?side=before');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Before, round 0: Report shot 0', $page->filter('.focus-review__picture img.screenshot__image')->attr('alt'));
        self::assertSame(['Note 1'], $page->filter('.focus-bar__note')->each(static fn (Crawler $note): string => $note->text()), 'The Before walks the report picture\'s own pin.');

        $page = $this->client->request('GET', self::DRAFT_FOCUS.'?'.RoundController::SCREENSHOT_PARAMETER.'=2&side=before');

        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $page->filter('.focus-review__sides'), 'The report has no Screenshot 2.');
        self::assertSame('Draft shot 1', $page->filter('.focus-review__picture img.screenshot__image')->attr('alt'));
    }

    #[Test]
    public function theRoundPagesFullScreenLinkOpensItOnTheShownScreenshot(): void
    {
        $this->seedSharedDraft($this->addTicket('SHOP-1'), 1, 'Draft shot ', [1, 2, 0]);
        $this->logIn($this->client, $this->people['Lena']);

        $roundPage = $this->client->request('GET', '/t/SHOP-1/rounds/1?'.RoundController::SCREENSHOT_PARAMETER.'=2');

        self::assertResponseStatusCodeSame(200);
        $fullScreen = $roundPage->filter('a.round-page__full-screen');
        self::assertSame('Full screen', $fullScreen->text());
        self::assertSame(RoundController::FULL_SCREEN_CONTROL_ID, $fullScreen->attr('id'), 'The exit names it, so focus comes back to it.');
        self::assertSame('fragment-focus', $fullScreen->attr('data-controller'));
        $page = $this->client->click($fullScreen->link());

        self::assertResponseStatusCodeSame(200);
        self::assertSame('SHOP-1 · Round 1 · Draft shot 1 · screenshot 2 of 3', $page->filter('h1.focus-review__line')->text());
        self::assertSame(['Note 1', 'Note 2'], $page->filter('.focus-bar__note')->each(static fn (Crawler $note): string => $note->text()));
    }

    #[Test]
    public function theReportOpensFromItsCardOnTheTicketPageAndGoesBackThere(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $this->seedRoundOfScreenshots($ticketId, 0, 'report', 'Mia', 'Report shot ', [1, 2]);
        $this->seedSharedDraft($ticketId, 1, 'Later draft shot ', [1]);
        $this->logIn($this->client, $this->people['Omar']);

        $frame = $this->client->request('GET', '/t/SHOP-1/rounds', [], [], self::TURBO_FRAME_HEADERS);

        self::assertResponseStatusCodeSame(200);
        $fullScreen = $frame->filter('.round-card a.round-card__full-screen');
        self::assertCount(1, $fullScreen, 'Only the report\'s card has one; the shared draft is a row with its own page.');
        self::assertSame('Full screen', $fullScreen->text());
        self::assertSame('/t/SHOP-1/rounds/0/focus?'.RoundController::RETURN_PARAMETER.'='.RoundController::RETURN_TO_TICKET, $fullScreen->attr('href'));
        self::assertSame('_top', $fullScreen->attr('data-turbo-frame'));
        self::assertSame(RoundsSection::FRAME_ID, $fullScreen->attr('data-fragment-focus-fragment-value'));
        self::assertStringContainsString('Round 0', $fullScreen->closest('.round-card')?->filter('.round-card__headline')->text() ?? '');

        $page = $this->client->request('GET', $fullScreen->attr('href'));

        self::assertResponseStatusCodeSame(200);
        self::assertSame('SHOP-1 · Round 0 · Report shot 0 · screenshot 1 of 2', $page->filter('h1.focus-review__line')->text());
        self::assertSame('Report shot 0', $page->filter('.focus-review__picture img.screenshot__image')->attr('alt'));
        self::assertSame('/t/SHOP-1#'.RoundsSection::FRAME_ID, $page->filter('a.focus-review__exit')->attr('href'));
        self::assertSame('/t/SHOP-1/rounds/0/focus?screenshot=2&from=ticket', $page->filter('.focus-review__next')->attr('href'), 'Paging keeps the way back.');
        self::assertStringNotContainsString('Later draft shot', (string) $this->client->getResponse()->getContent());

        $page = $this->client->request('GET', '/t/SHOP-1/rounds/0/focus?screenshot=2&from=ticket');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            ['/t/SHOP-1/rounds/0/focus?screenshot=2&from=ticket&pin=2', '/t/SHOP-1/rounds/0/focus?screenshot=2&from=ticket&pin=2'],
            $page->filter('.focus-bar__stop')->first()->filter('a.focus-bar__step')->each(static fn (Crawler $link): string => (string) $link->attr('href')),
        );
        self::assertSame('/t/SHOP-1#'.RoundsSection::FRAME_ID, $page->filter('a.focus-review__exit')->attr('href'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function waysBackThatAreNotTheTicket(): iterable
    {
        yield 'none' => [''];
        yield 'another word' => ['&from=elsewhere'];
        yield 'the word in another case' => ['&from=Ticket'];
        yield 'a list' => ['&from[]=ticket'];
    }

    #[Test]
    #[DataProvider('waysBackThatAreNotTheTicket')]
    public function anyOtherWayBackGoesToTheRoundPage(string $query): void
    {
        $this->seedRoundOfScreenshots($this->addTicket('SHOP-1'), 0, 'report', 'Mia', 'Report shot ', [1]);
        $this->logIn($this->client, $this->people['Mia']);

        $page = $this->client->request('GET', '/t/SHOP-1/rounds/0/focus?screenshot=1'.$query);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('/t/SHOP-1/rounds/0?screenshot=1#'.RoundController::FULL_SCREEN_CONTROL_ID, $page->filter('a.focus-review__exit')->attr('href'));
        self::assertSame(['/t/SHOP-1/rounds/0/focus?screenshot=1&pin=1', '/t/SHOP-1/rounds/0/focus?screenshot=1&pin=1'], $page->filter('a.focus-bar__step')->each(static fn (Crawler $link): string => (string) $link->attr('href')));
    }

    #[Test]
    public function aReportWithoutScreenshotsHasNoFullScreenOnItsCard(): void
    {
        $this->addRound($this->addTicket('SHOP-1'), 0, 'report', 'Mia', self::SHARED_EARLIER);
        $this->logIn($this->client, $this->people['Mia']);

        $frame = $this->client->request('GET', '/t/SHOP-1/rounds', [], [], self::TURBO_FRAME_HEADERS);

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $frame->filter('.round-card'));
        self::assertCount(0, $frame->filter('a.round-card__full-screen'));

        $page = $this->client->request('GET', '/t/SHOP-1/rounds/0/focus');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(RoundPages::NO_SCREENSHOTS, $page->filter('.empty-state')->text());
        self::assertCount(0, $page->filter('.focus-review__pager, .focus-bar, .focus-review__picture'));
    }

    #[Test]
    public function theChecklistHasNoFullScreenOfItsOwnRoundZeroShowsItsPinsAndAnEmptyDraftSaysItHasNone(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $reportShots = $this->seedRoundOfScreenshots($ticketId, 0, 'report', 'Mia', 'Report shot ', [1, 0]);
        $this->addCheckItem($this->addRound($ticketId, null, 'checklist', 'Omar', self::SHARED_EARLIER), $this->addPin($reportShots[0], 9, '30.00', '30.00', 'A checked note', 'Mia'), 'open');
        $this->addRound($ticketId, 2, 'draft', 'Omar', self::SHARED_TODAY);
        $this->logIn($this->client, $this->people['Lena']);

        // The checklist has no number (ADR-0048, note of 2026-11-22), so no round number opens it.
        $this->client->request('GET', '/t/SHOP-1/rounds/1/focus');
        self::assertResponseStatusCodeSame(404);

        $page = $this->client->request('GET', '/t/SHOP-1/rounds/0/focus');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('SHOP-1 · Round 0 · Report shot 0 · screenshot 1 of 2', $page->filter('h1.focus-review__line')->text());
        self::assertSame('Report shot 0', $page->filter('.focus-review__picture img.screenshot__image')->attr('alt'));
        self::assertSame(['Note 1', 'A checked note'], $page->filter('.focus-bar__note')->each(static fn (Crawler $note): string => $note->text()));

        $page = $this->client->request('GET', '/t/SHOP-1/rounds/2/focus');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('SHOP-1 · Round 2', $page->filter('h1.focus-review__line')->text());
        self::assertSame(RoundPages::NO_SCREENSHOTS, $page->filter('.empty-state')->text());
        self::assertSame('/t/SHOP-1/rounds/2#'.RoundController::FULL_SCREEN_CONTROL_ID, $page->filter('a.focus-review__exit')->attr('href'));

        $this->client->request('GET', '/t/SHOP-1/rounds/2/focus?'.RoundController::SCREENSHOT_PARAMETER.'=1');

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function screenshotsTheRoundHasNot(): iterable
    {
        yield 'zero' => ['screenshot=0'];
        yield 'one past the last' => ['screenshot=4'];
        yield 'letters' => ['screenshot=abc'];
        yield 'a negative number' => ['screenshot=-1'];
        yield 'empty' => ['screenshot='];
        yield 'a number with a trailing newline' => ['screenshot=2%0A'];
        yield 'too many digits' => ['screenshot=9999999999'];
        yield 'a list' => ['screenshot[]=1'];
    }

    #[Test]
    #[DataProvider('screenshotsTheRoundHasNot')]
    public function aMalformedOrUnknownScreenshotNumberAnswers404(string $query): void
    {
        $this->seedSharedDraft($this->addTicket('SHOP-1'), 1, self::HIDDEN_TITLE_PREFIX, [1, 1, 1]);
        $this->logIn($this->client, $this->people['Mia']);

        $page = $this->client->request('GET', self::DRAFT_FOCUS.'?'.$query);

        self::assertResponseStatusCodeSame(404);
        self::assertCount(0, $page->filter('.focus-review, .focus-bar'));
        foreach ([0, 1, 2] as $position) {
            self::assertStringNotContainsString(self::HIDDEN_TITLE_PREFIX.$position, (string) $this->client->getResponse()->getContent());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function addressesOfNoRound(): iterable
    {
        yield 'an unknown number' => ['/t/SHOP-1/rounds/9/focus'];
        yield 'a malformed number' => ['/t/SHOP-1/rounds/1x/focus'];
        yield 'an unknown ticket' => ['/t/SHOP-77/rounds/1/focus'];
    }

    #[Test]
    #[DataProvider('addressesOfNoRound')]
    public function anUnknownOrMalformedRoundAnswers404(string $uri): void
    {
        $this->seedSharedDraft($this->addTicket('SHOP-1'), 1, self::HIDDEN_TITLE_PREFIX, [1]);
        $this->logIn($this->client, $this->people['Mia']);

        $this->client->request('GET', $uri);

        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString(self::HIDDEN_TITLE_PREFIX.'0', (string) $this->client->getResponse()->getContent());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function peopleWhoDoNotOwnTheDraft(): iterable
    {
        yield 'the lead' => ['Lena'];
        yield 'the tester' => ['Mia'];
        yield 'the admin' => ['Sara'];
    }

    #[Test]
    #[DataProvider('peopleWhoDoNotOwnTheDraft')]
    public function omarsUnsharedDraftHasNoFullScreenForAnyoneElse(string $person): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $this->addScreenshot($this->addRound($ticketId, null, 'draft', 'Omar', null), $this->addMedia('ready'), 0, self::OMARS_PRIVATE_TITLE, null);
        $this->logIn($this->client, $this->people[$person]);

        foreach ([0, 1, 2] as $number) {
            foreach (['', '?screenshot=1', '?from=ticket'] as $query) {
                $this->client->request('GET', '/t/SHOP-1/rounds/'.$number.'/focus'.$query);

                self::assertResponseStatusCodeSame(404);
                self::assertStringNotContainsString(self::OMARS_PRIVATE_TITLE, (string) $this->client->getResponse()->getContent());
            }
        }
    }

    #[Test]
    public function someoneOfAnotherOrganizationGets404ForASharedDraft(): void
    {
        $this->seedSharedDraft($this->addTicket('SHOP-1'), 1, self::HIDDEN_TITLE_PREFIX, [1]);
        $this->logIn($this->client, $this->people['Omar']);
        $this->client->request('GET', self::DRAFT_FOCUS);
        self::assertResponseStatusCodeSame(200);

        $betaBakery = $this->createOrganization('Beta Bakery');
        $ben = $this->createUserWithoutOrganization('Ben');
        $this->addMembership($ben, $betaBakery);
        $this->setOrgRoles($ben, $betaBakery, '{admin}');
        $this->logIn($this->client, $ben);

        $this->client->request('GET', self::DRAFT_FOCUS);

        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString(self::HIDDEN_TITLE_PREFIX.'0', (string) $this->client->getResponse()->getContent());
    }

    /**
     * The page's reads do not grow with the screenshots or the pins: a first shared draft with two screenshots of one
     * pin each after a report with as many, then one with six of three pins each after a report with as many, read the
     * same number of queries, the report's read for the Befores included.
     */
    #[Test]
    public function aSharedDraftsFullScreenReadsTheSameNumberOfQueriesWhateverTheNumberOfScreenshotsAndPins(): void
    {
        $firstTicketId = $this->addTicket('SHOP-1');
        $this->seedRoundOfScreenshots($firstTicketId, 0, 'report', 'Mia', 'Report shot ', [1, 1]);
        $this->seedSharedDraft($firstTicketId, 1, 'Draft shot ', [1, 1]);
        $this->logIn($this->client, $this->people['Omar']);
        $this->queryCountOf(self::DRAFT_FOCUS);
        $twoScreenshotsQueryCount = $this->queryCountOf(self::DRAFT_FOCUS);

        $secondTicketId = $this->addTicket('SHOP-2', 'all', 2);
        $this->seedRoundOfScreenshots($secondTicketId, 0, 'report', 'Mia', 'Report shot ', [3, 3, 3, 3, 3, 3]);
        $this->seedSharedDraft($secondTicketId, 1, 'Draft shot ', [3, 3, 3, 3, 3, 3]);
        $this->queryCountOf('/t/SHOP-2/rounds/1/focus?screenshot=2');
        $sixScreenshotsQueryCount = $this->queryCountOf('/t/SHOP-2/rounds/1/focus?screenshot=2');

        self::assertSame('SHOP-2 · Round 1 · Draft shot 1 · screenshot 2 of 6', $this->client->getCrawler()->filter('h1.focus-review__line')->text());
        self::assertCount(3, $this->client->getCrawler()->filter('.focus-bar__stop'));
        self::assertSame($twoScreenshotsQueryCount, $sixScreenshotsQueryCount);
        self::assertSame(self::DRAFT_FOCUS_QUERIES, $sixScreenshotsQueryCount);
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
     * Omar's draft, shared as round $number.
     *
     * @param non-empty-list<int> $pinCounts as seedRoundOfScreenshots takes them
     *
     * @return list<string> the screenshots' ids by position
     */
    private function seedSharedDraft(string $ticketId, int $number, string $titlePrefix, array $pinCounts): array
    {
        return $this->seedRoundOfScreenshots($ticketId, $number, 'draft', 'Omar', $titlePrefix, $pinCounts);
    }

    /**
     * A shared round with one screenshot per entry of $pinCounts, titled $titlePrefix and its position, stored last
     * position first so the page must order them. Each has that many of Mia's pins, "Note 1" on, and one dismissed AI
     * pin, which the page never walks.
     *
     * @param non-empty-list<int> $pinCounts by position
     *
     * @return list<string> the screenshots' ids by position
     */
    private function seedRoundOfScreenshots(string $ticketId, int $number, string $kind, string $owner, string $titlePrefix, array $pinCounts): array
    {
        $roundId = $this->addRound($ticketId, $number, $kind, $owner, 0 === $number ? self::SHARED_EARLIER : self::SHARED_TODAY);
        $screenshotIds = [];
        foreach (array_reverse($pinCounts, true) as $position => $pinCount) {
            $screenshotId = $this->addScreenshot($roundId, $this->addMedia('ready'), $position, $titlePrefix.$position, null);
            for ($pin = 1; $pin <= $pinCount; ++$pin) {
                $this->addPin($screenshotId, $pin, (string) (10 * $pin).'.00', '20.00', 'Note '.$pin, 'Mia');
            }
            $this->addPin($screenshotId, $pinCount + 1, '90.00', '90.00', 'Dismissed guess', 'Mia', 'ai', 'dismissed');
            $screenshotIds[$position] = $screenshotId;
        }
        ksort($screenshotIds);

        return array_values($screenshotIds);
    }
}
