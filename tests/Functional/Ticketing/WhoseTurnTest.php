<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ticketing;

use App\Identity\Domain\User;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use App\Ticketing\UI\Component\TicketFilters;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Uid\Uuid;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
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
    use InteractsWithLiveComponents;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }

    private const string NOW = '2026-11-06 12:00:00 UTC';
    private const string TURN = '/tickets/turn';
    private const string SHELL = '/_test/menu/shell';
    /** A refused viewer must never get a word of it. */
    private const string HIDDEN_TITLE = 'Price rules for staff';
    /**
     * Beside the shell's, whatever the number of cards: the triager (RoleTicketVisibilities), the cards, every card's
     * names, and the rail's three (team names, the team's projects, their counts); the team's people are cached.
     */
    private const int PAGE_QUERY_COUNT = 6;
    private const string RAIL_FRAME = 'project-rail-frame';
    private const string CARDS_FRAME = 'ticket-list-frame';

    private KernelBrowser $client;

    /** @var array<string, User> */
    private array $people;

    private string $shopProject;

    private string $doingStep;

    private ?string $cartProject = null;

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
        $omarsCardId = 'turn-'.$this->people['Omar']->id()->toString();
        self::assertSame($omarsCardId, $omarsCard->attr('id'));
        $openUrl = (string) $omarsCard->filter('.turn__more')->attr('href');
        self::assertSame(self::TURN.'?project=SHOP&open='.$this->people['Omar']->id()->toString().'#'.$omarsCardId, $openUrl);

        $opened = $this->client->request('GET', $openUrl);

        self::assertResponseStatusCodeSame(200);
        $openedCard = $opened->filter('.turn__card')->eq(1);
        self::assertSame(['9 days', '8 days', '7 days', '5 days', '3 days', '2 days', '1 day'], $openedCard->filter('.turn__wait')->each(static fn (Crawler $wait): string => $wait->text()));
        self::assertSame('Show fewer', $openedCard->filter('.turn__more')->text());
        self::assertSame($omarsCardId, $openedCard->attr('id'));
        self::assertSame(self::TURN.'?project=SHOP#'.$omarsCardId, $openedCard->filter('.turn__more')->attr('href'));
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
        yield 'a cursor of no known form' => ['?after=yesterday'];
        yield 'a v4 uuid where a cursor is expected' => ['?after=4f6b1c9e-8d2a-4b7e-9c1d-2e3f4a5b6c7d'];
        yield 'a cursor after a person by a v4 uuid' => ['?after=1793098800.person.4f6b1c9e-8d2a-4b7e-9c1d-2e3f4a5b6c7d'];
        yield 'a cursor after a label of 101 characters' => ['?after=1793098800.label.'.str_repeat('a', 101)];
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
        $viewQuery = '?project=SHOP';

        $list = $this->client->request('GET', '/tickets'.$viewQuery.'&q=cart');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            ['/tickets'.$viewQuery, self::TURN.$viewQuery],
            $list->filter('.tickets__header a.seg')->each(static fn (Crawler $link): string => (string) $link->attr('href')),
        );

        $page = $this->client->request('GET', self::TURN.$viewQuery);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            ['/tickets'.$viewQuery, self::TURN.$viewQuery],
            $page->filter('.tickets__header a.seg')->each(static fn (Crawler $link): string => (string) $link->attr('href')),
        );
        self::assertSame('SHOP · Shop', $page->filter('.tickets__title')->text());
    }

    /**
     * OPS is a project of no team, so the team Lena picked, Mobile, decides the rail and both views keep it.
     */
    #[Test]
    public function theListAndThisPageSwitchViewsKeepingTheProjectAndThePickedTeam(): void
    {
        $this->addProject(null, 'OPS', 'Ops');
        $this->logIn($this->client, $this->people['Lena']);
        $viewQuery = '?project=OPS&team='.$this->mobile->toString();

        $list = $this->client->request('GET', '/tickets'.$viewQuery.'&q=cart');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['List', 'Whose turn?'], $list->filter('.tickets__header a.seg')->each(static fn (Crawler $link): string => $link->text()));
        self::assertSame('List', $list->filter('.tickets__header a.seg[aria-current="page"]')->text());
        self::assertSame(
            ['/tickets'.$viewQuery, self::TURN.$viewQuery],
            $list->filter('.tickets__header a.seg')->each(static fn (Crawler $link): string => (string) $link->attr('href')),
        );

        $page = $this->client->request('GET', self::TURN.$viewQuery);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Whose turn?', $page->filter('.tickets__header a.seg[aria-current="page"]')->text());
        self::assertSame(
            ['/tickets'.$viewQuery, self::TURN.$viewQuery],
            $page->filter('.tickets__header a.seg')->each(static fn (Crawler $link): string => (string) $link->attr('href')),
        );
        self::assertSame('OPS · Ops', $page->filter('.tickets__title')->text());
        self::assertSame('No tickets here yet.', $page->filter('.empty-state')->text());
    }

    #[Test]
    public function aSearchTooLongIsABadRequestThatShowsNoTicket(): void
    {
        $this->addWaitingTicket('Omar', 'build', 3, self::HIDDEN_TITLE);
        $this->logIn($this->client, $this->people['Lena']);

        $this->client->request('GET', self::TURN.'?q='.rawurlencode(str_repeat('é', 201)));

        self::assertResponseStatusCodeSame(400);
        $body = (string) $this->client->getResponse()->getContent();
        self::assertSame('The search is too long. Use 200 characters or fewer.', $body);
        self::assertStringNotContainsString(self::HIDDEN_TITLE, $body);
    }

    /**
     * Sara is an admin of no team, so the page has no team to mark people outside of; a label would still be marked.
     */
    #[Test]
    public function aViewerOfNoTeamSeesNoPersonMarkedOutsideTheTeam(): void
    {
        $this->addWaitingTicket('Omar', 'build', 3, 'Search shows no results');
        $this->addWaitingTicket('Raj', 'decide', 5, 'Coupon field takes any text');
        $this->addWaitingTicket('Mia', 'test', 7, 'Checkout button is hidden');
        $this->logIn($this->client, $this->people['Sara']);

        $page = $this->client->request('GET', self::TURN);

        self::assertResponseStatusCodeSame(200);
        $sentences = $page->filter('.turn__sentence')->each(static fn (Crawler $sentence): string => $sentence->text());
        self::assertSame([
            '1 ticket waits on Mia · for 7 days',
            '1 ticket waits on Raj · for 5 days',
            '1 ticket waits on Omar · for 3 days',
        ], $sentences);
        foreach ($sentences as $sentence) {
            self::assertStringNotContainsString(' · outside the team', $sentence);
        }
        $tones = $page->filter('.turn__card')->each(static fn (Crawler $card): string => (string) $card->attr('class'));
        self::assertCount(3, $tones);
        foreach ($tones as $tone) {
            self::assertSame('turn__card turn__card--team', $tone);
        }
    }

    #[Test]
    public function aPersonWithNoNameIsShownAsAnUnknownPerson(): void
    {
        $this->addTicketWaitingOn(Uuid::v7()->toRfc4122(), 'build', 3, 'Search shows no results');
        $this->logIn($this->client, $this->people['Lena']);

        $page = $this->client->request('GET', self::TURN.'?project=SHOP');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['an unknown person'], $page->filter('.turn__name')->each(static fn (Crawler $name): string => $name->text()));
        self::assertSame('1 ticket waits on an unknown person · for 3 days · outside the team', $page->filter('.turn__sentence')->text());
    }

    /**
     * A label's card id is its ASCII slug and a hash of the label; a label with no ASCII letter or digit keeps the hash.
     */
    #[Test]
    public function aLabelsCardHasAStableIdThatShowMoreAndShowFewerLandOn(): void
    {
        foreach ([6, 5, 4, 3, 2, 1] as $waitDays) {
            $this->addWaitingTicket(null, 'input', $waitDays, 'Customer waits '.$waitDays);
        }
        $this->addTicketWaitingOn(null, 'input', 1, 'Supplier waits', turnLabel: '顧客');
        $this->logIn($this->client, $this->people['Lena']);

        $page = $this->client->request('GET', self::TURN.'?project=SHOP');

        self::assertResponseStatusCodeSame(200);
        $customerCardId = 'turn-label-customer-bf376338';
        self::assertSame(
            [$customerCardId, 'turn-label-40b3e4e1'],
            $page->filter('.turn__card')->each(static fn (Crawler $card): string => (string) $card->attr('id')),
        );
        $moreUrl = (string) $page->filter('.turn__card')->eq(0)->filter('.turn__more')->attr('href');
        self::assertSame(self::TURN.'?project=SHOP&open=Customer#'.$customerCardId, $moreUrl);

        $opened = $this->client->request('GET', $moreUrl);

        self::assertResponseStatusCodeSame(200);
        $openedCard = $opened->filter('.turn__card')->eq(0);
        self::assertSame($customerCardId, $openedCard->attr('id'));
        self::assertCount(6, $openedCard->filter('.turn__row'));
        self::assertSame('Show fewer', $openedCard->filter('.turn__more')->text());
        self::assertSame(self::TURN.'?project=SHOP#'.$customerCardId, $openedCard->filter('.turn__more')->attr('href'));
    }

    /**
     * Vendor 01 to 18 wait 40 to 23 days; Omar, Vendor 19 to 21 and "vendor 00" all wait 10 days, so the first page ends
     * inside that tie: a person before the labels, then the labels byte by byte, where the lower-case "vendor 00" comes
     * after "Vendor 21". Raj waits the shortest. The groups go in newest first, against the page's order.
     */
    #[Test]
    public function aPageShowsTheViewersCardAndTwentyOthersAndNextPageGoesOnWithNoRepeatAndNoGap(): void
    {
        $this->addWaitingTicket('Raj', 'decide', 2, 'Coupon field takes any text');
        $this->addTicketWaitingOn(null, 'input', 10, 'Ticket of vendor 00', turnLabel: 'vendor 00');
        for ($vendor = 21; $vendor >= 1; --$vendor) {
            $label = \sprintf('Vendor %02d', $vendor);
            $this->addTicketWaitingOn(null, 'input', $vendor <= 18 ? 41 - $vendor : 10, 'Ticket of '.$label, turnLabel: $label);
        }
        $this->addWaitingTicket('Omar', 'build', 10, 'Search shows no results');
        $this->addWaitingTicket('Lena', 'review', 1, 'Cart total shows the wrong sum');
        $this->logIn($this->client, $this->people['Lena']);
        // The first request skips the user refresh every later one pays, and fills the team people's cache.
        $this->queryCountOf(self::SHELL);
        $shellQueryCount = $this->queryCountOf(self::SHELL);
        $this->queryCountOf(self::TURN);

        $firstPageQueryCount = $this->queryCountOf(self::TURN) - $shellQueryCount;

        $firstPage = $this->client->getCrawler();
        $firstPageNames = ['You'];
        for ($vendor = 1; $vendor <= 18; ++$vendor) {
            $firstPageNames[] = \sprintf('Vendor %02d', $vendor);
        }
        $firstPageNames = [...$firstPageNames, 'Omar', 'Vendor 19'];
        self::assertSame($firstPageNames, $firstPage->filter('.turn__name')->each(static fn (Crawler $name): string => $name->text()));
        self::assertSame('25 tickets · grouped by who acts next', $firstPage->filter('.tickets__total')->text());
        self::assertSame('Next page', $firstPage->filter('.turn__next-page')->text());
        self::assertCount(0, $firstPage->filter('.turn__first-page'));
        // Vendor 19's oldest wait, 27 Oct 2026 11:00 UTC, in Unix seconds.
        $nextPageUrl = (string) $firstPage->filter('.turn__next-page')->attr('href');
        self::assertSame(self::TURN.'?after=1793098800.label.Vendor%2019', $nextPageUrl);
        self::assertSame(self::PAGE_QUERY_COUNT, $firstPageQueryCount);

        $secondPageQueryCount = $this->queryCountOf($nextPageUrl) - $shellQueryCount;

        $secondPage = $this->client->getCrawler();
        self::assertSame(
            ['Vendor 20', 'Vendor 21', 'vendor 00', 'Raj'],
            $secondPage->filter('.turn__name')->each(static fn (Crawler $name): string => $name->text()),
        );
        self::assertSame('25 tickets · grouped by who acts next', $secondPage->filter('.tickets__total')->text());
        self::assertCount(0, $secondPage->filter('.turn__next-page'));
        self::assertSame('Back to the start', $secondPage->filter('.turn__first-page')->text());
        self::assertSame(self::TURN, $secondPage->filter('.turn__first-page')->attr('href'));
        self::assertSame(self::PAGE_QUERY_COUNT, $secondPageQueryCount);
    }

    /**
     * Vendor 01 to 19 wait 40 to 22 days; Omar, Raj and the label Vendor 20 all wait 10 days, so the first page ends on
     * the person of the lower user id, and the next one goes on with the other person, then the label, then Mia. Omar
     * and Raj hold 6 tickets each, so the second page's person has a "Show 1 more" that must keep the page.
     */
    #[Test]
    public function aPageThatEndsOnAPersonTiedWithAPersonAndALabelGoesOnWithTheOtherPersonThenTheLabel(): void
    {
        $this->addWaitingTicket('Mia', 'test', 2, 'Coupon field takes any text');
        $this->addTicketWaitingOn(null, 'input', 10, 'Ticket of Vendor 20', turnLabel: 'Vendor 20');
        foreach (['Raj', 'Omar'] as $person) {
            foreach ([5, 6, 7, 8, 9, 10] as $waitDays) {
                $this->addWaitingTicket($person, 'build', $waitDays, $person.' waits '.$waitDays);
            }
        }
        for ($vendor = 19; $vendor >= 1; --$vendor) {
            $label = \sprintf('Vendor %02d', $vendor);
            $this->addTicketWaitingOn(null, 'input', 41 - $vendor, 'Ticket of '.$label, turnLabel: $label);
        }
        $this->addWaitingTicket('Lena', 'review', 1, 'Cart total shows the wrong sum');
        $omarId = $this->people['Omar']->id()->toString();
        $rajId = $this->people['Raj']->id()->toString();
        [$cutPerson, $cutId, $nextPerson, $nextId] = strcmp($omarId, $rajId) < 0
            ? ['Omar', $omarId, 'Raj', $rajId]
            : ['Raj', $rajId, 'Omar', $omarId];
        $this->logIn($this->client, $this->people['Lena']);

        $firstPage = $this->client->request('GET', self::TURN.'?project=SHOP');

        self::assertResponseStatusCodeSame(200);
        $firstPageNames = ['You'];
        for ($vendor = 1; $vendor <= 19; ++$vendor) {
            $firstPageNames[] = \sprintf('Vendor %02d', $vendor);
        }
        $firstPageNames[] = $cutPerson;
        self::assertSame($firstPageNames, $firstPage->filter('.turn__name')->each(static fn (Crawler $name): string => $name->text()));
        // The cut person's oldest wait, 27 Oct 2026 11:00 UTC, in Unix seconds.
        $after = 'after=1793098800.person.'.$cutId;
        $nextPageUrl = (string) $firstPage->filter('.turn__next-page')->attr('href');
        self::assertSame(self::TURN.'?project=SHOP&'.$after, $nextPageUrl);

        $secondPage = $this->client->request('GET', $nextPageUrl);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([$nextPerson, 'Vendor 20', 'Mia'], $secondPage->filter('.turn__name')->each(static fn (Crawler $name): string => $name->text()));
        self::assertSame('34 tickets · grouped by who acts next', $secondPage->filter('.tickets__total')->text());
        self::assertCount(0, $secondPage->filter('.turn__next-page'));
        self::assertSame(self::TURN.'?project=SHOP', $secondPage->filter('.turn__first-page')->attr('href'));
        $nextCardId = 'turn-'.$nextId;
        $nextCard = $secondPage->filter('.turn__card')->eq(0);
        self::assertSame($nextCardId, $nextCard->attr('id'));
        self::assertSame('Show 1 more', $nextCard->filter('.turn__more')->text());
        $openUrl = (string) $nextCard->filter('.turn__more')->attr('href');
        self::assertSame(self::TURN.'?project=SHOP&'.$after.'&open='.$nextId.'#'.$nextCardId, $openUrl);

        $opened = $this->client->request('GET', $openUrl);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([$nextPerson, 'Vendor 20', 'Mia'], $opened->filter('.turn__name')->each(static fn (Crawler $name): string => $name->text()));
        $openedCard = $opened->filter('.turn__card')->eq(0);
        self::assertCount(6, $openedCard->filter('.turn__row'));
        self::assertSame('Show fewer', $openedCard->filter('.turn__more')->text());
        self::assertSame(self::TURN.'?project=SHOP&'.$after.'#'.$nextCardId, $openedCard->filter('.turn__more')->attr('href'));
    }

    #[Test]
    public function backToTheStartKeepsTheProjectAndTheSearch(): void
    {
        $this->addWaitingTicket('Omar', 'build', 3, 'Search shows no results');
        $this->logIn($this->client, $this->people['Lena']);

        $page = $this->client->request('GET', self::TURN.'?project=SHOP&q=cart&after=1793098800.label.Vendor%2019');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(self::TURN.'?project=SHOP&q=cart', $page->filter('.turn__first-page')->attr('href'));
    }

    /**
     * Omar waits 30 days on 6 tickets, so his card shows "Show 1 more"; Vendor 01 to 19 wait 28 to 10 days and fill the
     * page, and Vendor 20 waits 9 days, on the next one. Every title holds the search word.
     */
    #[Test]
    public function nextPageShowMoreAndShowFewerKeepTheProjectAndTheSearch(): void
    {
        $this->addWaitingTicket('Lena', 'review', 1, 'Cart total shows the wrong sum');
        foreach ([25, 26, 27, 28, 29, 30] as $waitDays) {
            $this->addWaitingTicket('Omar', 'build', $waitDays, 'Cart of Omar waits '.$waitDays);
        }
        for ($vendor = 20; $vendor >= 1; --$vendor) {
            $label = \sprintf('Vendor %02d', $vendor);
            $this->addTicketWaitingOn(null, 'input', 29 - $vendor, 'Cart of '.$label, turnLabel: $label);
        }
        $omarId = $this->people['Omar']->id()->toString();
        $omarCardId = 'turn-'.$omarId;
        $this->logIn($this->client, $this->people['Lena']);

        $page = $this->client->request('GET', self::TURN.'?project=SHOP&q=cart');

        self::assertResponseStatusCodeSame(200);
        // Vendor 19's oldest wait, 27 Oct 2026 11:00 UTC, in Unix seconds.
        self::assertSame(self::TURN.'?project=SHOP&q=cart&after=1793098800.label.Vendor%2019', $page->filter('.turn__next-page')->attr('href'));
        $omarCard = $page->filter('.turn__card')->eq(1);
        self::assertSame($omarCardId, $omarCard->attr('id'));
        self::assertSame('Show 1 more', $omarCard->filter('.turn__more')->text());
        $openUrl = (string) $omarCard->filter('.turn__more')->attr('href');
        self::assertSame(self::TURN.'?project=SHOP&q=cart&open='.$omarId.'#'.$omarCardId, $openUrl);

        $opened = $this->client->request('GET', $openUrl);

        self::assertResponseStatusCodeSame(200);
        $openedCard = $opened->filter('.turn__card')->eq(1);
        self::assertSame('Show fewer', $openedCard->filter('.turn__more')->text());
        self::assertSame(self::TURN.'?project=SHOP&q=cart#'.$omarCardId, $openedCard->filter('.turn__more')->attr('href'));
    }

    /**
     * The tickets of the next page may be done before it is read, so a cursor can lie past the last card.
     */
    #[Test]
    public function aCursorPastTheLastCardShowsNoTicketsAndTheWayBackToTheStart(): void
    {
        $this->addWaitingTicket('Omar', 'build', 3, 'Search shows no results');
        $this->logIn($this->client, $this->people['Lena']);

        // 1 Jan 2030, after every wait.
        $page = $this->client->request('GET', self::TURN.'?after=1893456000.label.Customer');

        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $page->filter('.turn__card'));
        self::assertSame('No tickets here yet.', $page->filter('.empty-state')->text());
        self::assertSame('1 ticket · grouped by who acts next', $page->filter('.tickets__total')->text());
        self::assertSame('Back to the start', $page->filter('.turn__first-page')->text());
        self::assertSame(self::TURN, $page->filter('.turn__first-page')->attr('href'));
        self::assertCount(0, $page->filter('.turn__next-page'));
    }

    #[Test]
    public function aCursorAfterALabelOfAHundredCharactersShowsThePageAfterIt(): void
    {
        $this->addWaitingTicket('Omar', 'build', 3, 'Search shows no results');
        $this->logIn($this->client, $this->people['Lena']);

        $page = $this->client->request('GET', self::TURN.'?after=1793098800.label.'.str_repeat('a', 100));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['1 ticket waits on Omar · for 3 days'], $page->filter('.turn__sentence')->each(static fn (Crawler $sentence): string => $sentence->text()));
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
        self::assertSame([self::PAGE_QUERY_COUNT, self::PAGE_QUERY_COUNT], [$twoCardsQueryCount, $sixCardsQueryCount]);
        // The search runs inside the cards' query; the open steps it takes are cached after the first read.
        $this->queryCountOf(self::TURN.'?q=coupon');
        $searchQueryCount = $this->queryCountOf(self::TURN.'?q=coupon') - $shellQueryCount;
        self::assertSame(['Coupon field takes any text'], $this->client->getCrawler()->filter('.turn__title')->each(static fn (Crawler $title): string => $title->text()));
        self::assertSame(self::PAGE_QUERY_COUNT, $searchQueryCount);
    }

    /**
     * SHOP and CART are both Shop's. Omar waits on a SHOP ticket with "cart" in its title, an older SHOP one without it
     * and the oldest, a CART one with it; Customer waits on one SHOP ticket with it.
     */
    #[Test]
    public function theProjectAndTheSearchNarrowTheCardsTheirCountsAndTheirOldestWait(): void
    {
        $this->addWaitingTicket('Omar', 'build', 4, 'Cart total shows the wrong sum');
        $this->addWaitingTicket('Omar', 'build', 9, 'Checkout button is hidden');
        $this->addTicketWaitingOn($this->people['Omar']->id()->toString(), 'build', 20, 'Cart badge shows no number', projectKey: 'CART');
        $this->addWaitingTicket(null, 'input', 2, 'Cart coupon is lost');
        $this->logIn($this->client, $this->people['Lena']);

        $both = $this->client->request('GET', self::TURN.'?project=SHOP&q=cart');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['1 ticket waits on Omar · for 4 days', '1 ticket waits on Customer · for 2 days · outside the team'], self::textsOf($both, '.turn__sentence'));
        self::assertSame(['Cart total shows the wrong sum', 'Cart coupon is lost'], self::textsOf($both, '.turn__title'));
        self::assertSame('2 tickets · grouped by who acts next', $both->filter('.tickets__total')->text());
        self::assertSame('2 tickets · grouped by who acts next', $both->filter('[data-total-line]')->attr('data-total-line'));

        $searchOnly = $this->client->request('GET', self::TURN.'?q=cart');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['2 tickets wait on Omar · the oldest for 20 days', '1 ticket waits on Customer · for 2 days · outside the team'], self::textsOf($searchOnly, '.turn__sentence'));
        self::assertSame(['Cart badge shows no number', 'Cart total shows the wrong sum', 'Cart coupon is lost'], self::textsOf($searchOnly, '.turn__title'));
        self::assertSame('3 tickets · grouped by who acts next', $searchOnly->filter('.tickets__total')->text());

        $projectOnly = $this->client->request('GET', self::TURN.'?project=SHOP');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['2 tickets wait on Omar · the oldest for 9 days', '1 ticket waits on Customer · for 2 days · outside the team'], self::textsOf($projectOnly, '.turn__sentence'));
        self::assertSame('3 tickets · grouped by who acts next', $projectOnly->filter('.tickets__total')->text());
    }

    #[Test]
    public function aSearchThatFindsNothingSaysToClearTheSearch(): void
    {
        $this->addWaitingTicket('Omar', 'build', 4, 'Cart total shows the wrong sum');
        $this->logIn($this->client, $this->people['Lena']);

        $page = $this->client->request('GET', self::TURN.'?q=invoice');

        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $page->filter('.turn__card'));
        self::assertSame('No tickets match. Clear the search.', $page->filter('.empty-state')->text());
        self::assertSame('0 tickets · grouped by who acts next', $page->filter('.tickets__total')->text());
    }

    /**
     * The search runs under the viewer's own visibility and organization: Raj, outside Shop, never finds its team-only
     * ticket, and a person of another organization finds none of Acme Foods' tickets.
     */
    #[Test]
    public function aSearchFindsNoTicketTheViewerMayNotSee(): void
    {
        $this->addWaitingTicket('Omar', 'build', 3, 'Price shows in the wrong currency');
        $this->addWaitingTicket('Omar', 'build', 40, self::HIDDEN_TITLE, 'team');
        $this->logIn($this->client, $this->people['Lena']);
        $lenasPage = $this->client->request('GET', self::TURN.'?q=price');
        self::assertResponseStatusCodeSame(200);
        self::assertSame([self::HIDDEN_TITLE, 'Price shows in the wrong currency'], self::textsOf($lenasPage, '.turn__title'));
        $this->logIn($this->client, $this->people['Raj']);

        $rajsPage = $this->client->request('GET', self::TURN.'?q=price');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['1 ticket waits on Omar · for 3 days'], self::textsOf($rajsPage, '.turn__sentence'));
        self::assertStringNotContainsString(self::HIDDEN_TITLE, (string) $this->client->getResponse()->getContent());
        $betaBakery = $this->createOrganization('Beta Bakery');
        $ben = $this->createUserWithoutOrganization('Ben');
        $this->addMembership($ben, $betaBakery);
        $this->logIn($this->client, $ben);

        $bensPage = $this->client->request('GET', self::TURN.'?q=price');

        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $bensPage->filter('.turn__card'));
        self::assertSame('0 tickets · grouped by who acts next', $bensPage->filter('.tickets__total')->text());
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString(self::HIDDEN_TITLE, $body);
        self::assertStringNotContainsString('Price shows in the wrong currency', $body);
    }

    /**
     * The search keeps its best 1,000 matches, as the tickets list does, so 1,001 matches show as "1,000+".
     */
    #[Test]
    public function aSearchPastTheCapShowsTheCountAsALowerBound(): void
    {
        $this->addManyTicketsWaitingOnOmar(1001, 'Cart total shows the wrong sum');
        $this->addWaitingTicket('Raj', 'decide', 3, 'Checkout button is hidden');
        $this->logIn($this->client, $this->people['Lena']);

        $capped = $this->client->request('GET', self::TURN.'?q=cart');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('1,000+ tickets · grouped by who acts next', $capped->filter('.tickets__total')->text());
        self::assertSame(['Omar'], self::textsOf($capped, '.turn__name'));

        $plain = $this->client->request('GET', self::TURN);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('1,002 tickets · grouped by who acts next', $plain->filter('.tickets__total')->text());
    }

    /**
     * The toolbar is the list's without the people: the search and "Show done" open this view, keeping the project.
     */
    #[Test]
    public function theToolbarHasTheSearchAndShowDoneButNoPeopleAndOpensThisView(): void
    {
        $this->logIn($this->client, $this->people['Lena']);

        $page = $this->client->request('GET', self::TURN.'?project=SHOP&q=cart');

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $page->filter('.ticket-filters'));
        self::assertCount(0, $page->filter('.ticket-filters__people, [data-person-chip], [data-person-picker-trigger]'));
        $form = $page->filter('.ticket-filters form[method="get"]');
        self::assertSame(self::TURN, $form->attr('action'));
        self::assertSame('cart', $form->filter('input[name="q"]')->attr('value'));
        self::assertSame(['project=SHOP'], $form->filter('input[type="hidden"]')->each(static fn (Crawler $input): string => $input->attr('name').'='.$input->attr('value')));
        self::assertSame(self::TURN.'?project=SHOP&done=1&q=cart', $page->filter('[data-done-chip]')->attr('href'));
        self::assertNull($page->filter('[data-done-chip]')->attr('aria-current'));
        self::assertSame('Shop', $page->filter('.project-rail__team')->text());
        self::assertSame(
            [self::TURN.'?team='.$this->shop->toString().'&q=cart', self::TURN.'?project=SHOP&q=cart'],
            $page->filter('.project-rail__item')->each(static fn (Crawler $item): string => (string) $item->attr('href')),
        );
        self::assertSame('true', $page->filter('.project-rail__item[data-rail-project="SHOP"]')->attr('aria-current'));
        self::assertSame('Turbo-Frame', $this->client->getResponse()->headers->get('Vary'));
    }

    /**
     * "Show done" changes only the rail's counts: done tickets wait on no one, so the cards stay, and their links keep it.
     */
    #[Test]
    public function showDoneCountsDoneTicketsOnTheRailAndKeepsTheCards(): void
    {
        $this->addShopCounts(6, 3);
        foreach ([1, 2, 3, 4, 5, 6] as $waitDays) {
            $this->addWaitingTicket('Omar', 'build', $waitDays, 'Omar waits '.$waitDays);
        }
        $this->logIn($this->client, $this->people['Lena']);
        $openOnly = $this->client->request('GET', self::TURN);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['All projects 6 open tickets', 'SHOP Shop 6 open tickets'], self::textsOf($openOnly, '.project-rail__item'));

        $withDone = $this->client->request('GET', self::TURN.'?done=1');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['All projects 9 tickets', 'SHOP Shop 9 tickets'], self::textsOf($withDone, '.project-rail__item'));
        self::assertSame('true', $withDone->filter('[data-done-chip]')->attr('aria-current'));
        self::assertSame(self::TURN, $withDone->filter('[data-done-chip]')->attr('href'));
        self::assertSame(self::textsOf($openOnly, '.turn__sentence'), self::textsOf($withDone, '.turn__sentence'));
        self::assertSame(['6 tickets wait on Omar · the oldest for 6 days'], self::textsOf($withDone, '.turn__sentence'));
        self::assertSame('6 tickets · grouped by who acts next', $withDone->filter('.tickets__total')->text());
        $omarsCardId = 'turn-'.$this->people['Omar']->id()->toString();
        self::assertSame(self::TURN.'?done=1&open='.$this->people['Omar']->id()->toString().'#'.$omarsCardId, $withDone->filter('.turn__more')->attr('href'));
        self::assertSame(self::TURN.'?project=SHOP&done=1', $withDone->filter('.project-rail__item[data-rail-project="SHOP"]')->attr('href'));
    }

    #[Test]
    public function showNMoreIsATextButton(): void
    {
        foreach ([1, 2, 3, 4, 5, 6] as $waitDays) {
            $this->addWaitingTicket('Omar', 'build', $waitDays, 'Omar waits '.$waitDays);
        }
        $this->logIn($this->client, $this->people['Lena']);

        $page = $this->client->request('GET', self::TURN);

        self::assertResponseStatusCodeSame(200);
        $more = $page->filter('a.turn__more');
        self::assertSame('Show 1 more', $more->text());
        self::assertSame(['btn', 'btn-sm', 'btn-text', 'turn__more'], self::sortedClassesOf((string) $more->attr('class')));
    }

    /**
     * After a filter change the page loads the new URL into the cards' frame and the rail's: each gets its own frame
     * alone, and runs only its own queries.
     */
    #[Test]
    public function eachFrameRequestGetsOnlyItsFrameAndRunsOnlyItsQueries(): void
    {
        $this->addWaitingTicket('Omar', 'build', 4, 'Cart total shows the wrong sum');
        $this->addWaitingTicket('Omar', 'build', 9, 'Checkout button is hidden');
        $this->logIn($this->client, $this->people['Lena']);
        $uri = self::TURN.'?q=cart';
        $this->queriesOf($uri);
        $pageQueries = $this->queriesOf($uri);

        $cardsQueries = $this->queriesOf($uri, ['HTTP_TURBO_FRAME' => self::CARDS_FRAME]);

        $cardsFrame = $this->client->getCrawler();
        self::assertSame('Turbo-Frame', $this->client->getResponse()->headers->get('Vary'));
        self::assertCount(1, $cardsFrame->filter('turbo-frame#ticket-list-frame[target="_top"]'));
        self::assertCount(0, $cardsFrame->filter('.ticket-filters, nav.project-rail, header, title'));
        self::assertSame(['Cart total shows the wrong sum'], self::textsOf($cardsFrame, '.turn__title'));
        self::assertSame('1 ticket · grouped by who acts next', $cardsFrame->filter('[data-total-line]')->attr('data-total-line'));

        $railQueries = $this->queriesOf($uri, ['HTTP_TURBO_FRAME' => self::RAIL_FRAME]);

        $railFrame = $this->client->getCrawler();
        self::assertSame('Turbo-Frame', $this->client->getResponse()->headers->get('Vary'));
        self::assertCount(1, $railFrame->filter('turbo-frame#project-rail-frame[target="_top"] nav.project-rail'));
        self::assertCount(0, $railFrame->filter('turbo-frame#ticket-list-frame, .ticket-filters, header, title, .turn__card'));
        $railRead = '/FROM org_team WHERE organization_id = \? AND id = ANY|FROM tkt_project WHERE organization_id = \? AND team_id = ANY|FROM ins_project_counts/';
        $cardsRead = '/FROM ins_ticket_turn|FROM idn_user WHERE id = ANY/';
        self::assertSame([2, 2, 0], [self::countMatching($cardsRead, $pageQueries), self::countMatching($cardsRead, $cardsQueries), self::countMatching($cardsRead, $railQueries)]);
        // The menu's team names match the rail's first read, so the page reads it twice.
        self::assertSame([4, 0, 3], [self::countMatching($railRead, $pageQueries), self::countMatching($railRead, $cardsQueries), self::countMatching($railRead, $railQueries)]);
    }

    /**
     * The toolbar's live requests send this view's URL, without a person.
     */
    #[Test]
    public function theToolbarsLiveRequestsSendThisViewsUrl(): void
    {
        $this->logIn($this->client, $this->people['Lena']);
        $toolbar = $this->createLiveComponent('TicketFilters', ['team' => $this->shop->toString(), 'project' => 'SHOP', 'person' => $this->people['Omar']->id()->toString(), 'isWhoseTurnView' => true], $this->client);

        $toolbar->call('toggleDone');

        self::assertSame(200, $toolbar->response()->getStatusCode());
        $this->assertComponentDispatchBrowserEvent($toolbar, TicketFilters::LIST_CHANGED_EVENT)
            ->withPayload(['url' => self::TURN.'?project=SHOP&done=1', 'addsHistoryEntry' => true]);

        $toolbar->set('q', '  gift note ');

        $this->assertComponentDispatchBrowserEvent($toolbar, TicketFilters::LIST_CHANGED_EVENT)
            ->withPayload(['url' => self::TURN.'?project=SHOP&done=1&q=gift%20note', 'addsHistoryEntry' => false]);
        self::assertCount(0, $toolbar->render()->crawler()->filter('.ticket-filters__people'));
    }

    private function queryCountOf(string $uri): int
    {
        return \count($this->queriesOf($uri));
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
     * @param list<string> $queries
     */
    private static function countMatching(string $pattern, array $queries): int
    {
        return \count(array_filter($queries, static fn (string $sql): bool => 1 === preg_match($pattern, $sql)));
    }

    /**
     * @return list<string>
     */
    private static function sortedClassesOf(string $classAttribute): array
    {
        $classes = preg_split('/\s+/', trim($classAttribute)) ?: [];
        sort($classes);

        return $classes;
    }

    /**
     * @return list<string>
     */
    private static function textsOf(Crawler $page, string $selector): array
    {
        return $page->filter($selector)->each(static fn (Crawler $node): string => $node->text());
    }

    private function cartProject(): string
    {
        return $this->cartProject ??= $this->addProject($this->shop, 'CART', 'Checkout');
    }

    /**
     * The rail's read model: SHOP's open and done tickets for everyone.
     */
    private function addShopCounts(int $openCount, int $doneCount): void
    {
        $values = [$this->acmeFoods->toString(), $this->shopProject, $this->shop->toString(), $openCount, $doneCount];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO ins_project_counts (organization_id, project_id, team_id, visibility, open_count, done_count) VALUES (?, ?, ?, 'all', ?, ?)",
            $values,
        ));
    }

    /**
     * $count SHOP tickets titled $title in one statement, each waiting on Omar a minute longer than the one before.
     */
    private function addManyTicketsWaitingOnOmar(int $count, string $title): void
    {
        $firstNumber = $this->nextNumber;
        $this->nextNumber += $count;
        $organizationId = $this->acmeFoods->toString();
        $ticketValues = [$organizationId, $this->shopProject, $this->shop->toString(), $title, $this->doingStep, $this->people['Lena']->id()->toString(), $firstNumber, $firstNumber + $count - 1];
        $turnValues = [$this->people['Omar']->id()->toString(), self::NOW, $organizationId, $firstNumber, $firstNumber + $count - 1];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($ticketValues, $turnValues): int {
            $migrator->executeStatement(
                "INSERT INTO tkt_ticket (organization_id, id, project_id, team_id, number, key, title, description, type, priority, priority_rank,
                     step_id, reporter_id, visibility, components, check_reasons, created_at, updated_at)
                 SELECT CAST(? AS uuid), CAST('0197a3c0-0000-7000-8003-' || lpad(to_hex(number), 12, '0') AS uuid), CAST(? AS uuid), CAST(? AS uuid), number, 'SHOP-' || number, CAST(? AS text), '',
                     'bug', 'normal', 3, CAST(? AS uuid), CAST(? AS uuid), 'all', '{}', '{}',
                     TIMESTAMPTZ '2026-08-01 09:00:00+00', TIMESTAMPTZ '2026-08-01 09:00:00+00'
                 FROM generate_series(CAST(? AS int), CAST(? AS int)) AS number",
                $ticketValues,
            );
            $migrator->executeStatement(
                "INSERT INTO ins_ticket_turn (organization_id, ticket_id, team_id, project_id, grp, turn_user_id, turn_label, kind, base_score, waiting_since, visibility, reporter_id)
                 SELECT ticket.organization_id, ticket.id, ticket.team_id, ticket.project_id, 'doing', CAST(? AS uuid), NULL, 'build', 100,
                     CAST(? AS timestamptz) - ticket.number * INTERVAL '1 minute', 'all', ticket.reporter_id
                 FROM tkt_ticket AS ticket WHERE ticket.organization_id = CAST(? AS uuid) AND ticket.number BETWEEN ? AND ?",
                $turnValues,
            );

            return 0;
        });
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
        $this->addTicketWaitingOn(null === $turnPerson ? null : $this->people[$turnPerson]->id()->toString(), $kind, $waitDays, $title, $visibility);
    }

    /**
     * As addWaitingTicket, waiting on the user id $turnUserId, which may be of nobody, or on $turnLabel for null, in
     * $projectKey, SHOP or CART.
     */
    private function addTicketWaitingOn(?string $turnUserId, string $kind, int $waitDays, string $title, string $visibility = 'all', string $turnLabel = 'Customer', string $projectKey = 'SHOP'): void
    {
        $number = $this->nextNumber++;
        $ticketId = Uuid::v7()->toRfc4122();
        $organizationId = $this->acmeFoods->toString();
        $shopTeam = $this->shop->toString();
        $lena = $this->people['Lena']->id()->toString();
        $waitingSince = new \DateTimeImmutable(self::NOW)->modify(\sprintf('-%d days -1 hour', $waitDays))->format('Y-m-d H:i:sP');
        $projectId = 'CART' === $projectKey ? $this->cartProject() : $this->shopProject;
        $stepId = $this->doingStep;
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId, $ticketId, $projectId, $projectKey, $shopTeam, $number, $title, $stepId, $lena, $visibility, $turnUserId, $turnLabel, $kind, $waitingSince): int {
            $migrator->executeStatement(
                "INSERT INTO tkt_ticket (organization_id, id, project_id, team_id, number, key, title, description, type, priority,
                     priority_rank, step_id, reporter_id, visibility, assignee_id, components, check_reasons, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, '', 'bug', 'major', 2, ?, ?, ?, NULL, '{}', '{}', TIMESTAMPTZ '2026-08-01 09:00:00+00', TIMESTAMPTZ '2026-08-01 09:00:00+00')",
                [$organizationId, $ticketId, $projectId, $shopTeam, $number, $projectKey.'-'.$number, $title, $stepId, $lena, $visibility],
            );
            $migrator->executeStatement(
                "INSERT INTO ins_ticket_turn (organization_id, ticket_id, team_id, project_id, grp, turn_user_id, turn_label, kind, base_score, waiting_since, visibility, reporter_id)
                 VALUES (?, ?, ?, ?, 'doing', ?, ?, ?, 100, CAST(? AS timestamptz), ?, ?)",
                [$organizationId, $ticketId, $shopTeam, $projectId, $turnUserId, null === $turnUserId ? $turnLabel : null, $kind, $waitingSince, $visibility, $lena],
            );

            return 0;
        });
    }
}
