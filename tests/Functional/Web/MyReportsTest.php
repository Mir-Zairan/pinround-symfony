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
        self::assertSame(['Show 2 more', 'Show fewer'], self::summaryTextsOf($stuck->filter('details > summary')));
        $moving = $page->filter('.my-reports__group--moving');
        self::assertSame(['APP-1', 'SHOP-2'], self::keysOf($moving));
        self::assertCount(0, $moving->filter('details'));
        self::assertSame(['Show 2 done', 'Hide 2 done'], self::summaryTextsOf($page->filter('details.my-reports__done:not([open]) > summary')));
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
        self::assertSame('1 ticket you reported. None needs you right now.', $page->filter('.my-reports__intro')->text());
    }

    /**
     * Mia's questions: SHOP-1 and her team-only APP-1. Not hers to answer: SHOP-2's question to Lena, and Omar's
     * SHOP-11, whose question is to her but which she did not report. Beta Bakery's ticket, reported by Mia with a
     * question to her, is not of Acme Foods.
     */
    #[Test]
    public function theIntroCountsEveryReportAndTheOnesThatNeedAnAnswer(): void
    {
        $this->addReport('Mia', 'SHOP-1', '2026-09-01 09:00:00', '2026-11-05 09:00:00', turn: ['Mia', 'answer']);
        $this->addReport('Mia', 'SHOP-2', '2026-09-02 09:00:00', '2026-11-05 09:00:00', turn: ['Lena', 'answer']);
        $this->addReport('Mia', 'SHOP-3', '2026-09-03 09:00:00', '2026-11-05 09:00:00', closedAt: '2026-11-05 09:00:00');
        $this->addReport('Mia', 'APP-1', '2026-09-04 09:00:00', '2026-10-01 09:00:00', isTeamOnly: true, turn: ['Mia', 'answer']);
        $this->addReport('Omar', 'SHOP-11', '2026-09-05 09:00:00', '2026-11-05 09:00:00', turn: ['Mia', 'answer']);
        $this->addBetaBakeryTicketReportedByMia(withQuestionToMia: true);
        $this->logIn($this->client, $this->people['Mia']);

        $page = $this->client->request('GET', self::MY_REPORTS);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('4 tickets you reported. 2 need your answer.', $page->filter('.my-reports__intro')->text());
    }

    #[Test]
    public function oneQuestionReadsInTheSingular(): void
    {
        $this->addReport('Mia', 'SHOP-1', '2026-09-01 09:00:00', '2026-11-05 09:00:00', turn: ['Mia', 'answer']);
        $this->logIn($this->client, $this->people['Mia']);

        $page = $this->client->request('GET', self::MY_REPORTS);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('1 ticket you reported. 1 needs your answer.', $page->filter('.my-reports__intro')->text());
    }

    /**
     * SHOP-1 waits on Mia's answer; SHOP-2 has had no change for 17 days and is Omar's to build; SHOP-4 is in a
     * checking step with a question to Lena; SHOP-3 is done, its stale turn row still naming Omar.
     */
    #[Test]
    public function eachCardShowsItsKeyTitleProgressTurnPersonAndNowLine(): void
    {
        $checkingStep = $this->addStep($this->acmeFoods, 'In test', 'check', 'test');
        $this->addReport('Mia', 'SHOP-1', '2026-09-01 09:00:00', '2026-10-01 09:00:00', turn: ['Mia', 'answer']);
        $this->addReport('Mia', 'SHOP-2', '2026-09-02 09:00:00', '2026-10-20 12:00:00', turn: ['Omar', 'build']);
        $this->addReport('Mia', 'SHOP-3', '2026-09-03 09:00:00', '2026-11-05 09:00:00', closedAt: '2026-11-05 09:00:00', turn: ['Omar', 'build']);
        $this->addReport('Mia', 'SHOP-4', '2026-09-04 09:00:00', '2026-11-05 09:00:00', turn: ['Lena', 'answer'], stepId: $checkingStep);
        $this->logIn($this->client, $this->people['Mia']);

        $page = $this->client->request('GET', self::MY_REPORTS);

        self::assertResponseStatusCodeSame(200);
        $cards = $page->filter('.my-reports__card');
        self::assertCount(4, $cards);
        self::assertSame(
            [
                ['SHOP-1', 'Ticket SHOP-1', '/t/SHOP-1', 'updated 1 Oct', false, 'Step 2 of 4: Building, waiting', 'Reported Waiting Checking Done', 'Mia', 'A question waits for your answer'],
                ['SHOP-2', 'Ticket SHOP-2', '/t/SHOP-2', 'no change for 17 days', true, 'Step 2 of 4: Building', 'Reported Building Checking Done', 'Omar', 'Omar builds it'],
                ['SHOP-4', 'Ticket SHOP-4', '/t/SHOP-4', 'changed recently', false, 'Step 3 of 4: Checking, waiting', 'Reported Building Waiting Done', 'Lena', 'Lena answers a question'],
                ['SHOP-3', 'Ticket SHOP-3', '/t/SHOP-3', 'changed recently', false, 'Step 4 of 4: Done', 'Reported Building Checking Done', null, 'Done'],
            ],
            $cards->each(static fn (Crawler $card): array => [
                $card->filter('.my-reports__key')->text(),
                $card->filter('.my-reports__title')->text(),
                $card->filter('a.my-reports__link')->attr('href'),
                $card->filter('.my-reports__side')->text(),
                1 === $card->filter('strong.my-reports__side--stuck')->count(),
                $card->filter('.progress-dots[role="img"]')->attr('aria-label'),
                $card->filter('.progress-dots')->text(),
                $card->filter('.my-reports__avatar[role="img"]')->count() > 0 ? $card->filter('.my-reports__avatar')->attr('aria-label') : null,
                $card->filter('.my-reports__now')->text(),
            ]),
        );
        self::assertSame(['progress-dots__stage progress-dots__stage--passed', 'progress-dots__stage progress-dots__stage--current progress-dots__stage--waiting', 'progress-dots__stage progress-dots__stage--later', 'progress-dots__stage progress-dots__stage--later'], $cards->eq(0)->filter('.progress-dots__stage')->each(static fn (Crawler $stage): string => (string) $stage->attr('class')));
    }

    /**
     * Only SHOP-1's question waits on Mia; SHOP-2's waits on Lena, and the stuck and done reports offer nothing yet
     * (Ping and the menu are P07.T7).
     */
    #[Test]
    public function onlyAReportThatNeedsTheViewerOffersAnswerAndNoCardHasMoreThanTwoButtons(): void
    {
        $this->addReport('Mia', 'SHOP-1', '2026-09-01 09:00:00', '2026-11-05 09:00:00', turn: ['Mia', 'answer']);
        $this->addReport('Mia', 'SHOP-2', '2026-09-02 09:00:00', '2026-11-05 09:00:00', turn: ['Lena', 'answer']);
        $this->addReport('Mia', 'SHOP-3', '2026-09-03 09:00:00', '2026-10-01 09:00:00', turn: ['Omar', 'build']);
        $this->addReport('Mia', 'SHOP-4', '2026-09-04 09:00:00', '2026-11-05 09:00:00', closedAt: '2026-11-05 09:00:00');
        $this->logIn($this->client, $this->people['Mia']);

        $page = $this->client->request('GET', self::MY_REPORTS);

        self::assertResponseStatusCodeSame(200);
        $answers = $page->filter('a.my-reports__answer');
        self::assertSame(['Answer on SHOP-1 → /t/SHOP-1'], $answers->each(static fn (Crawler $answer): string => $answer->text().' → '.$answer->attr('href')));
        self::assertSame(' on SHOP-1', $answers->filter('.visually-hidden')->text(normalizeWhitespace: false));
        self::assertCount(1, $page->filter('.my-reports__group--needs_you a.my-reports__answer'));
        $buttonCounts = $page->filter('.my-reports__card')->each(static fn (Crawler $card): int => $card->filter('.btn, button')->count());
        self::assertCount(4, $buttonCounts);
        self::assertSame([1, 0, 0, 0], $buttonCounts);
        self::assertLessThanOrEqual(2, max($buttonCounts));
    }

    #[Test]
    public function aPersonWhoReportedNothingSeesHowTheListFills(): void
    {
        $this->addReport('Omar', 'SHOP-1', '2026-09-01 09:00:00', '2026-11-05 09:00:00');
        $this->logIn($this->client, $this->people['Mia']);

        $page = $this->client->request('GET', self::MY_REPORTS);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('When you report a problem, you can follow it here until it is fixed.', $page->filter('main .empty-state > p')->text());
        $reportAProblem = $page->filter('main .empty-state a.btn');
        self::assertSame(['Report a problem → /report'], $reportAProblem->each(static fn (Crawler $link): string => $link->text().' → '.$link->attr('href')));
        self::assertCount(0, $page->filter('.my-reports__group, .my-reports__pages, .my-reports__key, .my-reports__intro'));
    }

    /**
     * Mia's questions: SHOP-1 and her team-only APP-1. Not counted: SHOP-2's question to Lena, Omar's SHOP-11 with a
     * question to Mia, and Beta Bakery's ticket with a question to her. Lena has a question in a report of hers, but it
     * waits on Omar; Sara is in no team, so she has no My reports at all.
     */
    #[Test]
    public function theMenuBadgeCountsTheQuestionsThatWaitOnThePersonInTheirOwnReports(): void
    {
        $this->addReport('Mia', 'SHOP-1', '2026-09-01 09:00:00', '2026-11-05 09:00:00', turn: ['Mia', 'answer']);
        $this->addReport('Mia', 'APP-1', '2026-09-02 09:00:00', '2026-11-05 09:00:00', isTeamOnly: true, turn: ['Mia', 'answer']);
        $this->addReport('Mia', 'SHOP-2', '2026-09-03 09:00:00', '2026-11-05 09:00:00', turn: ['Lena', 'answer']);
        $this->addReport('Omar', 'SHOP-11', '2026-09-04 09:00:00', '2026-11-05 09:00:00', turn: ['Mia', 'answer']);
        $this->addReport('Lena', 'SHOP-12', '2026-09-05 09:00:00', '2026-11-05 09:00:00', turn: ['Omar', 'answer']);
        $this->addBetaBakeryTicketReportedByMia(withQuestionToMia: true);

        $this->logIn($this->client, $this->people['Mia']);
        $miasHomeQueries = $this->queriesOf('/');
        $miasMenu = $this->client->getCrawler()->filter('header nav[aria-label="Main"]');
        self::assertSame([', 2 need your answer'], $miasMenu->filter('a.nav[href="/my-reports"] .nav__badge')->each(static fn (Crawler $badge): string => $badge->text()));
        self::assertCount(1, array_filter($miasHomeQueries, static fn (string $sql): bool => str_contains($sql, 'questions_waiting')), 'the badge is one query');

        $this->logIn($this->client, $this->people['Lena']);
        $this->queriesOf('/');
        $lenasMenu = $this->client->getCrawler()->filter('header nav[aria-label="Main"]');
        self::assertCount(1, $lenasMenu->filter('a.nav[href="/my-reports"]'));
        self::assertCount(0, $lenasMenu->filter('.nav__badge'));

        $this->logIn($this->client, $this->people['Sara']);
        $this->queriesOf('/');
        self::assertCount(0, $this->client->getCrawler()->filter('header .nav__badge, header a.nav[href="/my-reports"]'));
    }

    #[Test]
    public function oneQuestionMakesTheBadgeSingular(): void
    {
        $this->addReport('Mia', 'SHOP-1', '2026-09-01 09:00:00', '2026-11-05 09:00:00', turn: ['Mia', 'answer']);
        $this->logIn($this->client, $this->people['Mia']);

        $page = $this->client->request('GET', self::MY_REPORTS);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(', 1 needs your answer', $page->filter('header a.nav[href="/my-reports"] .nav__badge')->text());
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
        self::assertSame(['Show 46 more', 'Show fewer'], self::summaryTextsOf($firstPage->filter('.my-reports__group--moving details > summary')));
        self::assertSame('60 tickets you reported. None needs you right now.', $firstPage->filter('.my-reports__intro')->text());
        self::assertCount(0, $firstPage->filter('.my-reports__first-page'));
        $showMore = $firstPage->filter('.my-reports__pages a.my-reports__next-page');
        self::assertSame('Show more', $showMore->text());

        $secondPage = $this->client->request('GET', (string) $showMore->attr('href'));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(\array_slice($expectedKeys, 50), self::keysOf($secondPage));
        self::assertSame('60 tickets you reported. None needs you right now.', $secondPage->filter('.my-reports__intro')->text(), 'the intro counts every page');
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
        self::assertCount(0, $page->filter('.my-reports__key, .my-reports__pages, .my-reports__intro'));
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
            $this->addReport(
                'Mia',
                'SHOP-'.$number,
                \sprintf('2026-09-%02d 09:00:00', 1 + $number % 28),
                0 === $number % 3 ? '2026-10-01 09:00:00' : '2026-11-05 09:00:00',
                closedAt: 0 === $number % 5 ? '2026-11-05 09:00:00' : null,
                turn: 0 === $number % 5 ? null : [['Lena', 'Omar', 'Mia', 'Raj'][$number % 4], 0 === $number % 7 ? 'answer' : 'build'],
            );
        }

        $sixtyReportsQueries = $this->queriesOf(self::MY_REPORTS);

        self::assertCount(50, $this->client->getCrawler()->filter('.my-reports__key'));
        self::assertGreaterThan(1, $this->client->getCrawler()->filter('.my-reports__card .my-reports__avatar')->count(), 'the cards name their turn people');
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
     * @return array{string, string} the text a closed summary shows, then the one it shows open
     */
    private static function summaryTextsOf(Crawler $summary): array
    {
        self::assertCount(1, $summary);

        return [$summary->filter('.my-reports__when-closed')->text(), $summary->filter('.my-reports__when-open')->text()];
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
     * it has a close time, unless $stepId names its step. Times are UTC.
     *
     * @param array{string, string}|null $turn the person whose turn it is and the turn's kind; null for no turn row
     *
     * @return string the ticket's id
     */
    private function addReport(string $reporter, string $key, string $reportedAt, string $updatedAt, ?string $closedAt = null, bool $isTeamOnly = false, ?array $turn = null, ?string $stepId = null): string
    {
        [$projectId, $teamId] = $isTeamOnly ? [$this->mobileProject, $this->mobile] : [$this->shopProject, $this->shop];
        $stepId ??= null === $closedAt ? $this->doingStep : $this->doneStep;
        $ticketId = $this->insertTicket($this->acmeFoods, $projectId, $teamId, $key, $stepId, $this->people[$reporter], $reportedAt, $updatedAt, $closedAt, $isTeamOnly ? 'team' : 'all');
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

    /**
     * @param bool $withQuestionToMia whether the ticket has a turn row of a question to Mia
     */
    private function addBetaBakeryTicketReportedByMia(bool $withQuestionToMia = false): void
    {
        $betaBakery = $this->createOrganization('Beta Bakery');
        $projectId = $this->addProject(null, 'BAKE', 'Bakery', $betaBakery);
        $stepId = $this->addStep($betaBakery, 'In progress', 'doing', 'progress');
        $miaId = $this->people['Mia']->id()->toString();
        $ticketId = $this->insertTicket($betaBakery, $projectId, null, 'BAKE-1', $stepId, $this->people['Mia'], '2026-11-01 09:00:00', '2026-11-06 09:00:00', null, 'all');
        if (!$withQuestionToMia) {
            return;
        }
        $this->committedAs($betaBakery, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO ins_ticket_turn (organization_id, ticket_id, team_id, project_id, grp, turn_user_id, turn_label, kind, base_score, waiting_since, visibility, reporter_id)
             VALUES (?, ?, NULL, ?, 'doing', ?, NULL, 'answer', 100, '2026-11-06 09:00:00+00', 'all', ?)",
            [$betaBakery->toString(), $ticketId, $projectId, $miaId, $miaId],
        ));
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
