<?php

declare(strict_types=1);

namespace App\Tests\Functional\Review;

use App\Identity\Domain\User;
use App\Review\UI\Component\RoundBar;
use App\Review\UI\RoundsController;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
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
 * The round bar of Acme Foods' ticket SHOP-1 (P14.T7 step 3), GET /t/SHOP-1/round-bar, through the real firewall,
 * voters and database. Mia reported the ticket and Omar has it; Lena leads the Shop team, Raj is a manager in no team and
 * Sara the admin. The clock is fixed on 6 Nov 2026, before today: a clock ahead of the login would end the session as
 * idle.
 */
final class RoundBarTest extends WebTestCase
{
    use CreatesAcmeFoodsTeams;
    use CreatesTeams;
    use Factories;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }
    use SeedsTicketRounds;

    private const string NOW = '2026-11-06 12:00:00 UTC';
    private const string SHARED_AT = '2026-11-05 09:00:00+00';
    private const string ROUND_BAR_PAGE = '/t/SHOP-1/round-bar';
    private const string FRAME = 'turbo-frame#'.RoundBar::FRAME_ID;
    private const string BAR = self::FRAME.' .round-bar';
    private const array TURBO_FRAME_HEADERS = ['HTTP_TURBO_FRAME' => RoundBar::FRAME_ID];

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
        $this->removeSeededRounds();
        $this->removeCommittedProjects();
        $this->removeUsersAndOrganizations();
    }

    /**
     * Round 0 and the checklist round are open too, as they always are, yet only the review round is a hand-off round.
     */
    #[Test]
    public function anOpenReviewRoundShowsTheBarWithWhoseTurnItIsAndALinkToTheRound(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $this->addRound($ticketId, 0, 'report', 'Mia', self::SHARED_AT);
        $this->addRound($ticketId, 1, 'checklist', 'Mia', self::SHARED_AT);
        $this->addRound($ticketId, 2, 'review', 'Lena', self::SHARED_AT);
        $this->addTurnRowOf($ticketId, 'Mia');
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', self::ROUND_BAR_PAGE, [], [], self::TURBO_FRAME_HEADERS);

        self::assertResponseStatusCodeSame(200);
        $bar = $page->filter(self::BAR);
        self::assertCount(1, $bar);
        self::assertSame('Round 2 is open · Ready for Mia', $bar->filter('.round-bar__text')->text());
        $link = $bar->filter('a.round-bar__open');
        self::assertSame('Open round', $link->text());
        self::assertSame('/t/SHOP-1/rounds/2', $link->attr('href'));
        self::assertSame('_top', $link->attr('data-turbo-frame'));
    }

    /**
     * Mia's shared draft is findings for Omar, the assignee (ADR-0044): it is ready for him, though the turn row names
     * Lena. Omar is told it is ready for him.
     */
    #[Test]
    public function aFindingsRoundIsReadyForTheAssigneeWhateverTheTurnRowSays(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $this->addRound($ticketId, 1, 'draft', 'Mia', self::SHARED_AT);
        $this->addTurnRowOf($ticketId, 'Lena');

        $this->logIn($this->client, $this->people['Lena']);
        $page = $this->client->request('GET', self::ROUND_BAR_PAGE, [], [], self::TURBO_FRAME_HEADERS);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('Round 1 is open · Ready for Omar', $page->filter(self::BAR.' .round-bar__text')->text());

        $this->logIn($this->client, $this->people['Omar']);
        $page = $this->client->request('GET', self::ROUND_BAR_PAGE, [], [], self::TURBO_FRAME_HEADERS);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('Round 1 is open · Ready for you', $page->filter(self::BAR.' .round-bar__text')->text());
    }

    /**
     * Omar's own shared draft is his self-check, ready for whoever's turn it is.
     */
    #[Test]
    public function theAssigneesOwnSharedDraftIsReadyForWhoseTurnItIs(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $this->addRound($ticketId, 1, 'draft', 'Omar', self::SHARED_AT);
        $this->addTurnRowOf($ticketId, 'Mia');
        $this->logIn($this->client, $this->people['Lena']);

        $page = $this->client->request('GET', self::ROUND_BAR_PAGE, [], [], self::TURBO_FRAME_HEADERS);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Round 1 is open · Ready for Mia', $page->filter(self::BAR.' .round-bar__text')->text());
    }

    #[Test]
    public function aRoundWithNoTurnRowNamesNobody(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $this->addRound($ticketId, 1, 'review', 'Lena', self::SHARED_AT);
        $this->logIn($this->client, $this->people['Mia']);

        $page = $this->client->request('GET', self::ROUND_BAR_PAGE, [], [], self::TURBO_FRAME_HEADERS);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Round 1 is open', $page->filter(self::BAR.' .round-bar__text')->text());
        self::assertSame('/t/SHOP-1/rounds/1', $page->filter(self::BAR.' a.round-bar__open')->attr('href'));
    }

    /**
     * @return iterable<string, array{bool}> whether the ticket has a closed review round and a closed shared draft
     */
    public static function ticketsWithNoOpenSharedRound(): iterable
    {
        yield 'no rounds at all' => [false];
        yield 'only closed rounds besides Round 0 and the checklist' => [true];
    }

    #[Test]
    #[DataProvider('ticketsWithNoOpenSharedRound')]
    public function noRoundIsOpenSoTheFrameIsEmpty(bool $hasClosedRounds): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $this->addTurnRowOf($ticketId, 'Mia');
        if ($hasClosedRounds) {
            $this->addRound($ticketId, 0, 'report', 'Mia', self::SHARED_AT);
            $this->addRound($ticketId, 1, 'checklist', 'Mia', self::SHARED_AT);
            $this->closeRound($this->addRound($ticketId, 2, 'review', 'Lena', self::SHARED_AT));
            $this->closeRound($this->addRound($ticketId, 3, 'draft', 'Mia', self::SHARED_AT));
        }
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', self::ROUND_BAR_PAGE, [], [], self::TURBO_FRAME_HEADERS);

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $page->filter(self::FRAME));
        self::assertSame('', trim($page->filter(self::FRAME)->html()));
    }

    /**
     * Old data can hold two open shared rounds (ADR-0048 allows one); the bar names the lower, as a share refusal does.
     * Round 3 is written first, so the order of the rows does not decide.
     */
    #[Test]
    public function theLowestNumberedOpenRoundWinsWhenTwoAreOpen(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $this->addRound($ticketId, 3, 'draft', 'Mia', self::SHARED_AT);
        $this->addRound($ticketId, 2, 'review', 'Lena', self::SHARED_AT);
        $this->closeRound($this->addRound($ticketId, 1, 'review', 'Lena', self::SHARED_AT));
        $this->addTurnRowOf($ticketId, 'Mia');
        $this->logIn($this->client, $this->people['Lena']);

        $page = $this->client->request('GET', self::ROUND_BAR_PAGE, [], [], self::TURBO_FRAME_HEADERS);

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $page->filter(self::BAR));
        self::assertSame('Round 2 is open · Ready for Mia', $page->filter(self::BAR.' .round-bar__text')->text());
        self::assertSame('/t/SHOP-1/rounds/2', $page->filter(self::BAR.' a.round-bar__open')->attr('href'));
    }

    /**
     * Omar's private draft is open, yet it is no shared round: no bar for anyone, Omar included, and no trace of it for
     * Lena (lead), Mia (reporter) or Sara (admin), also beside an open shared round.
     */
    #[Test]
    public function omarsPrivateDraftNeverShowsInTheBar(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $omarsDraft = $this->addRound($ticketId, null, 'draft', 'Omar', null);
        $this->addTurnRowOf($ticketId, 'Mia');

        foreach (['Omar', 'Lena', 'Mia', 'Sara'] as $person) {
            $this->logIn($this->client, $this->people[$person]);
            $page = $this->client->request('GET', self::ROUND_BAR_PAGE, [], [], self::TURBO_FRAME_HEADERS);
            self::assertResponseStatusCodeSame(200, $person);
            self::assertCount(1, $page->filter(self::FRAME), $person);
            self::assertCount(0, $page->filter(self::BAR), $person);
            self::assertStringNotContainsString($omarsDraft, (string) $this->client->getResponse()->getContent(), $person);
        }

        $this->addRound($ticketId, 1, 'review', 'Lena', self::SHARED_AT);
        foreach (['Lena' => 'Ready for Mia', 'Mia' => 'Ready for you', 'Sara' => 'Ready for Mia'] as $person => $readyFor) {
            $this->logIn($this->client, $this->people[$person]);
            $page = $this->client->request('GET', self::ROUND_BAR_PAGE, [], [], self::TURBO_FRAME_HEADERS);
            self::assertResponseStatusCodeSame(200, $person);
            self::assertSame(
                ['Round 1 is open · '.$readyFor],
                $page->filter(self::BAR.' .round-bar__text')->each(static fn (Crawler $text): string => $text->text()),
                $person,
            );
            self::assertStringNotContainsString($omarsDraft, (string) $this->client->getResponse()->getContent(), $person);
        }
    }

    /**
     * @return iterable<string, array{string}> a key, as in the URL, of no ticket
     */
    public static function keysOfNoTicket(): iterable
    {
        yield 'a number no ticket has' => ['SHOP-99'];
        yield 'a malformed key' => ['not-a-key'];
        yield 'a trailing newline' => ['SHOP-1%0A'];
    }

    #[Test]
    #[DataProvider('keysOfNoTicket')]
    public function aKeyOfNoTicketIsNotFound(string $key): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $this->addRound($ticketId, 1, 'review', 'Lena', self::SHARED_AT);
        $this->logIn($this->client, $this->people['Omar']);

        $this->client->request('GET', '/t/'.$key.'/round-bar', [], [], self::TURBO_FRAME_HEADERS);

        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString(RoundsController::NOT_FOUND, (string) $this->client->getResponse()->getContent());
        self::assertCount(0, $this->client->getCrawler()->filter('.round-bar'));
    }

    #[Test]
    public function someoneOfAnotherOrganizationGetsNotFoundForATicketOfAcmeFoods(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $this->addRound($ticketId, 1, 'review', 'Lena', self::SHARED_AT);
        $betaBakery = $this->createOrganization('Beta Bakery');
        $ben = $this->createUserWithoutOrganization('Ben');
        $this->addMembership($ben, $betaBakery);
        $this->setOrgRoles($ben, $betaBakery, '{admin}');
        $this->logIn($this->client, $ben);

        $this->client->request('GET', self::ROUND_BAR_PAGE, [], [], self::TURBO_FRAME_HEADERS);

        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString(RoundsController::NOT_FOUND, (string) $this->client->getResponse()->getContent());
        self::assertCount(0, $this->client->getCrawler()->filter('.round-bar'));
    }

    #[Test]
    public function aViewerWhoMayNotSeeATeamOnlyTicketGetsNotFound(): void
    {
        $ticketId = $this->addTicket('SHOP-1', 'team');
        $this->addRound($ticketId, 1, 'review', 'Lena', self::SHARED_AT);
        $this->logIn($this->client, $this->people['Raj']);

        $this->client->request('GET', self::ROUND_BAR_PAGE, [], [], self::TURBO_FRAME_HEADERS);

        self::assertResponseStatusCodeSame(404);
        self::assertCount(0, $this->client->getCrawler()->filter('.round-bar'));
    }

    #[Test]
    public function anAnonymousVisitorIsSentToLogIn(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $this->addRound($ticketId, 1, 'review', 'Lena', self::SHARED_AT);

        $this->client->request('GET', self::ROUND_BAR_PAGE, [], [], self::TURBO_FRAME_HEADERS);

        self::assertResponseStatusCodeSame(302);
        self::assertStringEndsWith('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    /**
     * The bar reads the rounds once, whatever their number: Round 0 with a pinned screenshot and an open review round,
     * then the same with three more closed rounds, two of them shared drafts with pinned screenshots.
     */
    #[Test]
    public function theBarReadsTheSameNumberOfQueriesForTwoRoundsAndForFive(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $this->addPinnedScreenshotTo($this->addRound($ticketId, 0, 'report', 'Mia', self::SHARED_AT), 'Mia');
        $this->addRound($ticketId, 4, 'review', 'Lena', self::SHARED_AT);
        $this->addTurnRowOf($ticketId, 'Mia');
        $this->logIn($this->client, $this->people['Omar']);
        $this->queryCountOf(self::ROUND_BAR_PAGE);
        $twoRoundsQueryCount = $this->queryCountOf(self::ROUND_BAR_PAGE);
        self::assertSame('Round 4 is open · Ready for Mia', $this->client->getCrawler()->filter(self::BAR.' .round-bar__text')->text());

        $this->addPinnedScreenshotTo($this->closedDraftOf($ticketId, 1, 'Mia'), 'Mia');
        $this->addPinnedScreenshotTo($this->closedDraftOf($ticketId, 2, 'Lena'), 'Lena');
        $this->closeRound($this->addRound($ticketId, 3, 'review', 'Lena', self::SHARED_AT));
        $manyRoundsQueryCount = $this->queryCountOf(self::ROUND_BAR_PAGE);

        self::assertSame('Round 4 is open · Ready for Mia', $this->client->getCrawler()->filter(self::BAR.' .round-bar__text')->text());
        self::assertSame($twoRoundsQueryCount, $manyRoundsQueryCount);
    }

    /**
     * The ticket page loads the bar in an eager frame inside the header's state, which a move's Turbo Stream replaces,
     * so the bar is read again after every move; the page itself reads no round.
     */
    #[Test]
    public function theTicketPageLoadsTheBarInAnEagerFrameInsideTheHeadersState(): void
    {
        $ticketId = $this->addTicket('SHOP-1');
        $this->addRound($ticketId, 1, 'review', 'Lena', self::SHARED_AT);
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', '/t/SHOP-1');

        self::assertResponseStatusCodeSame(200);
        $frame = $page->filter('header.ticket__header #'.TicketPageParts::STATE_ID.' '.self::FRAME);
        self::assertCount(1, $frame);
        self::assertSame(self::ROUND_BAR_PAGE, $frame->attr('src'));
        self::assertNull($frame->attr('loading'), 'An eager frame: the bar belongs at the top as the page shows.');
        self::assertCount(0, $page->filter('.round-bar'));
    }

    /**
     * A closed shared draft of $owner, numbered $number.
     */
    private function closedDraftOf(string $ticketId, int $number, string $owner): string
    {
        $roundId = $this->addRound($ticketId, $number, 'draft', $owner, self::SHARED_AT);
        $this->closeRound($roundId);

        return $roundId;
    }

    private function addPinnedScreenshotTo(string $roundId, string $author): void
    {
        $this->addPin($this->addScreenshot($roundId, $this->addMedia('ready'), 0, null, null), 1, '10.00', '10.00', 'A note', $author);
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
}
