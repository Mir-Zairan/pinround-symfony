<?php

declare(strict_types=1);

namespace App\Tests\Functional\Review;

use App\Identity\Domain\User;
use App\Review\UI\Component\RoundsSection;
use App\Review\UI\RoundCards;
use App\Review\UI\RoundsController;
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
 * The Rounds section's lazy frame of Acme Foods' ticket SHOP-1, GET /t/SHOP-1/rounds, through the real firewall,
 * voters, Media signing and database. The clock is fixed on 6 Nov 2026, before today: a clock ahead of the login would end the session as idle.
 * Mia reported the ticket and Lena leads the Shop team, Omar is its member; Raj is a manager in no team.
 */
final class RoundsFrameTest extends WebTestCase
{
    use CreatesAcmeFoodsTeams;
    use CreatesTeams;
    use Factories;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }

    private const string NOW = '2026-11-06 12:00:00 UTC';
    private const string SHARED_AT = '2026-11-05 09:00:00+00';
    /**
     * A warm frame for a ticket with no round, the request's transaction included: the session's user and password
     * change, the organizations, the tenant, the viewer's roles and teams twice, the settings, the ticket, the names, the
     * visibility check and the rounds.
     */
    private const int EMPTY_FRAME_QUERIES = 15;
    private const string ROUNDS_PAGE = '/t/SHOP-1/rounds';
    private const string ROUNDS = 'turbo-frame#ticket-rounds';
    /** What Turbo sends when it loads the Rounds section's frame. */
    private const array TURBO_FRAME_HEADERS = ['HTTP_TURBO_FRAME' => RoundsSection::FRAME_ID];
    private const string REPORT_TITLE = 'Checkout <b>total</b>';
    private const string REPORT_SUMMARY = 'The total shows €0 after a coupon.';
    private const string LENA_DRAFT_TITLE = 'Lena private self-check';
    private const string TEAM_ONLY_TITLE = 'Team-only price rules';

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
        $this->people = $this->createAcmeFoodsPeople();
        $this->shopProject = $this->addProject($this->shop, 'SHOP', 'Shop');
        $this->stepId = Uuid::v7()->toRfc4122();
        $organizationId = $this->acmeFoods->toString();
        $stepId = $this->stepId;
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, 'To do', 'todo', 'todo', 'core', 1)",
            [$organizationId, $stepId],
        ));
    }

    protected function tearDown(): void
    {
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->committedAs(new OrganizationId($organizationId), static function (Connection $migrator) use ($organizationId): int {
                foreach (['rvw_round', 'med_object', 'tkt_ticket_event', 'ins_ticket_turn', 'ins_project_count_ticket', 'ins_project_counts', 'tkt_ticket', 'wf_step'] as $table) {
                    $migrator->executeStatement(\sprintf('DELETE FROM %s WHERE organization_id = ?', $table), [$organizationId]);
                }

                return 0;
            });
        }
        $this->removeCommittedProjects();
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function aTicketWithNoRoundsShowsTheEmptyState(): void
    {
        $this->addTicket('SHOP-1');
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', self::ROUNDS_PAGE, [], [], self::TURBO_FRAME_HEADERS);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(RoundsSection::EMPTY, $page->filter(self::ROUNDS.' .empty-state')->text());
        self::assertCount(0, $page->filter(self::ROUNDS.' .round-card'));
    }

    #[Test]
    public function roundZeroShowsItsReporterItsPinCountAndEachScreenshotsTextAboveItsPictureWithPinsAndNoWayToAddOne(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $roundId = $this->addRound($ticketId, 0, 'report', 'Mia', self::SHARED_AT);
        $readyMedia = $this->addMedia('ready');
        $screenshotId = $this->addScreenshot($roundId, $readyMedia, 0, self::REPORT_TITLE, self::REPORT_SUMMARY);
        $this->addPin($screenshotId, 1, '12.35', '7.10', 'The total is off', 'Mia');
        $this->addPin($screenshotId, 2, '80.00', '50.00', 'Pay button is hidden', 'Lena');
        $this->addPin($screenshotId, 3, '50.00', '50.00', 'An AI guess nobody wanted', 'Mia', 'ai', 'dismissed');
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', self::ROUNDS_PAGE, [], [], self::TURBO_FRAME_HEADERS);

        self::assertResponseStatusCodeSame(200);
        $card = $page->filter(self::ROUNDS.' .round-card');
        self::assertCount(1, $card);
        self::assertSame('Round 0 · Report · by Mia · 2 pins', $card->filter('.round-card__headline')->text());
        self::assertSame(self::REPORT_TITLE, $card->filter('.screenshot__title')->text());
        self::assertSame(self::REPORT_SUMMARY, $card->filter('.screenshot__summary')->text());
        self::assertStringNotContainsString('<b>total</b>', (string) $this->client->getResponse()->getContent(), 'A title is text, never markup.');
        $order = $card->filter('figure.screenshot')->children()->each(static fn (Crawler $child): string => (string) $child->attr('class'));
        self::assertSame(['screenshot__text', 'screenshot__frame'], $order, 'The text comes above the picture.');
        self::assertStringContainsString('/media/'.$readyMedia.'/view.webp', (string) $card->filter('img.screenshot__image')->attr('src'));
        self::assertSame(
            ['Pin 1: The total is off', 'Pin 2: Pay button is hidden'],
            $card->filter('button.pin')->each(static fn (Crawler $pin): string => (string) $pin->attr('aria-label')),
        );
        self::assertSame(['The total is off', 'Pay button is hidden'], $card->filter('.pin-list__item .pin-list__note')->each(static fn (Crawler $note): string => $note->text()));
        self::assertCount(0, $card->filter('button[data-pin-add], textarea, [data-pin-remove], form'));
        self::assertCount(0, $card->filter('.round-card__open'));
        self::assertStringNotContainsString('An AI guess nobody wanted', (string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function aPictureStillProcessingARefusedOneAndOneMediaDoesNotKnowEachShowTheirOwnPlaceholder(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $roundId = $this->addRound($ticketId, 0, 'report', 'Mia', self::SHARED_AT);
        $this->addScreenshot($roundId, $this->addMedia('pending'), 0, 'Pending one', null);
        $this->addScreenshot($roundId, $this->addMedia('rejected'), 1, 'Refused one', null);
        $this->addScreenshot($roundId, Uuid::v7()->toRfc4122(), 2, 'Unknown one', null);
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', self::ROUNDS_PAGE, [], [], self::TURBO_FRAME_HEADERS);

        self::assertResponseStatusCodeSame(200);
        $shots = $page->filter(self::ROUNDS.' figure.screenshot');
        self::assertCount(3, $shots);
        self::assertCount(0, $shots->filter('img'));
        self::assertSame(
            ['Processing…', 'We could not process this image. Try again.', 'We could not process this image. Try again.'],
            $shots->each(static fn (Crawler $shot): string => $shot->filter('.screenshot__placeholder')->text()),
        );
        self::assertSame('Round 0 · Report · by Mia · 0 pins', $page->filter(self::ROUNDS.' .round-card__headline')->text());
    }

    #[Test]
    public function reviewAndChecklistRoundsAreRowsWithAnOpenRoundButtonThatDoesNothingYetAndShowNoPicture(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $checklist = $this->addRound($ticketId, 1, 'checklist', 'Omar', self::SHARED_AT);
        $review = $this->addRound($ticketId, 2, 'review', 'Lena', self::SHARED_AT);
        $this->addScreenshot($checklist, $this->addMedia('ready'), 0, 'Hidden in a row', null);
        $this->addScreenshot($review, $this->addMedia('ready'), 0, 'Hidden in a row too', null);
        $this->logIn($this->client, $this->people['Mia']);

        $page = $this->client->request('GET', self::ROUNDS_PAGE, [], [], self::TURBO_FRAME_HEADERS);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            ['Round 1 · Checklist from the report · by Omar', 'Round 2 · Review · by Lena'],
            $page->filter(self::ROUNDS.' .round-card__headline')->each(static fn (Crawler $headline): string => $headline->text()),
        );
        self::assertSame(['Open round', 'Open round'], $page->filter(self::ROUNDS.' button.round-card__open[disabled]')->each(static fn (Crawler $button): string => $button->text()));
        self::assertCount(0, $page->filter(self::ROUNDS.' img, '.self::ROUNDS.' figure'));
        self::assertStringNotContainsString('Hidden in a row', (string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function aSharedDraftShowsToEveryoneAndTheViewersOwnUnsharedDraftIsPrivateWhileAnotherPersonsIsAbsent(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $shared = $this->addRound($ticketId, 3, 'draft', 'Omar', self::SHARED_AT);
        $this->addScreenshot($shared, $this->addMedia('ready'), 0, 'Shared self-check', null, 'looks_right');
        $this->addScreenshot($shared, $this->addMedia('ready'), 1, 'Second look', null, 'not_right');
        $omarDraft = $this->addRound($ticketId, null, 'draft', 'Omar', null);
        $this->addScreenshot($omarDraft, $this->addMedia('ready'), 0, 'Omar own draft shot', null);
        $lenaDraft = $this->addRound($ticketId, null, 'draft', 'Lena', null);
        $this->addScreenshot($lenaDraft, $this->addMedia('ready'), 0, self::LENA_DRAFT_TITLE, null);

        $this->logIn($this->client, $this->people['Omar']);
        $page = $this->client->request('GET', self::ROUNDS_PAGE, [], [], self::TURBO_FRAME_HEADERS);
        self::assertResponseStatusCodeSame(200);
        $cards = $page->filter(self::ROUNDS.' .round-card');
        self::assertSame(
            ['Round 3 · self-check by Omar', 'Draft round · only you can see this'],
            $cards->filter('.round-card__headline')->each(static fn (Crawler $headline): string => $headline->text()),
        );
        self::assertSame(['1 of 2 look right', '0 of 1 look right'], $cards->filter('.round-card__status')->each(static fn (Crawler $status): string => $status->text()));
        self::assertSame(['PRIVATE'], $cards->filter('.round-card__private')->each(static fn (Crawler $tag): string => $tag->text()));
        self::assertCount(1, $page->filter(self::ROUNDS.' .round-card--private'));
        self::assertStringNotContainsString(self::LENA_DRAFT_TITLE, (string) $this->client->getResponse()->getContent());

        foreach (['Mia', 'Sara'] as $person) {
            $this->logIn($this->client, $this->people[$person]);
            $page = $this->client->request('GET', self::ROUNDS_PAGE, [], [], self::TURBO_FRAME_HEADERS);
            self::assertResponseStatusCodeSame(200);
            self::assertSame(['Round 3 · self-check by Omar'], $page->filter(self::ROUNDS.' .round-card__headline')->each(static fn (Crawler $headline): string => $headline->text()), $person);
            self::assertCount(0, $page->filter(self::ROUNDS.' .round-card--private'), $person);
            self::assertStringNotContainsString('Omar own draft shot', (string) $this->client->getResponse()->getContent(), $person);
            self::assertStringNotContainsString(self::LENA_DRAFT_TITLE, (string) $this->client->getResponse()->getContent(), $person);
        }
    }

    #[Test]
    public function aTeamOnlyTicketsRoundsReachNoOneOutsideTheTeam(): void
    {
        $ticketId = $this->addTicket('SHOP-1', 'team');
        $roundId = $this->addRound($ticketId, 0, 'report', 'Lena', self::SHARED_AT);
        $this->addScreenshot($roundId, $this->addMedia('ready'), 0, self::TEAM_ONLY_TITLE, null);

        $this->logIn($this->client, $this->people['Lena']);
        $this->client->request('GET', self::ROUNDS_PAGE, [], [], self::TURBO_FRAME_HEADERS);
        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString(self::TEAM_ONLY_TITLE, (string) $this->client->getResponse()->getContent());

        $this->logIn($this->client, $this->people['Raj']);
        $this->client->request('GET', self::ROUNDS_PAGE, [], [], self::TURBO_FRAME_HEADERS);
        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString(self::TEAM_ONLY_TITLE, (string) $this->client->getResponse()->getContent());
        self::assertCount(0, $this->client->getCrawler()->filter('.round-card, .screenshot, img.screenshot__image'));
    }

    /**
     * The section's own reads do not grow with the rounds: one report with one picture and pin, then the same ticket with the
     * report, 5 more rounds by different people with 3 pictures each, a shared draft and the viewer's draft read the same
     * number of queries.
     */
    #[Test]
    public function theSectionReadsTheSameNumberOfQueriesWhateverTheNumberOfRounds(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $report = $this->addRound($ticketId, 0, 'report', 'Mia', self::SHARED_AT);
        $this->addPin($this->addScreenshot($report, $this->addMedia('ready'), 0, 'First', null), 1, '10.00', '10.00', 'A note', 'Mia');
        $this->logIn($this->client, $this->people['Omar']);
        $this->queryCountOf(self::ROUNDS_PAGE);
        $oneRoundQueryCount = $this->queryCountOf(self::ROUNDS_PAGE);

        foreach (['Lena', 'Omar', 'Raj', 'Sara', 'Mia'] as $number => $owner) {
            $round = $this->addRound($ticketId, $number + 1, 0 === $number % 2 ? 'draft' : 'review', $owner, self::SHARED_AT);
            foreach ([0, 1, 2] as $position) {
                $screenshot = $this->addScreenshot($round, $this->addMedia('ready'), $position, null, null);
                $this->addPin($screenshot, 1, '10.00', '10.00', 'A note', $owner);
            }
        }
        $own = $this->addRound($ticketId, null, 'draft', 'Omar', null);
        $this->addScreenshot($own, $this->addMedia('pending'), 0, null, null);
        $manyRoundsQueryCount = $this->queryCountOf(self::ROUNDS_PAGE);

        self::assertCount(7, $this->client->getCrawler()->filter(self::ROUNDS.' .round-card'));
        self::assertCount(11, $this->client->getCrawler()->filter(self::ROUNDS.' figure.screenshot'));
        self::assertSame($oneRoundQueryCount, $manyRoundsQueryCount);
    }

    #[Test]
    public function pastTheCapTheNewestTwentyNumberedRoundsShowWithTheCountOfOlderOnesLeftOut(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $this->addRound($ticketId, 0, 'report', 'Mia', self::SHARED_AT);
        for ($number = 1; $number <= 22; ++$number) {
            $this->addRound($ticketId, ($number * 7) % 22 + 1, 'review', 'Lena', self::SHARED_AT);
        }
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', self::ROUNDS_PAGE, [], [], self::TURBO_FRAME_HEADERS);

        self::assertResponseStatusCodeSame(200);
        $headlines = $page->filter(self::ROUNDS.' .round-card__headline')->each(static fn (Crawler $headline): string => $headline->text());
        self::assertCount(21, $headlines);
        self::assertSame('Round 0 · Report · by Mia · 0 pins', $headlines[0]);
        self::assertSame('Round 3 · Review · by Lena', $headlines[1], 'Rounds 1 and 2 are the oldest two of 22.');
        self::assertSame('Round 22 · Review · by Lena', $headlines[20]);
        self::assertSame('2 older rounds are not shown.', $page->filter(self::ROUNDS.' .rounds__older')->text());
    }

    #[Test]
    public function oneRoundPastTheCapIsNamedInTheSingular(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        for ($number = 1; $number <= 21; ++$number) {
            $this->addRound($ticketId, $number, 'review', 'Lena', self::SHARED_AT);
        }
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', self::ROUNDS_PAGE, [], [], self::TURBO_FRAME_HEADERS);

        self::assertSame('1 older round is not shown.', $page->filter(self::ROUNDS.' .rounds__older')->text());
    }

    #[Test]
    public function aTicketWithinTheCapShowsNoNoteAboutOlderRounds(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        for ($number = 1; $number <= 20; ++$number) {
            $this->addRound($ticketId, $number, 'review', 'Lena', self::SHARED_AT);
        }
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', self::ROUNDS_PAGE, [], [], self::TURBO_FRAME_HEADERS);

        self::assertCount(20, $page->filter(self::ROUNDS.' .round-card'));
        self::assertCount(0, $page->filter(self::ROUNDS.' .rounds__older'));
    }

    #[Test]
    public function aViewerWhoMayNotSeeATeamOnlyTicketGetsNotFoundWithNoRoundText(): void
    {
        $ticketId = $this->addTicket('SHOP-1', 'team');
        $roundId = $this->addRound($ticketId, 0, 'report', 'Lena', self::SHARED_AT);
        $this->addScreenshot($roundId, $this->addMedia('ready'), 0, self::TEAM_ONLY_TITLE, null);
        $this->logIn($this->client, $this->people['Raj']);

        foreach ([[], self::TURBO_FRAME_HEADERS] as $headers) {
            $this->client->request('GET', self::ROUNDS_PAGE, [], [], $headers);

            self::assertResponseStatusCodeSame(404);
            self::assertStringContainsString(RoundsController::NOT_FOUND, (string) $this->client->getResponse()->getContent());
            self::assertStringNotContainsString(self::TEAM_ONLY_TITLE, (string) $this->client->getResponse()->getContent());
            self::assertCount(0, $this->client->getCrawler()->filter('.round-card, .screenshot, img'));
        }
    }

    #[Test]
    public function someoneOfAnotherOrganizationGetsNotFoundForATicketOfAcmeFoods(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $roundId = $this->addRound($ticketId, 0, 'report', 'Mia', self::SHARED_AT);
        $this->addScreenshot($roundId, $this->addMedia('ready'), 0, self::TEAM_ONLY_TITLE, null);
        $betaBakery = $this->createOrganization('Beta Bakery');
        $ben = $this->createUserWithoutOrganization('Ben');
        $this->addMembership($ben, $betaBakery);
        $this->setOrgRoles($ben, $betaBakery, '{admin}');
        $this->logIn($this->client, $ben);

        $this->client->request('GET', self::ROUNDS_PAGE, [], [], self::TURBO_FRAME_HEADERS);

        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString(self::TEAM_ONLY_TITLE, (string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function anAnonymousVisitorIsSentToLogIn(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $this->addRound($ticketId, 0, 'report', 'Mia', self::SHARED_AT);

        $this->client->request('GET', self::ROUNDS_PAGE, [], [], self::TURBO_FRAME_HEADERS);

        self::assertResponseStatusCodeSame(302);
        self::assertStringEndsWith('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    /**
     * @return iterable<string, array{string}> a key, as in the URL, of no ticket
     */
    public static function keysOfNoTicket(): iterable
    {
        yield 'a number no ticket has' => ['SHOP-99'];
        yield 'a malformed key' => ['not-a-key'];
        yield 'the key in lower case' => ['shop-1'];
        yield 'a trailing newline' => ['SHOP-1%0A'];
        yield 'a number past the integer range' => ['SHOP-99999999999999999999'];
    }

    #[Test]
    #[DataProvider('keysOfNoTicket')]
    public function aKeyOfNoTicketIsNotFound(string $key): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $this->addRound($ticketId, 0, 'report', 'Mia', self::SHARED_AT);
        $this->logIn($this->client, $this->people['Omar']);

        $this->client->request('GET', '/t/'.$key.'/rounds', [], [], self::TURBO_FRAME_HEADERS);

        self::assertResponseStatusCodeSame(404);
        self::assertCount(0, $this->client->getCrawler()->filter('.round-card'));
    }

    #[Test]
    public function withoutTheFrameHeaderTheRoundsComeAsAPageOfTheirOwnWithABackLinkAndTheFrameAsTheOnlyFrame(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $this->addRound($ticketId, 0, 'report', 'Mia', self::SHARED_AT);
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', self::ROUNDS_PAGE);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Rounds', $page->filter('h1')->text());
        self::assertSame('/t/SHOP-1', $page->filter('a.ticket__back')->attr('href'));
        self::assertSame(['Round 0 · Report · by Mia · 0 pins'], $page->filter('.round-card__headline')->each(static fn (Crawler $headline): string => $headline->text()));
        self::assertSame('Turbo-Frame', $this->client->getResponse()->headers->get('Vary'));
    }

    #[Test]
    public function aCardShowsAtMostTenPicturesLazilyAndSaysHowManyMoreAreNotShown(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $report = $this->addRound($ticketId, 0, 'report', 'Mia', self::SHARED_AT);
        $shared = $this->addRound($ticketId, 1, 'draft', 'Omar', self::SHARED_AT);
        $own = $this->addRound($ticketId, null, 'draft', 'Omar', null);
        foreach (range(0, RoundCards::MAX_PICTURES_PER_CARD + 1) as $position) {
            $this->addScreenshot($report, $this->addMedia('ready'), $position, 'Report shot '.$position, null);
        }
        foreach (range(0, RoundCards::MAX_PICTURES_PER_CARD) as $position) {
            $this->addScreenshot($shared, $this->addMedia('ready'), $position, 'Shared shot '.$position, null);
        }
        foreach (range(0, RoundCards::MAX_PICTURES_PER_CARD - 1) as $position) {
            $this->addScreenshot($own, $this->addMedia('ready'), $position, 'Own shot '.$position, null);
        }
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', self::ROUNDS_PAGE, [], [], self::TURBO_FRAME_HEADERS);

        self::assertResponseStatusCodeSame(200);
        $cards = $page->filter(self::ROUNDS.' .round-card');
        self::assertCount(3, $cards);
        self::assertSame([10, 10, 10], $cards->each(static fn (Crawler $card): int => $card->filter('img.screenshot__image')->count()));
        self::assertSame(
            ['2 more screenshots are not shown.', '1 more screenshot is not shown.'],
            $cards->filter('.round-card__more')->each(static fn (Crawler $note): string => $note->text()),
            'A card that shows every picture has no note.',
        );
        self::assertSame(['lazy'], array_unique($cards->filter('img.screenshot__image')->each(static fn (Crawler $image): ?string => $image->attr('loading'))));
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Report shot 9', $content);
        self::assertStringNotContainsString('Report shot 10', $content);
        self::assertStringNotContainsString('Report shot 11', $content);
        self::assertStringNotContainsString('Shared shot 10', $content);
        self::assertSame('Round 0 · Report · by Mia · 0 pins', $cards->eq(0)->filter('.round-card__headline')->text());
        self::assertSame('0 of 11 look right', $cards->eq(1)->filter('.round-card__status')->text(), 'The status counts every screenshot, shown or not.');
    }

    /**
     * A round with a picture and a pin adds five queries to a frame with no round: its screenshots, their pins, the
     * owners' membership and names, and the signing. Whatever the number of rounds, the count stays that
     * (theSectionReadsTheSameNumberOfQueriesWhateverTheNumberOfRounds).
     */
    #[Test]
    public function aFrameWithARoundReadsFiveMoreQueriesThanOneWithNone(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $this->logIn($this->client, $this->people['Omar']);
        $this->queryCountOf(self::ROUNDS_PAGE);
        $noRoundQueryCount = $this->queryCountOf(self::ROUNDS_PAGE);

        $report = $this->addRound($ticketId, 0, 'report', 'Mia', self::SHARED_AT);
        $this->addPin($this->addScreenshot($report, $this->addMedia('ready'), 0, 'First', null), 1, '10.00', '10.00', 'A note', 'Mia');
        $oneRoundQueryCount = $this->queryCountOf(self::ROUNDS_PAGE);

        self::assertSame(self::EMPTY_FRAME_QUERIES, $noRoundQueryCount);
        self::assertSame(self::EMPTY_FRAME_QUERIES + 5, $oneRoundQueryCount, 'The rounds, screenshots, pins, the owners\' names and the signing.');
    }

    private function queryCountOf(string $uri): int
    {
        $debugData = self::getContainer()->get('doctrine.debug_data_holder');
        $debugData->reset();
        $this->client->request('GET', $uri, [], [], self::TURBO_FRAME_HEADERS);
        self::assertResponseStatusCodeSame(200);

        $queryCount = 0;
        foreach ($debugData->getData() as $queriesOfOneConnection) {
            $queryCount += \count($queriesOfOneConnection);
        }

        return $queryCount;
    }

    /**
     * @return string the ticket's id
     */
    private function addTicket(string $key, string $visibility = 'all'): string
    {
        $ticketId = Uuid::v7()->toRfc4122();
        $parameters = [
            $this->acmeFoods->toString(), $ticketId, $this->shopProject, $this->shop->toString(), $key, $this->stepId,
            $this->people['Mia']->id()->toString(), $this->people['Omar']->id()->toString(), $visibility,
        ];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO tkt_ticket (organization_id, id, project_id, team_id, number, key, title, description, type, priority, priority_rank,
                 step_id, reporter_id, assignee_id, visibility, version, components, check_reasons, created_at, updated_at)
             VALUES (?, ?, ?, ?, 1, ?, 'Cart total shows the wrong sum', '', 'bug', 'major', 2, ?, ?, ?, ?, 3, '{}', '{}',
                 TIMESTAMPTZ '2026-11-04 10:00:00+00', TIMESTAMPTZ '2026-11-05 10:00:00+00')",
            $parameters,
        ));

        return $ticketId;
    }

    /**
     * @param int|null $number null for an unshared draft
     *
     * @return string the round's id
     */
    private function addRound(string $ticketId, ?int $number, string $kind, string $owner, ?string $sharedAt): string
    {
        $roundId = Uuid::v7()->toRfc4122();
        $parameters = [$this->acmeFoods->toString(), $roundId, $ticketId, $number, $kind, $this->people[$owner]->id()->toString(), $sharedAt];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO rvw_round (organization_id, id, ticket_id, number, kind, owner_id, shared_at, state, version, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'open', 1, '2026-11-05 08:00:00+00')",
            $parameters,
        ));

        return $roundId;
    }

    /**
     * @return string the screenshot's id
     */
    private function addScreenshot(string $roundId, string $mediaId, int $position, ?string $title, ?string $summary, ?string $verdict = null): string
    {
        $screenshotId = Uuid::v7()->toRfc4122();
        $parameters = [$this->acmeFoods->toString(), $screenshotId, $roundId, $mediaId, $position, $verdict, $title, $summary];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO rvw_screenshot (organization_id, id, round_id, media_id, position, verdict, title, summary, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, '2026-11-05 08:00:00+00')",
            $parameters,
        ));

        return $screenshotId;
    }

    private function addPin(string $screenshotId, int $number, string $x, string $y, string $note, string $author, string $source = 'human', ?string $aiState = null): void
    {
        $parameters = [$this->acmeFoods->toString(), Uuid::v7()->toRfc4122(), $screenshotId, $number, $x, $y, $note, $source, $aiState, $this->people[$author]->id()->toString()];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO rvw_pin (organization_id, id, screenshot_id, number, x_pct, y_pct, note, source, ai_state, author_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '2026-11-05 08:00:00+00')",
            $parameters,
        ));
    }

    /**
     * @param 'ready'|'pending'|'rejected' $status
     *
     * @return string the media id
     */
    private function addMedia(string $status): string
    {
        $mediaId = Uuid::v7()->toRfc4122();
        $parameters = [
            $this->acmeFoods->toString(), $mediaId, 'uploads/'.$mediaId, $this->people['Mia']->id()->toString(), $status,
            'rejected' === $status ? 'The file is not a picture.' : null,
            'ready' === $status ? hash('sha256', $mediaId) : null,
            'ready' === $status ? 1280 : null,
            'ready' === $status ? 800 : null,
            'ready' === $status ? json_encode(['view' => 'media/'.$mediaId.'/view.webp', 'thumb' => 'media/'.$mediaId.'/thumb.webp'], \JSON_THROW_ON_ERROR) : null,
        ];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO med_object (organization_id, id, storage_key, mime, bytes, uploaded_by, created_at, status, reject_reason, sha256, width, height, variants)
             VALUES (?, ?, ?, 'image/png', 2048, ?, '2026-11-05 08:00:00+00', ?, ?, ?, ?, ?, CAST(? AS jsonb))",
            $parameters,
        ));

        return $mediaId;
    }
}
