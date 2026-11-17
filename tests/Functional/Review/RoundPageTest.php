<?php

declare(strict_types=1);

namespace App\Tests\Functional\Review;

use App\Identity\Domain\User;
use App\Review\UI\Controller\RoundController;
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
 * The review round page of Acme Foods' ticket SHOP-1, GET /t/SHOP-1/rounds/{number}, through the real firewall, voters,
 * Media signing and database. The clock is fixed on 6 Nov 2026, before today, and the test users' zone is Europe/Berlin,
 * an hour ahead of UTC then. Mia is the Shop team's tester, Lena its lead and Omar a member; Raj is a manager in no team.
 */
final class RoundPageTest extends WebTestCase
{
    use CreatesAcmeFoodsTeams;
    use CreatesTeams;
    use Factories;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }
    use SeedsTicketRounds;

    private const string NOW = '2026-11-06 12:00:00 UTC';
    private const string SHARED_TODAY = '2026-11-06 09:12:00+00';
    private const string SHARED_EARLIER = '2026-11-05 09:00:00+00';
    private const string ROUND_PAGE = '/t/SHOP-1/rounds/2';
    /**
     * The session's user and password change, the organizations, the tenant, the viewer's roles and teams, the settings, the
     * ticket, the names, the visibility checks, the round, its areas, the pins, the round and verdict voters' ticket facts and the signing.
     */
    private const int PAGE_QUERIES = 22;
    private const string PRIVATE_TITLE = 'Lena private draft picture';

    private KernelBrowser $client;

    /** @var array<string, User> */
    private array $people;

    private string $shopProject;
    private string $stepId;

    /** @var array<string, string> the id of round 1's and round 2's area pictures by "r1-0", "r2-3", and so on */
    private array $screenshots = [];

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

    /**
     * @return iterable<string, array{string}>
     */
    public static function peopleOnTheTicket(): iterable
    {
        yield 'the tester' => ['Mia'];
        yield 'the lead' => ['Lena'];
        yield 'a member' => ['Omar'];
    }

    #[Test]
    #[DataProvider('peopleOnTheTicket')]
    public function theHeaderTabsAndChipsShowWhatEachAreaStandsAt(string $person): void
    {
        $this->seedTwoRoundsOfFiveAreas();
        $this->logIn($this->client, $this->people[$person]);

        $page = $this->client->request('GET', self::ROUND_PAGE);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Round 2 · shot today, 10:12 · Ready for Mia', $page->filter('h1.round-page__title')->text());
        self::assertSame('· shot today, 10:12 · Ready for Mia', $page->filter('h1.round-page__title .round-page__detail')->text(), 'The rest of the header is muted.');
        self::assertSame(
            ['Per order Passed', 'Per product 3 pins', 'Combined Needs fix', 'Totals Question', 'Footer Not reviewed'],
            $page->filter('nav.area-tabs a.area-tab')->each(static fn (Crawler $tab): string => (string) preg_replace('/\s+/', ' ', trim($tab->text()))),
        );
        self::assertSame('Per order', $page->filter('nav.area-tabs a[aria-current="page"] .area-tab__name')->text());
        self::assertSame('Cart total shows the wrong sum', $page->filter('.round-page__ticket a')->text());
        $compare = $page->filter('.round-compare-control[role="group"][aria-label="Compare"][hidden]');
        self::assertSame(
            [['Side by side', 'both', 'true'], ['Before', 'before', 'false'], ['After', 'after', 'false']],
            $compare->filter('button.seg')->each(static fn (Crawler $option): array => [$option->text(), $option->attr('value'), $option->attr('aria-pressed')]),
        );
        self::assertSame('click->compare#choose', $compare->attr('data-action'));
        self::assertSame('/t/SHOP-1/rounds/2/focus?area=0', $page->filter('a.round-page__full-screen')->attr('href'));
        self::assertSame('Full screen', $page->filter('a.round-page__full-screen')->text());
    }

    #[Test]
    public function aTabShowsItsAreasBeforeAndAfterPicturesWithTheirTitlesAndSummariesAndPins(): void
    {
        $this->seedTwoRoundsOfFiveAreas();
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', self::ROUND_PAGE.'?'.RoundController::AREA_PARAMETER.'=1');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Per product', $page->filter('nav.area-tabs a[aria-current="page"] .area-tab__name')->text());
        self::assertSame(['Before · round 1', 'After · round 2'], $page->filter('.round-compare__caption')->each(static fn (Crawler $caption): string => $caption->text()));
        self::assertSame(['Before title 1', 'After title 1'], $page->filter('.screenshot__title')->each(static fn (Crawler $title): string => $title->text()));
        self::assertSame(['Before summary 1', 'After summary 1'], $page->filter('.screenshot__summary')->each(static fn (Crawler $summary): string => $summary->text()));
        self::assertCount(2, $page->filter('img.screenshot__image'));
        $before = $page->filter('.round-compare__side--before');
        $after = $page->filter('.round-compare__side--after');
        self::assertSame(['Pin 1: Planted wrong', 'Pin 2: Header cut', 'Pin 3: Kept AI idea'], $before->filter('button.pin')->each(static fn (Crawler $pin): string => (string) $pin->attr('aria-label')));
        self::assertSame(['Pin 1: Still cut'], $after->filter('button.pin')->each(static fn (Crawler $pin): string => (string) $pin->attr('aria-label')));
        self::assertSame(
            ['Pins from round 1', 'Verdict on Per product'],
            $page->filter('.round-panel__title')->each(static fn (Crawler $title): string => $title->text()),
        );
        self::assertSame(
            ['1 Planted wrong Mia · tester Fixed', '2 Header cut Mia · tester Still wrong', '3 Kept AI idea Mia · tester Not checked yet'],
            $page->filter('.round-panel__pin')->each(static fn (Crawler $row): string => (string) preg_replace('/\s+/', ' ', trim($row->text()))),
        );
        self::assertSame('No verdict yet.', $page->filter('.round-panel__section')->last()->filter('.round-panel__empty')->text());
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('Suggested guess', $content, 'An AI pin nobody kept is not drawn.');
        self::assertStringNotContainsString('Dismissed guess', $content);
        self::assertCount(0, $page->filter('.round-panel form, .round-panel button'), 'Omar may not give verdicts, so the panel is read-only.');
    }

    #[Test]
    public function aDecidedAreaShowsItsVerdictAndNote(): void
    {
        $this->seedTwoRoundsOfFiveAreas();
        $this->logIn($this->client, $this->people['Mia']);

        $page = $this->client->request('GET', self::ROUND_PAGE.'?area=2');

        self::assertResponseStatusCodeSame(200);
        $verdict = $page->filter('.round-panel__section')->last();
        self::assertSame('Your verdict on Combined', $verdict->filter('.round-panel__title')->text());
        self::assertSame('Needs fix', $verdict->filter('.tag')->text());
        self::assertSame('Totals differ by a cent', $verdict->filter('.round-panel__note')->text());
        self::assertSame('Saved by Mia', $verdict->filter('.round-panel__author')->text());
        self::assertSame('That round left no pins here.', $page->filter('.round-panel__section')->first()->filter('.round-panel__empty')->text());
    }

    #[Test]
    public function anUnknownAreaPositionFallsBackToTheFirstTab(): void
    {
        $this->seedTwoRoundsOfFiveAreas();
        $this->logIn($this->client, $this->people['Mia']);

        foreach (['area=99', 'area=abc', 'area[]=1'] as $query) {
            $page = $this->client->request('GET', self::ROUND_PAGE.'?'.$query);

            self::assertResponseStatusCodeSame(200);
            self::assertSame('Per order', $page->filter('nav.area-tabs a[aria-current="page"] .area-tab__name')->text());
        }
    }

    #[Test]
    public function aRoundWhoseTicketHasNoTesterSaysNothingOfWhoIsNext(): void
    {
        $this->seedTwoRoundsOfFiveAreas();
        $organizationId = $this->acmeFoods->toString();
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "UPDATE org_team_member SET job = 'other' WHERE organization_id = ? AND job = 'tester'",
            [$organizationId],
        ));
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', self::ROUND_PAGE);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Round 2 · shot today, 10:12', $page->filter('h1.round-page__title')->text());
    }

    #[Test]
    public function aRoundShotEarlierNamesTheDay(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $this->addRound($ticketId, 1, 'review', 'Omar', self::SHARED_EARLIER);
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', '/t/SHOP-1/rounds/1');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Round 1 · shot yesterday, 10:00 · Ready for Mia', $page->filter('h1.round-page__title')->text());
        self::assertSame('This round has no screen areas.', $page->filter('.empty-state')->text());
        self::assertCount(0, $page->filter('nav.area-tabs, .round-compare-control'));

        $this->addRound($ticketId, 2, 'review', 'Omar', '2026-10-30 18:05:00+00');
        $page = $this->client->request('GET', '/t/SHOP-1/rounds/2');
        self::assertSame('Round 2 · shot 30 Oct, 19:05 · Ready for Mia', $page->filter('h1.round-page__title')->text());

        $this->addRound($ticketId, 3, 'review', 'Omar', '2025-12-31 23:30:00+00');
        $page = $this->client->request('GET', '/t/SHOP-1/rounds/3');
        self::assertSame('Round 3 · shot 1 Jan, 00:30 · Ready for Mia', $page->filter('h1.round-page__title')->text(), 'Berlin is in 2026 already.');

        $this->addRound($ticketId, 4, 'review', 'Omar', '2025-10-30 18:05:00+00');
        $page = $this->client->request('GET', '/t/SHOP-1/rounds/4');
        self::assertSame('Round 4 · shot 30 Oct 2025, 19:05 · Ready for Mia', $page->filter('h1.round-page__title')->text(), 'Another year is named.');
    }

    /**
     * At 12:00 UTC on 6 Nov it is already 01:00 on 7 Nov in Auckland, so a round shot that morning in UTC was shot
     * yesterday there, and one shot the day before is two days back.
     */
    #[Test]
    public function theDayAndTimeAreTheViewersOwnWhereTheDayHasFlipped(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $this->addRound($ticketId, 1, 'review', 'Omar', self::SHARED_EARLIER);
        $this->addRound($ticketId, 2, 'review', 'Omar', self::SHARED_TODAY);
        $this->migrator()->executeStatement("UPDATE idn_user SET time_zone = 'Pacific/Auckland' WHERE id = ?", [$this->people['Omar']->id()->toString()]);
        self::getContainer()->get('doctrine.orm.default_entity_manager')->clear();
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', '/t/SHOP-1/rounds/2');
        self::assertSame('Round 2 · shot yesterday, 22:12 · Ready for Mia', $page->filter('h1.round-page__title')->text());
        $page = $this->client->request('GET', '/t/SHOP-1/rounds/1');
        self::assertSame('Round 1 · shot 5 Nov, 22:00 · Ready for Mia', $page->filter('h1.round-page__title')->text());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function numbersNoRoundHas(): iterable
    {
        yield 'an unknown number' => ['/t/SHOP-1/rounds/9'];
        yield 'a malformed number' => ['/t/SHOP-1/rounds/abc'];
        yield 'a negative number' => ['/t/SHOP-1/rounds/-1'];
        yield 'a number too large for the database' => ['/t/SHOP-1/rounds/99999999999999999999'];
        yield 'an unknown ticket' => ['/t/SHOP-77/rounds/2'];
        yield 'a malformed key' => ['/t/shop%20one/rounds/2'];
    }

    #[Test]
    #[DataProvider('numbersNoRoundHas')]
    public function anUnknownOrMalformedKeyOrNumberAnswers404AndLeaksNothingOfTheRound(string $uri): void
    {
        $this->seedTwoRoundsOfFiveAreas();
        $this->logIn($this->client, $this->people['Mia']);

        $this->client->request('GET', $uri);

        self::assertResponseStatusCodeSame(404);
        self::assertNothingOfTheRoundIn((string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function someoneElsesUnsharedDraftHasNoPageForAnyNumber(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $draft = $this->addRound($ticketId, null, 'draft', 'Lena', null);
        $this->addScreenshot($draft, $this->addMedia('ready'), 0, self::PRIVATE_TITLE, null);
        $this->logIn($this->client, $this->people['Omar']);

        foreach ([0, 1, 2, 3] as $number) {
            $this->client->request('GET', '/t/SHOP-1/rounds/'.$number);

            self::assertResponseStatusCodeSame(404);
            self::assertStringNotContainsString(self::PRIVATE_TITLE, (string) $this->client->getResponse()->getContent());
        }
    }

    #[Test]
    public function aViewerWithoutTicketViewGets404ForATeamOnlyTicketsRound(): void
    {
        $this->seedTwoRoundsOfFiveAreas('team');

        $this->logIn($this->client, $this->people['Lena']);
        $this->client->request('GET', self::ROUND_PAGE);
        self::assertResponseStatusCodeSame(200);

        $this->logIn($this->client, $this->people['Raj']);
        $this->client->request('GET', self::ROUND_PAGE);

        self::assertResponseStatusCodeSame(404);
        self::assertNothingOfTheRoundIn((string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function someoneOfAnotherOrganizationGets404(): void
    {
        $this->seedTwoRoundsOfFiveAreas();
        $betaBakery = $this->createOrganization('Beta Bakery');
        $ben = $this->createUserWithoutOrganization('Ben');
        $this->addMembership($ben, $betaBakery);
        $this->setOrgRoles($ben, $betaBakery, '{admin}');
        $this->logIn($this->client, $ben);

        $this->client->request('GET', self::ROUND_PAGE);

        self::assertResponseStatusCodeSame(404);
        self::assertNothingOfTheRoundIn((string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function anAnonymousVisitorIsSentToLogIn(): void
    {
        $this->seedTwoRoundsOfFiveAreas();

        $this->client->request('GET', self::ROUND_PAGE);

        self::assertResponseRedirects();
        self::assertStringNotContainsString('Before title', (string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function theOpenRoundLinkAppearsOnlyForRoundsTheViewerMayOpen(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $reportShot = $this->addScreenshot($this->addRound($ticketId, 0, 'report', 'Mia', self::SHARED_EARLIER), $this->addMedia('ready'), 0, 'Report shot', null);
        $this->addCheckItem($this->addRound($ticketId, 1, 'checklist', 'Omar', self::SHARED_EARLIER), $this->addPin($reportShot, 1, '10.00', '10.00', 'A note', 'Mia'), 'open');
        $this->addRound($ticketId, 2, 'review', 'Lena', self::SHARED_EARLIER);
        $this->addRound($ticketId, null, 'draft', 'Lena', null);
        $this->logIn($this->client, $this->people['Mia']);

        $page = $this->client->request('GET', '/t/SHOP-1/rounds', [], [], ['HTTP_TURBO_FRAME' => 'ticket-rounds']);

        self::assertResponseStatusCodeSame(200);
        $links = $page->filter('a.round-card__open');
        self::assertSame(['Open round'], $links->each(static fn (Crawler $link): string => $link->text()), 'The checklist card shows its rows and has no page link.');
        self::assertSame(['/t/SHOP-1/rounds/2'], $links->each(static fn (Crawler $link): string => (string) $link->attr('href')));
        self::assertCount(0, $page->filter('button.round-card__open'));

        foreach ($links->each(static fn (Crawler $link): string => (string) $link->attr('href')) as $href) {
            $this->client->request('GET', $href);
            self::assertResponseStatusCodeSame(200);
        }
    }

    #[Test]
    public function noOpenRoundLinkIsShownToSomeoneWhoMayNotSeeTheTicket(): void
    {
        $ticketId = $this->addTicket('SHOP-1', 'team');
        $this->addRound($ticketId, 1, 'review', 'Lena', self::SHARED_EARLIER);
        $this->logIn($this->client, $this->people['Raj']);

        $this->client->request('GET', '/t/SHOP-1/rounds', [], [], ['HTTP_TURBO_FRAME' => 'ticket-rounds']);

        self::assertResponseStatusCodeSame(404);
        self::assertCount(0, $this->client->getCrawler()->filter('a.round-card__open'));
    }

    /**
     * The page's reads do not grow with the areas or the pins: round 2 with one area and one pin, then with five areas
     * and many pins, read the same number of queries, and signing is one call.
     */
    #[Test]
    public function thePageReadsTheSameNumberOfQueriesWhateverTheNumberOfAreasAndPins(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $this->seedRound($ticketId, 1, 1);
        $this->seedRound($ticketId, 2, 1);
        $this->logIn($this->client, $this->people['Omar']);
        $this->queryCountOf('/t/SHOP-1/rounds/2');
        $oneAreaQueryCount = $this->queryCountOf('/t/SHOP-1/rounds/2');

        $manyAreas = $this->addTicket('SHOP-2', 'all', 2);
        $this->seedRound($manyAreas, 1, 5);
        $this->seedRound($manyAreas, 2, 5);
        $this->queryCountOf('/t/SHOP-2/rounds/2?area=1');
        $manyAreasQueryCount = $this->queryCountOf('/t/SHOP-2/rounds/2?area=1');

        self::assertCount(5, $this->client->getCrawler()->filter('a.area-tab'));
        self::assertCount(3, $this->client->getCrawler()->filter('.round-compare__side--before button.pin'));
        self::assertSame($oneAreaQueryCount, $manyAreasQueryCount);
        self::assertSame(self::PAGE_QUERIES, $manyAreasQueryCount);
    }

    private static function assertNothingOfTheRoundIn(string $content): void
    {
        foreach (['Before title', 'After title', 'Per product', 'Planted wrong', 'Still cut', 'Totals differ by a cent', 'shot today'] as $leak) {
            self::assertStringNotContainsString($leak, $content);
        }
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
     * Rounds 1 and 2 with five areas each. Round 1's pictures are round 2's "before"; round 2's areas read: 0 passed;
     * 1 with three pins to check (one fixed, one still wrong, one a kept AI pin nobody checked) and an AI suggestion and
     * a dismissed one that never show; 2 needs fix; 3 question; 4 not reviewed.
     */
    private function seedTwoRoundsOfFiveAreas(string $visibility = 'all'): void
    {
        $ticketId = $this->addTicket('SHOP-1', $visibility);
        $this->seedRound($ticketId, 1, 5);
        $this->seedRound($ticketId, 2, 5);
    }

    private function seedRound(string $ticketId, int $number, int $areaCount): void
    {
        $roundId = $this->addRound($ticketId, $number, 'review', 'Omar', 1 === $number ? self::SHARED_EARLIER : self::SHARED_TODAY);
        $names = ['Per order', 'Per product', 'Combined', 'Totals', 'Footer'];
        $verdicts = [['pass', null], [null, null], ['needs_fix', 'Totals differ by a cent'], ['question', null], [null, null]];
        $title = 1 === $number ? 'Before title ' : 'After title ';
        $summary = 1 === $number ? 'Before summary ' : 'After summary ';
        for ($position = 0; $position < $areaCount; ++$position) {
            $screenshotId = $this->addScreenshot($roundId, $this->addMedia('ready'), $position, $title.$position, $summary.$position);
            $this->screenshots[$ticketId.'-r'.$number.'-'.$position] = $screenshotId;
            if (1 === $number && 1 === $position) {
                $this->addPin($screenshotId, 1, '10.00', '10.00', 'Planted wrong', 'Mia');
                $this->addPin($screenshotId, 2, '20.00', '30.00', 'Header cut', 'Mia');
                $this->addPin($screenshotId, 3, '70.00', '30.00', 'Kept AI idea', 'Mia', 'ai', 'kept');
                $this->addPin($screenshotId, 4, '50.00', '50.00', 'Suggested guess', 'Mia', 'ai', 'suggested');
                $this->addPin($screenshotId, 5, '50.00', '60.00', 'Dismissed guess', 'Mia', 'ai', 'dismissed');
                $this->setPinVerdict($screenshotId, 1, 'fixed');
                $this->setPinVerdict($screenshotId, 2, 'still_wrong');
            }
            if (2 === $number) {
                $this->addPin($screenshotId, 1, '40.00', '40.00', 1 === $position ? 'Still cut' : 'A note', 'Mia');
                [$verdict, $note] = $verdicts[$position];
                $this->addArea($roundId, $names[$position], $position, $this->screenshots[$ticketId.'-r1-'.$position], $screenshotId, $verdict, $note);
            }
        }
    }
}
