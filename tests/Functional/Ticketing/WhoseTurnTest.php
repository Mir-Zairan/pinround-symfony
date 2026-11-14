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
 * The whose-turn view (FR-TKT-03, FR-TKT-18) on Acme Foods' project SHOP of team Shop, through the real firewall,
 * voters and database. The clock is fixed on 6 Nov 2026, before today: a clock ahead of the login would end the
 * session as idle. Lena leads Shop and Omar is a member; Raj, a manager of no team, is outside it.
 */
final class WhoseTurnTest extends WebTestCase
{
    use CreatesAcmeFoodsTeams;
    use CreatesTeams;
    use Factories;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }

    private const string NOW = '2026-11-06 12:00:00 UTC';
    private const string TURN = '/tickets/turn';
    private const string SHELL = '/_test/menu/shell';
    /** A refused viewer must never get a word of it. */
    private const string HIDDEN_TITLE = 'Price rules for staff';

    private KernelBrowser $client;

    /** @var array<string, User> */
    private array $people;

    private string $shopProject;

    private string $doingStep;

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
        self::assertSame(3, $firstPageQueryCount);

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
        self::assertSame(3, $secondPageQueryCount);
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
     * page, and Vendor 20 waits 9 days, on the next one.
     */
    #[Test]
    public function nextPageShowMoreAndShowFewerKeepTheProjectAndTheSearch(): void
    {
        $this->addWaitingTicket('Lena', 'review', 1, 'Cart total shows the wrong sum');
        foreach ([25, 26, 27, 28, 29, 30] as $waitDays) {
            $this->addWaitingTicket('Omar', 'build', $waitDays, 'Omar waits '.$waitDays);
        }
        for ($vendor = 20; $vendor >= 1; --$vendor) {
            $label = \sprintf('Vendor %02d', $vendor);
            $this->addTicketWaitingOn(null, 'input', 29 - $vendor, 'Ticket of '.$label, turnLabel: $label);
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
        self::assertSame($twoCardsQueryCount, $sixCardsQueryCount);
        // The triager (RoleTicketVisibilities), the cards, and every card's names; the team's people are cached.
        self::assertSame(3, $sixCardsQueryCount);
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
     * As addWaitingTicket, waiting on the user id $turnUserId, which may be of nobody, or on $turnLabel for null.
     */
    private function addTicketWaitingOn(?string $turnUserId, string $kind, int $waitDays, string $title, string $visibility = 'all', string $turnLabel = 'Customer'): void
    {
        $number = $this->nextNumber++;
        $ticketId = Uuid::v7()->toRfc4122();
        $organizationId = $this->acmeFoods->toString();
        $shopTeam = $this->shop->toString();
        $lena = $this->people['Lena']->id()->toString();
        $waitingSince = new \DateTimeImmutable(self::NOW)->modify(\sprintf('-%d days -1 hour', $waitDays))->format('Y-m-d H:i:sP');
        $projectId = $this->shopProject;
        $stepId = $this->doingStep;
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId, $ticketId, $projectId, $shopTeam, $number, $title, $stepId, $lena, $visibility, $turnUserId, $turnLabel, $kind, $waitingSince): int {
            $migrator->executeStatement(
                "INSERT INTO tkt_ticket (organization_id, id, project_id, team_id, number, key, title, description, type, priority,
                     priority_rank, step_id, reporter_id, visibility, assignee_id, components, check_reasons, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, '', 'bug', 'major', 2, ?, ?, ?, NULL, '{}', '{}', TIMESTAMPTZ '2026-08-01 09:00:00+00', TIMESTAMPTZ '2026-08-01 09:00:00+00')",
                [$organizationId, $ticketId, $projectId, $shopTeam, $number, 'SHOP-'.$number, $title, $stepId, $lena, $visibility],
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
