<?php

declare(strict_types=1);

namespace App\Tests\Functional\Notification;

use App\Identity\Domain\User;
use App\Notification\Infrastructure\RateLimit\ValkeyPingWindow;
use App\Notification\UI\Component\PingSheet;
use App\Notification\UI\Controller\PingController;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\Transactions;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Infrastructure\Doctrine\DoctrineTransactions;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use App\Tests\Functional\Web\IssuesCsrfTokens;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * The ticket page's ping, POST /t/{key}/ping/{userId}, on Acme Foods' SHOP-1 through the real firewall, voters,
 * session, ping window on Valkey and database. The clock is fixed on 6 Nov 2026, before today: a clock ahead of the
 * login would end the session as idle. Every refusal also proves that no ping was stored or published.
 */
final class PingTest extends WebTestCase
{
    use CreatesAcmeFoodsTeams;
    use CreatesTeams;
    use Factories;
    use IssuesCsrfTokens;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }

    private const string NOW = '2026-11-06 12:00:00 UTC';
    private const string KEY = 'SHOP-1';
    private const string TICKET_PAGE = '/t/SHOP-1';
    private const string MESSAGE = 'Hi Lena, could you look at SHOP-1? Now: Code review.';
    /** The sheet's draft for Lena on SHOP-1, which quotes the ticket's Now line with its own end mark. */
    private const string DRAFT = 'Hi Lena, could you look at SHOP-1? Now: Lena plans it.';
    private const string NOBODY_ID = '0197a3c0-0000-7000-8000-00000000dead';
    /** What Turbo sends with a form it posts. */
    private const array TURBO_FORM_HEADERS = ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml'];
    private const string TOAST_REGION_ID = 'toast-region';
    /** What Turbo sends when a My reports card loads SHOP-1's sheet into its frame. */
    private const array SHEET_FRAME_HEADERS = ['HTTP_TURBO_FRAME' => 'ping-sheet-SHOP-1'];

    private KernelBrowser $client;

    private MockClock $clock;

    /** @var array<string, User> */
    private array $people;

    private string $shopProject;

    private string $backlogStep;

    private string $shopTicket;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTPS' => 'on']);
        $this->client->disableReboot();
        $this->clock = new MockClock(new \DateTimeImmutable(self::NOW));
        self::getContainer()->set('clock', $this->clock);
    }

    protected function tearDown(): void
    {
        $this->forgetSlots();
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->committedAs(new OrganizationId($organizationId), static function (Connection $migrator) use ($organizationId): int {
                foreach (['ntf_ping', 'tkt_ticket', 'wf_step'] as $table) {
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
    public function omarPingsLenaAndHerPingButtonTurnsIntoThePingedState(): void
    {
        $this->seedShop();
        $this->logIn($this->client, $this->people['Omar']);

        $this->client->request('POST', $this->pathTo('Lena'), $this->pingFields(), [], self::TURBO_FORM_HEADERS);

        $streams = $this->streamsOfTheAnswer(200, 'Lena', true);
        self::assertSame('Pinged · again in 4 h', trim($streams['ping-'.self::KEY.'-'.$this->idOf('Lena')]->filter('#ping-'.self::KEY.'-'.$this->idOf('Lena'))->text()));
        self::assertSame(1, $this->stateValkey()->exists($this->slotKeyOf('Omar', 'Lena')));
        self::assertSame('Pinged Lena. One notification sent.', $this->toastOf($streams));
        self::assertSame([['from_id' => $this->idOf('Omar'), 'to_id' => $this->idOf('Lena'), 'body' => self::MESSAGE, 'created_at' => '2026-11-06 12:00:00+00']], $this->storedPings());
        self::assertSame(1, $this->publishedPingEvents());
    }

    #[Test]
    public function aSecondPingWithinTheLimitIsRefusedWithTheWaitAndStoresNothing(): void
    {
        $this->seedShop();
        $this->logIn($this->client, $this->people['Omar']);
        $this->client->request('POST', $this->pathTo('Lena'), $this->pingFields(), [], self::TURBO_FORM_HEADERS);
        self::assertResponseStatusCodeSame(200);
        $this->clock->modify('+90 minutes');

        $this->client->request('POST', $this->pathTo('Lena'), $this->pingFields(['message' => 'Any news?']), [], self::TURBO_FORM_HEADERS);

        $streams = $this->streamsOfTheAnswer(422, 'Lena', true);
        self::assertSame('Pinged · again in 3 h', trim($streams['ping-'.self::KEY.'-'.$this->idOf('Lena')]->text()));
        self::assertSame('You pinged Lena about this ticket recently. You can ping them again in 3 h.', $this->toastOf($streams));
        self::assertCount(1, $this->storedPings());
        self::assertSame(self::MESSAGE, $this->storedPings()[0]['body']);
        self::assertSame(1, $this->publishedPingEvents());
    }

    /**
     * Who pings whom, the ticket, and Mia's team role.
     *
     * @return iterable<string, array{string, string, array{reporter?: string, visibility?: string}, 'member'|'viewer'|null}>
     */
    public static function peopleWhoMayPing(): iterable
    {
        yield 'Lena, the lead' => ['Lena', 'Omar', [], 'viewer'];
        yield 'Omar, a member' => ['Omar', 'Lena', [], 'viewer'];
        yield 'Sara, an admin with no team role' => ['Sara', 'Omar', ['visibility' => 'team'], 'viewer'];
        yield 'Raj, a manager with no team role' => ['Raj', 'Omar', [], 'viewer'];
        yield 'Mia, with no team role, as the reporter' => ['Mia', 'Lena', ['reporter' => 'Mia'], null];
    }

    /**
     * @param array{reporter?: string, visibility?: string} $ticket
     * @param 'member'|'viewer'|null                        $miaTeamRole
     */
    #[Test]
    #[DataProvider('peopleWhoMayPing')]
    public function someoneWhoMayPingSendsOnePing(string $sender, string $recipient, array $ticket, ?string $miaTeamRole): void
    {
        $this->seedShop($ticket, $miaTeamRole);
        $this->logIn($this->client, $this->people[$sender]);

        $this->client->request('POST', $this->pathTo($recipient), $this->pingFields(), [], self::TURBO_FORM_HEADERS);

        $streams = $this->streamsOfTheAnswer(200, $recipient, true);
        self::assertSame(\sprintf('Pinged %s. One notification sent.', $recipient), $this->toastOf($streams));
        self::assertSame([[$this->idOf($sender), $this->idOf($recipient)]], array_map(static fn (array $ping): array => [$ping['from_id'], $ping['to_id']], $this->storedPings()));
    }

    /**
     * Who pings Lena, the ticket, and Mia's team role.
     *
     * @return iterable<string, array{string, array{reporter?: string, visibility?: string}, 'member'|'viewer'|null}>
     */
    public static function peopleWhoMayNotPing(): iterable
    {
        yield 'Mia, a viewer of the team' => ['Mia', [], 'viewer'];
        yield 'Mia, a viewer of the team, as the reporter' => ['Mia', ['reporter' => 'Mia'], 'viewer'];
        yield 'Mia, with no team role and not the reporter' => ['Mia', [], null];
        yield 'Raj, a manager who may not see a team-only ticket' => ['Raj', ['visibility' => 'team'], 'viewer'];
        yield 'Ben, of another organization' => ['Ben', [], 'viewer'];
    }

    /**
     * @param array{reporter?: string, visibility?: string} $ticket
     * @param 'member'|'viewer'|null                        $miaTeamRole
     */
    #[Test]
    #[DataProvider('peopleWhoMayNotPing')]
    public function someoneWhoMayNotPingGetsNotFoundAndNothingIsStored(string $sender, array $ticket, ?string $miaTeamRole): void
    {
        $this->seedShop($ticket, $miaTeamRole);
        if ('Ben' === $sender) {
            $this->addBenOfBetaBakery();
        }
        $this->logIn($this->client, $this->people[$sender]);

        $this->client->request('POST', $this->pathTo('Lena'), $this->pingFields(), [], self::TURBO_FORM_HEADERS);

        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString('<turbo-stream', (string) $this->client->getResponse()->getContent());
        self::assertSame([], $this->storedPings());
        self::assertSame(0, $this->publishedPingEvents());
    }

    /**
     * Whom Omar pings, the ticket, the fields, and the toast.
     *
     * @return iterable<string, array{string, array{visibility?: string}, array<string, string>, string}>
     */
    public static function pingsRefusedWithAToast(): iterable
    {
        yield 'a ping to yourself' => ['Omar', [], [], 'You cannot ping yourself.'];
        yield 'a ping to someone who may not see the team-only ticket' => ['Raj', ['visibility' => 'team'], [], 'This person cannot see this ticket.'];
        yield 'a made-up form token' => ['Lena', [], [PingController::CSRF_FIELD => 'not-a-token'], 'The page was open too long. Try again.'];
        yield 'an empty message' => ['Lena', [], ['message' => "  \n "], 'Write a message of 1 to 500 characters.'];
        yield 'a message of 501 characters' => ['Lena', [], ['message' => str_repeat('é', 501)], 'Write a message of 1 to 500 characters.'];
        yield 'a message that is no UTF-8' => ['Lena', [], ['message' => "Hi \xC3\x28"], 'Write a message of 1 to 500 characters.'];
        yield 'a message past the byte cap' => ['Lena', [], ['message' => 'Hi'.str_repeat(' ', PingController::MESSAGE_MAX_BYTES)], 'Write a message of 1 to 500 characters.'];
    }

    /**
     * @param array{visibility?: string} $ticket
     * @param array<string, string>      $fields
     */
    #[Test]
    #[DataProvider('pingsRefusedWithAToast')]
    public function aRefusedPingSaysWhyAndStoresNothing(string $recipient, array $ticket, array $fields, string $expectedToast): void
    {
        $this->seedShop($ticket);
        $this->logIn($this->client, $this->people['Omar']);

        $this->client->request('POST', $this->pathTo($recipient), $this->pingFields($fields), [], self::TURBO_FORM_HEADERS);

        $streams = $this->streamsOfTheAnswer(422, $recipient, false);
        self::assertSame($expectedToast, $this->toastOf($streams));
        self::assertSame($expectedToast, trim($streams['ping-'.self::KEY.'-'.$this->idOf($recipient).'-refusal']->text()));
        self::assertSame([], $this->storedPings());
        self::assertSame(0, $this->publishedPingEvents());
    }

    #[Test]
    public function aPingWhileValkeyIsDownIsRefusedAndStoresNothing(): void
    {
        $this->seedShop();
        $unreachable = RedisAdapter::createConnection('redis://127.0.0.1:1', ['lazy' => true, 'timeout' => 1]);
        self::assertInstanceOf(\Redis::class, $unreachable);
        self::getContainer()->set(ValkeyPingWindow::class, new ValkeyPingWindow($unreachable));
        $this->logIn($this->client, $this->people['Omar']);

        $this->client->request('POST', $this->pathTo('Lena'), $this->pingFields(), [], self::TURBO_FORM_HEADERS);

        $streams = $this->streamsOfTheAnswer(422, 'Lena', false);
        self::assertSame('Pings are paused for a moment. Try again soon.', $this->toastOf($streams));
        self::assertSame([], $this->storedPings());
        self::assertSame(0, $this->publishedPingEvents());
    }

    #[Test]
    public function aPingWhoseTransactionFailsAfterTheWriteGivesItsSlotBackAndStoresNothing(): void
    {
        $this->seedShop();
        $this->failCommitsAfterTheWrite();
        $this->logIn($this->client, $this->people['Omar']);

        $this->client->request('POST', $this->pathTo('Lena'), $this->pingFields(), [], self::TURBO_FORM_HEADERS);

        self::assertResponseStatusCodeSame(500);
        self::assertSame(0, $this->stateValkey()->exists($this->slotKeyOf('Omar', 'Lena')));
        self::assertSame([], $this->storedPings());
        self::assertSame(0, $this->publishedPingEvents());
    }

    #[Test]
    public function aFailedCommitWhoseSlotCannotBeGivenBackStillAnswersWithTheCommitsFailure(): void
    {
        $this->seedShop();
        $this->failCommitsAfterTheWrite();
        self::getContainer()->set(ValkeyPingWindow::class, new ValkeyPingWindow(self::valkeyThatFailsReleases()));
        $this->logIn($this->client, $this->people['Omar']);
        $this->client->catchExceptions(false);

        try {
            $this->client->request('POST', $this->pathTo('Lena'), $this->pingFields(), [], self::TURBO_FORM_HEADERS);
            self::fail('The failed commit was answered as a success.');
        } catch (\RuntimeException $failure) {
            self::assertSame([\RuntimeException::class, 'The commit failed.'], [$failure::class, $failure->getMessage()]);
        }

        // The slot then ends by itself after the ping limit.
        self::assertSame(1, $this->stateValkey()->exists($this->slotKeyOf('Omar', 'Lena')));
        self::assertSame([], $this->storedPings());
        self::assertSame(0, $this->publishedPingEvents());
    }

    #[Test]
    public function aPingTooSoonFromAFormWithoutTurboRedirectsToTheTicketWithTheWait(): void
    {
        $this->seedShop();
        $this->logIn($this->client, $this->people['Omar']);
        $this->client->request('POST', $this->pathTo('Lena'), $this->pingFields(), [], self::TURBO_FORM_HEADERS);
        self::assertResponseStatusCodeSame(200);
        $this->clock->modify('+90 minutes');

        $this->client->request('POST', $this->pathTo('Lena'), $this->pingFields(['message' => 'Any news?']));

        self::assertResponseStatusCodeSame(303);
        self::assertSame(self::TICKET_PAGE, $this->client->getResponse()->headers->get('Location'));
        $page = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            ['You pinged Lena about this ticket recently. You can ping them again in 3 h.'],
            $page->filter('.toast-region [data-toast-message-value]')->each(static fn (Crawler $toast): string => (string) $toast->attr('data-toast-message-value')),
        );
        self::assertSame([self::MESSAGE], array_column($this->storedPings(), 'body'));
        self::assertSame(1, $this->publishedPingEvents());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pathsOfNoPing(): iterable
    {
        yield 'an unknown person' => ['/t/SHOP-1/ping/'.self::NOBODY_ID];
        yield 'a person who is no uuid' => ['/t/SHOP-1/ping/lena'];
        yield 'a uuid with a trailing newline' => ['/t/SHOP-1/ping/{Lena}%0A'];
        yield 'an unknown ticket' => ['/t/SHOP-404/ping/{Lena}'];
    }

    #[Test]
    #[DataProvider('pathsOfNoPing')]
    public function aPathOfNoTicketOrNoPersonIsNotFoundAndNothingIsStored(string $path): void
    {
        $this->seedShop();
        $this->logIn($this->client, $this->people['Omar']);

        $this->client->request('POST', strtr($path, ['{Lena}' => $this->idOf('Lena')]), $this->pingFields(), [], self::TURBO_FORM_HEADERS);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->storedPings());
    }

    #[Test]
    public function aPersonOfAnotherOrganizationIsNotFoundAndNothingIsStored(): void
    {
        $this->seedShop();
        $this->addBenOfBetaBakery();
        $this->logIn($this->client, $this->people['Omar']);

        $this->client->request('POST', $this->pathTo('Ben'), $this->pingFields(), [], self::TURBO_FORM_HEADERS);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->storedPings());
    }

    #[Test]
    public function aPingFromAFormWithoutTurboRedirectsToTheTicketWithTheToast(): void
    {
        $this->seedShop();
        $this->logIn($this->client, $this->people['Omar']);

        $this->client->request('POST', $this->pathTo('Lena'), $this->pingFields());

        self::assertResponseStatusCodeSame(303);
        self::assertSame(self::TICKET_PAGE, $this->client->getResponse()->headers->get('Location'));
        $page = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSame('Pinged Lena. One notification sent.', (string) $page->filter('.toast-region [data-toast-message-value]')->attr('data-toast-message-value'));
        self::assertCount(1, $this->storedPings());
    }

    #[Test]
    public function theTicketPageGivesOmarAPingSheetForLenaWithTheDraftAndNoneForHimself(): void
    {
        $this->seedShop();
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', self::TICKET_PAGE);

        self::assertResponseStatusCodeSame(200);
        $sheet = $page->filter('#ping-'.self::KEY.'-'.$this->idOf('Lena'));
        self::assertCount(1, $sheet);
        self::assertSame('Ping', trim($sheet->filter('button[aria-haspopup="dialog"]')->text()));
        self::assertSame('Ping Lena', trim($sheet->filter('.dialog__title')->text()));
        self::assertSame('SHOP-1 · Cart total shows the wrong sum', trim($sheet->filter('.ping-sheet__ticket')->text()));
        self::assertSame(self::DRAFT, $sheet->filter('textarea[name="message"]')->text());
        self::assertSame('Lena gets one notification. You can ping again after 4 hours (set by your admin).', trim($sheet->filter('.ping-sheet__note')->text()));
        $form = $sheet->filter('form.ping-sheet__form');
        self::assertSame($this->pathTo('Lena'), $form->attr('action'));
        self::assertSame('true', $form->attr('data-turbo'));
        self::assertSame(PingSheet::PING_FAILED, $form->attr('data-failed-toast'));
        self::assertSame(['Cancel', 'Send ping'], $sheet->filter('.dialog__actions button')->each(static fn (Crawler $button): string => trim($button->text())));
        self::assertCount(0, $page->filter('#ping-'.self::KEY.'-'.$this->idOf('Omar')));
        self::assertGreaterThan(1, $page->filter('.ticket-people__row')->count());
        // Ping keeps its own grid column, apart from the name and role, so a long role line cannot push it down.
        $lenasRow = $page->filter('.ticket-people__row')->reduce(static fn (Crawler $row): bool => 'Lena' === $row->filter('.ticket-people__who > .ticket-people__name')->text());
        self::assertCount(1, $lenasRow);
        self::assertCount(1, $lenasRow->filter('.ticket-people__row > .ticket-people__actions > #ping-'.self::KEY.'-'.$this->idOf('Lena')));
        self::assertCount(0, $lenasRow->filter('.ticket-people__who .ping-sheet'));
    }

    #[Test]
    public function theSheetsDraftSentIsStoredAsTheMessage(): void
    {
        $this->seedShop();
        $this->logIn($this->client, $this->people['Omar']);
        $page = $this->client->request('GET', self::TICKET_PAGE);
        self::assertResponseStatusCodeSame(200);

        $this->client->submit($page->filter('#ping-'.self::KEY.'-'.$this->idOf('Lena').' form.ping-sheet__form')->form(), [], ['HTTP_ACCEPT' => self::TURBO_FORM_HEADERS['HTTP_ACCEPT']]);

        $this->streamsOfTheAnswer(200, 'Lena', true);
        self::assertSame([self::DRAFT], array_column($this->storedPings(), 'body'));
    }

    #[Test]
    public function miaAViewerOfTheTeamSeesThePeopleButNoPingButton(): void
    {
        $this->seedShop();
        $this->logIn($this->client, $this->people['Mia']);

        $page = $this->client->request('GET', self::TICKET_PAGE);

        self::assertResponseStatusCodeSame(200);
        self::assertGreaterThan(1, $page->filter('.ticket-people__row')->count());
        self::assertCount(0, $page->filter('.ping-sheet'));
        self::assertCount(0, $page->filter('[id^="ping-"]'));
    }

    #[Test]
    public function afterAPingLenasRowShowsThePingedStateWithTheWaitLeft(): void
    {
        $this->seedShop();
        $this->logIn($this->client, $this->people['Omar']);
        $this->client->request('POST', $this->pathTo('Lena'), $this->pingFields(), [], self::TURBO_FORM_HEADERS);
        self::assertResponseStatusCodeSame(200);
        $this->clock->modify('+90 minutes');

        $page = $this->client->request('GET', self::TICKET_PAGE);

        self::assertResponseStatusCodeSame(200);
        $lenasAction = $page->filter('#ping-'.self::KEY.'-'.$this->idOf('Lena'));
        self::assertSame('Pinged · again in 3 h', trim($lenasAction->text()));
        self::assertCount(0, $lenasAction->filter('button, form'));
    }

    /**
     * GET /t/{key}/ping/{userId}/sheet, which a My reports card loads into its frame: the ticket page's sheet, opened as
     * it connects, with no Ping button of its own, posting to the same ping route. It has no Now line to quote.
     */
    #[Test]
    public function theSheetRouteGivesTheCardsFrameTheOpenedSheetAndStoresNothing(): void
    {
        $this->seedShop();
        $this->logIn($this->client, $this->people['Lena']);

        $page = $this->client->request('GET', $this->sheetPathTo('Omar'), [], [], self::SHEET_FRAME_HEADERS);

        self::assertResponseStatusCodeSame(200);
        $frame = $page->filter('turbo-frame#ping-sheet-SHOP-1');
        self::assertCount(1, $frame);
        $sheet = $frame->filter('#ping-'.self::KEY.'-'.$this->idOf('Omar').'.ping-sheet');
        self::assertCount(1, $sheet);
        self::assertCount(1, $sheet->filter('[data-controller="dialog"][data-dialog-open-on-connect-value="true"] > dialog'), 'The sheet opens as it connects.');
        self::assertCount(0, $sheet->filter('[aria-haspopup="dialog"]'), 'The card\'s own Ping button is the trigger.');
        self::assertSame('Ping Omar', trim($sheet->filter('.dialog__title')->text()));
        self::assertSame($this->pathTo('Omar'), $sheet->filter('form.ping-sheet__form')->attr('action'));
        self::assertSame('Hi Omar, could you look at SHOP-1?', $sheet->filter('textarea.ping-sheet__message')->text());
        self::assertSame([], $this->storedPings());
    }

    /**
     * Without script, or for another frame, the request is the ticket page's People, where the sheet is on the page.
     *
     * @return iterable<string, array{array<string, string>}>
     */
    public static function requestsOutsideTheSheetsFrame(): iterable
    {
        yield 'no frame' => [[]];
        yield 'another ticket\'s frame' => [['HTTP_TURBO_FRAME' => 'ping-sheet-SHOP-2']];
    }

    /**
     * @param array<string, string> $headers
     */
    #[Test]
    #[DataProvider('requestsOutsideTheSheetsFrame')]
    public function theSheetRouteOutsideItsFrameGoesToTheTicketsPeople(array $headers): void
    {
        $this->seedShop();
        $this->logIn($this->client, $this->people['Lena']);

        $this->client->request('GET', $this->sheetPathTo('Omar'), [], [], $headers);

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/t/SHOP-1#ticket-people-heading');
    }

    /**
     * @param array{reporter?: string, visibility?: string} $ticket
     * @param 'member'|'viewer'|null                        $miaTeamRole
     */
    #[Test]
    #[DataProvider('peopleWhoMayNotPing')]
    public function theSheetRouteIsNotFoundForSomeoneWhoMayNotPing(string $sender, array $ticket, ?string $miaTeamRole): void
    {
        $this->seedShop($ticket, $miaTeamRole);
        if ('Ben' === $sender) {
            $this->addBenOfBetaBakery();
        }
        $this->logIn($this->client, $this->people[$sender]);

        $this->client->request('GET', $this->sheetPathTo('Lena'), [], [], self::SHEET_FRAME_HEADERS);

        self::assertResponseStatusCodeSame(404);
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('No ticket you may see has that key.', $content);
        self::assertStringNotContainsString('<turbo-frame', $content);
    }

    #[Test]
    public function theSheetRouteForNoMemberIsNotFoundAndForTheViewerIsAnEmptyFrame(): void
    {
        $this->seedShop();
        $this->logIn($this->client, $this->people['Omar']);

        $this->client->request('GET', '/t/'.self::KEY.'/ping/'.self::NOBODY_ID.'/sheet', [], [], self::SHEET_FRAME_HEADERS);
        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString(PingController::RECIPIENT_NOT_FOUND, (string) $this->client->getResponse()->getContent());

        $page = $this->client->request('GET', $this->sheetPathTo('Omar'), [], [], self::SHEET_FRAME_HEADERS);
        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $page->filter('turbo-frame#ping-sheet-SHOP-1'));
        self::assertCount(0, $page->filter('.ping-sheet, .ping-sheet__pinged'), 'Nobody pings themselves.');
    }

    #[Test]
    public function theSheetRouteAfterAPingShowsThePingedStateInTheFrame(): void
    {
        $this->seedShop();
        $this->logIn($this->client, $this->people['Omar']);
        $this->client->request('POST', $this->pathTo('Lena'), $this->pingFields(), [], self::TURBO_FORM_HEADERS);
        self::assertResponseStatusCodeSame(200);
        $this->clock->modify('+90 minutes');

        $page = $this->client->request('GET', $this->sheetPathTo('Lena'), [], [], self::SHEET_FRAME_HEADERS);

        self::assertResponseStatusCodeSame(200);
        $pinged = $page->filter('turbo-frame#ping-sheet-SHOP-1 > #ping-'.self::KEY.'-'.$this->idOf('Lena'));
        self::assertSame('Pinged · again in 3 h', trim($pinged->text()));
        self::assertCount(0, $page->filter('dialog, form'));
        self::assertCount(1, $this->storedPings());
    }

    #[Test]
    public function anotherSendersPingLeavesOmarsPingButton(): void
    {
        $this->seedShop();
        $this->logIn($this->client, $this->people['Sara']);
        $this->client->request('POST', $this->pathTo('Lena'), $this->pingFields(), [], self::TURBO_FORM_HEADERS);
        self::assertResponseStatusCodeSame(200);
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', self::TICKET_PAGE);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Ping Lena', trim($page->filter('#ping-'.self::KEY.'-'.$this->idOf('Lena').' .dialog__title')->text()));
    }

    #[Test]
    public function anAnonymousVisitorIsSentToLogInAndNothingIsStored(): void
    {
        $this->seedShop();

        $this->client->request('POST', $this->pathTo('Lena'), ['message' => self::MESSAGE]);

        self::assertResponseStatusCodeSame(302);
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
        self::assertSame([], $this->storedPings());
    }

    /**
     * Acme Foods' people and SHOP-1, a bug of Shop's team in its backlog, reported by Lena and assigned to Omar unless
     * $ticket names another reporter or the visibility.
     *
     * @param array{reporter?: string, visibility?: string} $ticket
     * @param 'member'|'viewer'|null                        $miaTeamRole
     */
    private function seedShop(array $ticket = [], ?string $miaTeamRole = 'viewer'): void
    {
        $this->people = $this->createAcmeFoodsPeople($miaTeamRole);
        $this->shopProject = $this->addProject($this->shop, 'SHOP', 'Shop');
        $this->backlogStep = Uuid::v7()->toRfc4122();
        $this->shopTicket = Uuid::v7()->toRfc4122();
        $parameters = [
            $this->acmeFoods->toString(), $this->backlogStep,
            $this->acmeFoods->toString(), $this->shopTicket, $this->shopProject, $this->shop->toString(), $this->backlogStep,
            $this->idOf($ticket['reporter'] ?? 'Lena'), $this->idOf('Omar'), $ticket['visibility'] ?? 'all',
        ];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($parameters): int {
            $migrator->executeStatement(
                "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, 'Backlog', 'todo', 'backlog', 'core', 0)",
                \array_slice($parameters, 0, 2),
            );
            $migrator->executeStatement(
                "INSERT INTO tkt_ticket (organization_id, id, project_id, team_id, number, key, title, description, type, priority, priority_rank,
                     step_id, reporter_id, assignee_id, reviewer_id, waiting_on_user_id, visibility, version, components, check_reasons, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 1, 'SHOP-1', 'Cart total shows the wrong sum', '', 'bug', 'major', 2, ?, ?, ?, NULL, NULL, ?, 1, '{}', '{}',
                     TIMESTAMPTZ '2026-11-04 10:00:00+00', TIMESTAMPTZ '2026-11-05 10:00:00+00')",
                \array_slice($parameters, 2),
            );

            return 0;
        });
    }

    /**
     * Every transaction of its own runs its write and then fails and rolls back, as when the COMMIT fails.
     */
    private function failCommitsAfterTheWrite(): void
    {
        $container = self::getContainer();
        $realTransactions = new DoctrineTransactions($container->get('doctrine.orm.entity_manager'), $container->get(TenantContext::class), $container->get('doctrine'));
        $container->set(DoctrineTransactions::class, new readonly class($realTransactions) implements Transactions {
            public function __construct(private Transactions $transactions)
            {
            }

            public function run(\Closure $write): mixed
            {
                return $this->transactions->run(static function () use ($write): never {
                    $write();

                    throw new \RuntimeException('The commit failed.');
                });
            }
        });
    }

    /**
     * A connection to the tests' state Valkey on which the ping window's release script fails as phpredis does when
     * Valkey goes away, while taking a slot still works.
     */
    private static function valkeyThatFailsReleases(): \Redis
    {
        $dsn = $_SERVER['TEST_STATE_VALKEY_DSN'] ?? null;
        self::assertIsString($dsn, 'TEST_STATE_VALKEY_DSN is not set (.env.test).');
        $connection = new class extends \Redis {
            /**
             * @param array<mixed> $args
             */
            public function eval(string $script, array $args = [], int $num_keys = 0): mixed
            {
                if (str_contains($script, "'DEL'")) {
                    throw new \RedisException('Connection lost');
                }

                return parent::eval($script, $args, $num_keys);
            }
        };
        $connection->connect((string) parse_url($dsn, \PHP_URL_HOST), (int) parse_url($dsn, \PHP_URL_PORT));

        return $connection;
    }

    /**
     * The state Valkey never evicts, so the slots of each test's own organizations go with them.
     */
    private function forgetSlots(): void
    {
        foreach ($this->createdOrganizationIds as $organizationId) {
            $heldSlots = $this->stateValkey()->keys('ping:'.$organizationId.':*');
            if (\is_array($heldSlots) && [] !== $heldSlots) {
                $this->stateValkey()->del($heldSlots);
            }
        }
    }

    private function stateValkey(): \Redis
    {
        return self::getContainer()->get('state_valkey.connection');
    }

    private function slotKeyOf(string $sender, string $recipient): string
    {
        return 'ping:'.$this->acmeFoods->toString().':'.$this->idOf($sender).':'.$this->idOf($recipient).':'.$this->shopTicket;
    }

    private function addBenOfBetaBakery(): void
    {
        $betaBakery = $this->createOrganization('Beta Bakery');
        $this->people['Ben'] = $this->createUserWithoutOrganization('Ben');
        $this->addMembership($this->people['Ben'], $betaBakery);
        $this->setOrgRoles($this->people['Ben'], $betaBakery, '{admin}');
    }

    private function idOf(string $person): string
    {
        return $this->people[$person]->id()->toString();
    }

    private function pathTo(string $person): string
    {
        return '/t/'.self::KEY.'/ping/'.$this->idOf($person);
    }

    private function sheetPathTo(string $person): string
    {
        return $this->pathTo($person).'/sheet';
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string> SHOP-1's ping form token and the message, then the overrides
     */
    private function pingFields(array $overrides = []): array
    {
        return [
            PingController::CSRF_FIELD => $this->csrfTokenFor($this->client, PingController::csrfIdOf(self::KEY)),
            PingController::MESSAGE_FIELD => self::MESSAGE,
            ...$overrides,
        ];
    }

    /**
     * Asserts the answer is a Turbo Stream of the status that replaces $recipient's Ping button with the pinged state
     * when $isPinged, else fills the open sheet's refusal alert, and then updates the toast region.
     *
     * @return array<string, Crawler> each stream's template content by its target
     */
    private function streamsOfTheAnswer(int $expectedStatus, string $recipient, bool $isPinged): array
    {
        self::assertResponseStatusCodeSame($expectedStatus);
        self::assertSame('text/vnd.turbo-stream.html; charset=UTF-8', $this->client->getResponse()->headers->get('Content-Type'));
        preg_match_all(
            '#<turbo-stream action="([a-z]+)" target="([A-Za-z0-9-]+)"><template>(.*?)</template></turbo-stream>#s',
            (string) $this->client->getResponse()->getContent(),
            $matches,
            \PREG_SET_ORDER,
        );
        $buttonTarget = $isPinged ? ['replace', 'ping-'.self::KEY.'-'.$this->idOf($recipient)] : ['update', 'ping-'.self::KEY.'-'.$this->idOf($recipient).'-refusal'];
        self::assertSame([$buttonTarget, ['update', self::TOAST_REGION_ID]], array_map(static fn (array $match): array => [$match[1], $match[2]], $matches));
        $streams = [];
        foreach ($matches as [, , $target, $content]) {
            $streams[$target] = new Crawler('<!DOCTYPE html><html><body>'.$content.'</body></html>');
        }

        return $streams;
    }

    /**
     * @param array<string, Crawler> $streams
     */
    private function toastOf(array $streams): string
    {
        $toast = $streams[self::TOAST_REGION_ID]->filter('.toast[data-controller="toast"]');
        self::assertCount(1, $toast);

        return (string) $toast->attr('data-toast-message-value');
    }

    /**
     * @return list<array{from_id: string, to_id: string, body: string, created_at: string}> Acme Foods' pings, oldest first
     */
    private function storedPings(): array
    {
        $organizationId = $this->acmeFoods->toString();
        $rows = [];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId, &$rows): int {
            $rows = $migrator->fetchAllAssociative(
                "SELECT from_id, to_id, body, to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') || '+00' AS created_at
                 FROM ntf_ping WHERE organization_id = ? ORDER BY created_at, id",
                [$organizationId],
            );

            return 0;
        });

        return array_map(static function (array $row): array {
            ['from_id' => $fromId, 'to_id' => $toId, 'body' => $body, 'created_at' => $createdAt] = $row;
            \assert(\is_string($fromId) && \is_string($toId) && \is_string($body) && \is_string($createdAt));

            return ['from_id' => $fromId, 'to_id' => $toId, 'body' => $body, 'created_at' => $createdAt];
        }, $rows);
    }

    private function publishedPingEvents(): int
    {
        $organizationId = $this->acmeFoods->toString();
        $count = 0;
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId, &$count): int {
            $count = $migrator->fetchOne(
                "SELECT count(*) FROM messenger_outbox WHERE queue_name = 'outbox' AND position(? in body) > 0 AND position('PingSent' in body) > 0",
                [$organizationId],
            );
            \assert(\is_int($count));

            return 0;
        });

        return $count;
    }
}
