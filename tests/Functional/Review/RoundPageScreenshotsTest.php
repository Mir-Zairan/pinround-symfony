<?php

declare(strict_types=1);

namespace App\Tests\Functional\Review;

use App\Identity\Domain\User;
use App\Review\Api\RoundQueries;
use App\Review\UI\Controller\RoundController;
use App\Review\UI\RoundPage\RoundPages;
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
 * The round page of a round without screen areas (P14.T8 step 1): a shared draft, the report (Round 0) and a checklist
 * round, one screenshot at a time with the strip, the pager and "?screenshot=N", through the real firewall, voters,
 * Media signing and database. The clock is fixed on 6 Nov 2026, before today. Omar owns the drafts; Mia is the Shop
 * team's tester and Lena its lead.
 */
final class RoundPageScreenshotsTest extends WebTestCase
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
    private const string DRAFT_PAGE = '/t/SHOP-1/rounds/1';
    private const string OMARS_PRIVATE_TITLE = 'Omar private draft picture';
    private const string LENAS_PRIVATE_TITLE = 'Lena private draft picture';
    private const string EARLIER_TRY_TITLE = 'Earlier try only Omar sees';
    /**
     * With the position, the titles a 404 must not show. The debug error page quotes the lines of this file around the
     * request, so a title checked for there is never spelled out in them.
     */
    private const string HIDDEN_TITLE_PREFIX = 'Screenshot titled ';
    /**
     * RoundPageTest::PAGE_QUERIES less the review round's areas and their pins, plus the shown round's own read through
     * RoundsVisibleTo::sharedRoundOf: its visibility check, the round, its screenshots and their pins. The open draft's
     * move-on buttons' read of the saved turn raised it from 27 (task 8e), but Omar shared this draft outside a move, so
     * his own move never closes it and his page skips that read (HandOffRound, task 16l). The header's read of whose
     * turn it is (RoundReadyFor), as Omar's own draft is no findings round, raised it from 27. The report's read as the
     * first shared draft's Befores through RoundsVisibleTo::previousHandOffRoundOf (its visibility check, the round, its
     * screenshots and their pins; ADR-0048, amended 2026-11-21) raised it from 28. The tab row has no small pictures to
     * sign, and the side panel reads the check items of the report's pins (the checklist round, then its items), which
     * raised it from 32. The one query that finds the shown picture is no copy (RoundsVisibleTo::copySourceOf,
     * RoundPages::pairedBeforeOf) raised it from 33. The one read of the round's general notes (GeneralNoteQueries, ADR-0048
     * note of 2026-11-22; the authors' names come from the ticket's page, so none here) raised it from 34; the form
     * asks no second vote, as RoundPermission ADD_GENERAL_NOTE follows the VIEW already granted.
     */
    private const int DRAFT_PAGE_QUERIES = 35;

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
    public function aSharedDraftWithThreeScreenshotsOpensAtTheFirstAndTheQueryShowsTheSecond(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $this->seedSharedDraft($ticketId, 1, 'Draft shot ', [2, 0, 1]);
        $this->logIn($this->client, $this->people['Mia']);

        $page = $this->client->request('GET', self::DRAFT_PAGE);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('round-tab-1', $page->filter('a.round-tab[aria-current="page"]')->attr('id'));
        self::assertSame('Screenshots', $page->filter('nav.round-tabs')->attr('aria-label'));
        self::assertCount(0, $page->filter('nav.screenshot-pager, .pin-list'), 'The tabs replace the pager, and the side panel lists the notes.');
        self::assertSame(
            ['Draft shot 0 2 pins', 'Draft shot 1 0 pins', 'Draft shot 2 1 pin'],
            self::tabTextsOf($page),
            'In position order although stored out of order; a dismissed AI pin is not counted.',
        );
        self::assertSame(
            ['/t/SHOP-1/rounds/1?screenshot=1', '/t/SHOP-1/rounds/1?screenshot=2', '/t/SHOP-1/rounds/1?screenshot=3'],
            $page->filter('nav.round-tabs a.round-tab')->each(static fn (Crawler $thumb): string => (string) $thumb->attr('href')),
        );
        self::assertSame('Draft shot 0', $page->filter('a.round-tab[aria-current="page"] .round-tab__label')->text(), 'A picture with a title names its tab.');
        self::assertCount(0, $page->filter('.round-page [role="tablist"], .round-page [role="tab"], .round-page [role="tabpanel"], .round-page [aria-selected]'), 'Page links, not ARIA tabs, as each one loads a page.');
        self::assertSame(['round-tab-1'], $page->filter('nav.round-tabs [aria-current]')->each(static fn (Crawler $tab): string => (string) $tab->attr('id')));
        $fragment = $page->filter('article.round-page')->attr('data-screenshot-pager-fragment-value');
        self::assertSame('shown-tab', $fragment);
        self::assertCount(0, $page->filter('#'.$fragment), 'No element has the fragment as id, so the browser does not scroll to it.');
        self::assertSame(
            ['round-tab-1'],
            $page->filter('nav.round-tabs a.round-tab[data-controller="fragment-focus"]')->each(static fn (Crawler $tab): string => (string) $tab->attr('id')),
        );
        self::assertSame($fragment, $page->filter('#round-tab-1')->attr('data-fragment-focus-fragment-value'), 'The shown tab takes the focus after an arrow key\'s visit.');
        self::assertCount(0, $page->filter('.round-tab img'), 'A tab is text only, so the pictures stay above the fold.');
        self::assertSame(['Draft shot 0'], $page->filter('.screenshot__title')->each(static fn (Crawler $title): string => $title->text()));
        self::assertCount(0, $page->filter('a.round-tab[data-screenshot-pager-target="back"]'), 'There is no screenshot before the first.');
        self::assertSame('/t/SHOP-1/rounds/1?screenshot=2', $page->filter('a.round-tab[data-screenshot-pager-target="next"]')->attr('href'));
        self::assertCount(0, $page->filter('.round-compare-control, .round-compare__side--before, #round-panel'), 'A draft has no earlier picture and takes no area verdict here.');
        self::assertSame('/t/SHOP-1/rounds/1/focus?screenshot=1', $page->filter('a.round-page__full-screen')->attr('href'));

        $page = $this->client->request('GET', self::DRAFT_PAGE.'?'.RoundController::SCREENSHOT_PARAMETER.'=2');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('round-tab-2', $page->filter('a.round-tab[aria-current="page"]')->attr('id'));
        self::assertSame(
            ['round-tab-2'],
            $page->filter('nav.round-tabs a.round-tab[data-controller="fragment-focus"]')->each(static fn (Crawler $tab): string => (string) $tab->attr('id')),
        );
        self::assertSame('Draft shot 1', $page->filter('a.round-tab[aria-current="page"] .round-tab__label')->text());
        self::assertSame(['Draft shot 1'], $page->filter('.screenshot__title')->each(static fn (Crawler $title): string => $title->text()));
        self::assertSame('/t/SHOP-1/rounds/1?screenshot=1', $page->filter('a.round-tab[data-screenshot-pager-target="back"]')->attr('href'));
        self::assertSame('/t/SHOP-1/rounds/1?screenshot=3', $page->filter('a.round-tab[data-screenshot-pager-target="next"]')->attr('href'));

        $page = $this->client->request('GET', self::DRAFT_PAGE.'?'.RoundController::SCREENSHOT_PARAMETER.'=3');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('round-tab-3', $page->filter('a.round-tab[aria-current="page"]')->attr('id'));
        self::assertSame('/t/SHOP-1/rounds/1?screenshot=2', $page->filter('a.round-tab[data-screenshot-pager-target="back"]')->attr('href'));
        self::assertCount(0, $page->filter('a.round-tab[data-screenshot-pager-target="next"]'), 'There is no screenshot after the last.');
    }

    #[Test]
    public function theReportShowsItsScreenshotsAndTheChecklistHasNoRoundPageOfItsOwn(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $reportShots = $this->seedRoundOfScreenshots($ticketId, 0, 'report', 'Mia', 'Report shot ', [1, 0]);
        $this->addCheckItem($this->addRound($ticketId, null, 'checklist', 'Omar', self::SHARED_EARLIER), $this->addPin($reportShots[0], 9, '30.00', '30.00', 'A checked note', 'Mia'), 'open');
        $this->seedSharedDraft($ticketId, 2, 'Later draft shot ', [1]);
        $this->logIn($this->client, $this->people['Lena']);

        // The checklist has no number (ADR-0048, note of 2026-11-22), so Round 1 is no page while the draft is round 2.
        $this->client->request('GET', '/t/SHOP-1/rounds/1');
        self::assertResponseStatusCodeSame(404);

        foreach (['/t/SHOP-1/rounds/0'] as $roundPage) {
            $page = $this->client->request('GET', $roundPage);

            self::assertResponseStatusCodeSame(200);
            self::assertSame('round-tab-1', $page->filter('a.round-tab[aria-current="page"]')->attr('id'));
            self::assertSame(['Report shot 0 2 pins', 'Report shot 1 0 pins'], self::tabTextsOf($page));
            self::assertSame(['Report shot 0'], $page->filter('.screenshot__title')->each(static fn (Crawler $title): string => $title->text()));
            self::assertStringNotContainsString('Later draft shot', (string) $this->client->getResponse()->getContent(), 'Another round\'s screenshots are not the report\'s.');

            $page = $this->client->request('GET', $roundPage.'?'.RoundController::SCREENSHOT_PARAMETER.'=2');

            self::assertResponseStatusCodeSame(200);
            self::assertSame('round-tab-2', $page->filter('a.round-tab[aria-current="page"]')->attr('id'));
            self::assertSame(['Report shot 1'], $page->filter('.screenshot__title')->each(static fn (Crawler $title): string => $title->text()));
        }
    }

    #[Test]
    public function theChecklistOfATicketWithoutAReportHasNoRoundNumberToOpen(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $this->addRound($ticketId, null, 'checklist', 'Omar', self::SHARED_EARLIER);
        $this->seedSharedDraft($ticketId, 2, 'Later draft shot ', [1]);
        $this->logIn($this->client, $this->people['Mia']);

        $this->client->request('GET', self::DRAFT_PAGE);

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function aReviewRoundWithoutAreasAndASharedDraftWithoutScreenshotsSayWhatTheyLack(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $this->addRound($ticketId, 1, 'review', 'Omar', self::SHARED_EARLIER);
        $this->addRound($ticketId, 2, 'draft', 'Omar', self::SHARED_TODAY);
        $this->logIn($this->client, $this->people['Mia']);

        $page = $this->client->request('GET', '/t/SHOP-1/rounds/1');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(RoundPages::NO_AREAS, $page->filter('.empty-state')->text());
        self::assertCount(0, $page->filter('nav.round-tabs, .screenshot-pager'));

        $page = $this->client->request('GET', '/t/SHOP-1/rounds/2');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(RoundPages::NO_SCREENSHOTS, $page->filter('.empty-state')->text());
        self::assertCount(0, $page->filter('nav.round-tabs, .screenshot-pager'));

        $this->client->request('GET', '/t/SHOP-1/rounds/2?'.RoundController::SCREENSHOT_PARAMETER.'=1');

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
        $ticketId = $this->addTicket('SHOP-1');
        $this->seedSharedDraft($ticketId, 1, self::HIDDEN_TITLE_PREFIX, [1, 1, 1]);
        $this->logIn($this->client, $this->people['Mia']);

        $page = $this->client->request('GET', self::DRAFT_PAGE.'?'.$query);

        self::assertResponseStatusCodeSame(404);
        self::assertCount(0, $page->filter('nav.round-tabs, .screenshot-pager, .screenshot__title'));
        foreach ([0, 1, 2] as $position) {
            self::assertStringNotContainsString(self::HIDDEN_TITLE_PREFIX.$position, (string) $this->client->getResponse()->getContent());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function peopleWhoDoNotOwnTheDraft(): iterable
    {
        yield 'the lead' => ['Lena'];
        yield 'the tester' => ['Mia'];
    }

    #[Test]
    #[DataProvider('peopleWhoDoNotOwnTheDraft')]
    public function noRoundPageShowsAnUnsharedDraftOrAnEarlierTryOfASharedOne(string $person): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $this->addScreenshot($this->addRound($ticketId, null, 'draft', 'Omar', null), $this->addMedia('ready'), 0, self::OMARS_PRIVATE_TITLE, null);
        $this->addScreenshot($this->addRound($ticketId, null, 'draft', 'Lena', null), $this->addMedia('ready'), 0, self::LENAS_PRIVATE_TITLE, null);
        $this->logIn($this->client, $this->people[$person]);

        foreach ([0, 1, 2, 3] as $number) {
            $this->client->request('GET', '/t/SHOP-1/rounds/'.$number);

            self::assertResponseStatusCodeSame(404);
            self::assertNoPrivateTitleIn((string) $this->client->getResponse()->getContent());
        }

        $shared = $this->addRound($ticketId, 1, 'draft', 'Omar', self::SHARED_TODAY);
        $earlierTry = $this->addScreenshot($shared, $this->addMedia('ready'), 0, self::EARLIER_TRY_TITLE, null);
        $latestTry = $this->addScreenshot($shared, $this->addMedia('ready'), 1, 'Latest try', null);
        $this->markRetakeOf($latestTry, $earlierTry);
        $this->addScreenshot($shared, $this->addMedia('ready'), 2, 'Second screenshot', null);

        $page = $this->client->request('GET', self::DRAFT_PAGE);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('round-tab-1', $page->filter('a.round-tab[aria-current="page"]')->attr('id'));
        self::assertSame(['Latest try'], $page->filter('.screenshot__title')->each(static fn (Crawler $title): string => $title->text()));
        self::assertNoPrivateTitleIn((string) $this->client->getResponse()->getContent());

        $this->client->request('GET', self::DRAFT_PAGE.'?'.RoundController::SCREENSHOT_PARAMETER.'=2');

        self::assertResponseStatusCodeSame(200);
        self::assertNoPrivateTitleIn((string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function compareAndTheBeforePictureShowOnlyForAnAreaWithAnEarlierPicture(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $reportShots = $this->seedRoundOfScreenshots($ticketId, 0, 'report', 'Mia', 'Report shot ', [0]);
        $reviewRound = $this->addRound($ticketId, 1, 'review', 'Omar', self::SHARED_TODAY);
        $this->addArea($reviewRound, 'Cart', 0, $reportShots[0], $this->addScreenshot($reviewRound, $this->addMedia('ready'), 0, 'Cart after', null), null, null);
        $this->addArea($reviewRound, 'Checkout', 1, null, $this->addScreenshot($reviewRound, $this->addMedia('ready', 'Mia', 1000, 2000), 1, 'Checkout after', null), null, null);
        $this->logIn($this->client, $this->people['Mia']);

        $page = $this->client->request('GET', '/t/SHOP-1/rounds/1?'.RoundController::AREA_PARAMETER.'=0');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Cart', 'Checkout'], $page->filter('a.round-tab .round-tab__label')->each(static fn (Crawler $label): string => $label->text()));
        self::assertCount(1, $page->filter('.round-compare-control'));
        self::assertSame(['Before · round 0', 'After · round 1'], $page->filter('.round-compare__caption')->each(static fn (Crawler $caption): string => $caption->text()));

        $page = $this->client->request('GET', '/t/SHOP-1/rounds/1?'.RoundController::AREA_PARAMETER.'=1');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('round-tab-2', $page->filter('a.round-tab[aria-current="page"]')->attr('id'));
        self::assertSame(RoundPages::AREAS_TABS_LABEL, $page->filter('nav.round-tabs')->attr('aria-label'));
        self::assertCount(0, $page->filter('.round-compare-control, .round-compare__side--before'));
        self::assertSame(['After · round 1'], $page->filter('.round-compare__caption')->each(static fn (Crawler $caption): string => $caption->text()));
        self::assertSame(['Checkout after'], $page->filter('.screenshot__title')->each(static fn (Crawler $title): string => $title->text()));
    }

    /**
     * Omar's first shared draft has no hand-off round before it, so its Befores are the report's (ADR-0048, amended
     * 2026-11-21): Screenshot 1 is compared with the report's Screenshot 1 and its shown pins, offering Side by side,
     * Before and After; Screenshot 2 is numbered past the report's last, so it stands alone.
     */
    #[Test]
    public function aFirstSharedDraftComparesEachScreenshotWithTheReportsSameNumberedPicture(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $this->seedRoundOfScreenshots($ticketId, 0, 'report', 'Mia', 'Report shot ', [2]);
        $this->seedSharedDraft($ticketId, 1, 'Draft shot ', [1, 0]);
        $this->logIn($this->client, $this->people['Lena']);

        $page = $this->client->request('GET', self::DRAFT_PAGE);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Before · round 0', 'After · round 1'], $page->filter('.round-compare__caption')->each(static fn (Crawler $caption): string => $caption->text()));
        self::assertSame(['Side by side', 'Before', 'After'], $page->filter('.round-compare-control button.seg')->each(static fn (Crawler $option): string => $option->text()));
        $before = $page->filter('.round-compare__side--before');
        self::assertSame(['Report shot 0'], $before->filter('.screenshot__title')->each(static fn (Crawler $title): string => $title->text()));
        self::assertCount(2, $before->filter('button.pin'), 'The report picture\'s two pins, not its dismissed AI guess.');
        self::assertSame(['Draft shot 0'], $page->filter('.round-compare__side--after .screenshot__title')->each(static fn (Crawler $title): string => $title->text()));

        $page = $this->client->request('GET', self::DRAFT_PAGE.'?'.RoundController::SCREENSHOT_PARAMETER.'=2');

        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $page->filter('.round-compare__caption')->each(static fn (Crawler $caption): string => $caption->text()), 'The report has no Screenshot 2.');
        self::assertCount(0, $page->filter('.round-compare-control, .round-compare__side--before'));
        self::assertSame(['Draft shot 1'], $page->filter('.screenshot__title')->each(static fn (Crawler $title): string => $title->text()));
    }

    /**
     * Mia pinned Round 1's Screenshot 2 in her draft and shared it as Round 2, where it is Screenshot 1. "Pin this in my
     * draft" keeps the picture, so its Before is its source, Round 1's Screenshot 2 with its pins, on the round page and
     * in full screen, not Round 1's Screenshot 1 by number. The later copies (Round 2, Omar's unshared draft) do not
     * make Round 1's own picture a copy: it still pairs with the report's Screenshot 2.
     */
    #[Test]
    public function aCopiedScreenshotOfASharedDraftHasItsSourceAsItsBeforeOnTheRoundPageAndInFullScreen(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $this->seedRoundOfScreenshots($ticketId, 0, 'report', 'Mia', 'Report shot ', [0, 0]);
        $roundOne = $this->addRound($ticketId, 1, 'draft', 'Omar', self::SHARED_EARLIER);
        $this->addScreenshot($roundOne, $this->addMedia('ready'), 0, 'Cart total', null);
        $discountMedia = $this->addMedia('ready');
        $discountLine = $this->addScreenshot($roundOne, $discountMedia, 1, 'Discount line', null);
        $this->addPin($discountLine, 1, '40.00', '40.00', 'The discount line is missing', 'Omar');
        $roundTwo = $this->addRound($ticketId, 2, 'draft', 'Mia', self::SHARED_TODAY);
        $this->addScreenshot($roundTwo, $discountMedia, 0, 'From round 1 · Screenshot 2', null);
        $this->addScreenshot($roundTwo, $this->addMedia('ready', 'Mia'), 1, 'Mia own shot', null);
        $this->addScreenshot($this->addRound($ticketId, null, 'draft', 'Omar', null), $discountMedia, 0, 'Copied again', null);
        $this->logIn($this->client, $this->people['Lena']);

        $page = $this->client->request('GET', '/t/SHOP-1/rounds/2');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Before · round 1', 'After · round 2'], $page->filter('.round-compare__caption')->each(static fn (Crawler $caption): string => $caption->text()));
        self::assertSame('screenshot-'.$discountLine, $page->filter('.round-compare__side--before figure.screenshot')->attr('id'), 'Its source, not Round 1\'s Screenshot 1.');
        $earlierPins = $page->filter('.round-panel__section')->first();
        self::assertSame('Pins from round 1', $earlierPins->filter('h2')->text());
        self::assertStringContainsString('The discount line is missing', $earlierPins->text());

        $focus = $this->client->request('GET', '/t/SHOP-1/rounds/2/focus?screenshot=1&side=before');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Before, round 1: Discount line', $focus->filter('img.screenshot__image')->attr('alt'));
        self::assertSame(['The discount line is missing'], $focus->filter('.focus-bar__note')->each(static fn (Crawler $note): string => $note->text()));

        $page = $this->client->request('GET', '/t/SHOP-1/rounds/1?'.RoundController::SCREENSHOT_PARAMETER.'=2');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Report shot 1'], $page->filter('.round-compare__side--before .screenshot__title')->each(static fn (Crawler $title): string => $title->text()), 'Round 1\'s own picture still pairs by number.');
    }

    /**
     * RoundsVisibleTo lists only the newest RoundQueries::MAX_NUMBERED_ROUNDS numbered rounds; the round page reads the
     * one round asked for, so a shared draft older than those still shows its screenshots.
     */
    #[Test]
    public function aSharedDraftOlderThanTheNewestListedRoundsStillShowsItsScreenshots(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $this->seedSharedDraft($ticketId, 1, 'Old draft shot ', [1, 2]);
        for ($number = 2; $number <= RoundQueries::MAX_NUMBERED_ROUNDS + 2; ++$number) {
            $this->addRound($ticketId, $number, 'review', 'Omar', self::SHARED_TODAY);
        }
        $this->logIn($this->client, $this->people['Mia']);

        $page = $this->client->request('GET', self::DRAFT_PAGE);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('round-tab-1', $page->filter('a.round-tab[aria-current="page"]')->attr('id'));
        self::assertSame(['Old draft shot 0 1 pin', 'Old draft shot 1 2 pins'], self::tabTextsOf($page));
        self::assertSame(['Old draft shot 0'], $page->filter('.screenshot__title')->each(static fn (Crawler $title): string => $title->text()));
    }

    /**
     * The page's reads do not grow with the screenshots or the pins: a first shared draft with two screenshots of one
     * pin each after a report with as many, then one with six of three pins each after a report with as many, read the
     * same number of queries, the report's read for the Befores included.
     */
    #[Test]
    public function aSharedDraftsPageReadsTheSameNumberOfQueriesWhateverTheNumberOfScreenshotsAndPins(): void
    {
        $firstTicketId = $this->addTicket('SHOP-1');
        $this->seedRoundOfScreenshots($firstTicketId, 0, 'report', 'Mia', 'Report shot ', [1, 1]);
        $this->seedSharedDraft($firstTicketId, 1, 'Draft shot ', [1, 1]);
        $this->logIn($this->client, $this->people['Omar']);
        $this->queryCountOf(self::DRAFT_PAGE);
        $twoScreenshotsQueryCount = $this->queryCountOf(self::DRAFT_PAGE);

        $secondTicketId = $this->addTicket('SHOP-2', 'all', 2);
        $this->seedRoundOfScreenshots($secondTicketId, 0, 'report', 'Mia', 'Report shot ', [3, 3, 3, 3, 3, 3]);
        $this->seedSharedDraft($secondTicketId, 1, 'Draft shot ', [3, 3, 3, 3, 3, 3]);
        $this->queryCountOf('/t/SHOP-2/rounds/1?screenshot=2');
        $sixScreenshotsQueryCount = $this->queryCountOf('/t/SHOP-2/rounds/1?screenshot=2');

        self::assertSame(array_fill(0, 6, '3 pins'), $this->client->getCrawler()->filter('a.round-tab .round-tab__tag')->each(static fn (Crawler $tag): string => $tag->text()));
        self::assertCount(3, $this->client->getCrawler()->filter('.round-compare__side--after button.pin'));
        self::assertSame($twoScreenshotsQueryCount, $sixScreenshotsQueryCount);
        self::assertSame(self::DRAFT_PAGE_QUERIES, $sixScreenshotsQueryCount);
    }

    private static function assertNoPrivateTitleIn(string $content): void
    {
        foreach ([self::OMARS_PRIVATE_TITLE, self::LENAS_PRIVATE_TITLE, self::EARLIER_TRY_TITLE] as $leak) {
            self::assertStringNotContainsString($leak, $content);
        }
    }

    /**
     * @return list<string> each tab's label and tag, spaces folded
     */
    private static function tabTextsOf(Crawler $page): array
    {
        return $page->filter('nav.round-tabs a.round-tab')->each(static fn (Crawler $tab): string => (string) preg_replace('/\s+/', ' ', trim($tab->text())));
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
     * position first so the page must order them. Each has that many of Mia's pins and one dismissed AI pin, which the
     * page never counts.
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
