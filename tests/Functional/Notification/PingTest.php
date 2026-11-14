<?php

declare(strict_types=1);

namespace App\Tests\Functional\Notification;

use App\Identity\Domain\User;
use App\Notification\Infrastructure\RateLimit\ValkeyPingWindow;
use App\Notification\UI\Controller\PingController;
use App\SharedKernel\Domain\OrganizationId;
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
    private const string NOBODY_ID = '0197a3c0-0000-7000-8000-00000000dead';
    /** What Turbo sends with a form it posts. */
    private const array TURBO_FORM_HEADERS = ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml'];
    private const string TOAST_REGION_ID = 'toast-region';

    private KernelBrowser $client;

    private MockClock $clock;

    /** @var array<string, User> */
    private array $people;

    private string $shopProject;

    private string $backlogStep;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTPS' => 'on']);
        $this->client->disableReboot();
        $this->clock = new MockClock(new \DateTimeImmutable(self::NOW));
        self::getContainer()->set('clock', $this->clock);
    }

    protected function tearDown(): void
    {
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

        $streams = $this->streamsOfTheAnswer(200, 'Lena');
        self::assertSame('Pinged · again in 4 h', trim($streams['ping-'.$this->idOf('Lena')]->filter('#ping-'.$this->idOf('Lena'))->text()));
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

        $streams = $this->streamsOfTheAnswer(422, 'Lena');
        self::assertSame('Pinged · again in 3 h', trim($streams['ping-'.$this->idOf('Lena')]->text()));
        self::assertSame('You pinged Lena about this ticket recently. You can ping them again in 3 h.', $this->toastOf($streams));
        self::assertCount(1, $this->storedPings());
        self::assertSame(self::MESSAGE, $this->storedPings()[0]['body']);
        self::assertSame(1, $this->publishedPingEvents());
    }

    /**
     * @return iterable<string, array{string, string, array{reporter?: string, visibility?: string}, ?string}> who pings
     *                                                                                                         whom, the
     *                                                                                                         ticket, and
     *                                                                                                         Mia's team role
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
     */
    #[Test]
    #[DataProvider('peopleWhoMayPing')]
    public function someoneWhoMayPingSendsOnePing(string $sender, string $recipient, array $ticket, ?string $miaTeamRole): void
    {
        $this->seedShop($ticket, $miaTeamRole);
        $this->logIn($this->client, $this->people[$sender]);

        $this->client->request('POST', $this->pathTo($recipient), $this->pingFields(), [], self::TURBO_FORM_HEADERS);

        $streams = $this->streamsOfTheAnswer(200, $recipient);
        self::assertSame(\sprintf('Pinged %s. One notification sent.', $recipient), $this->toastOf($streams));
        self::assertSame([[$this->idOf($sender), $this->idOf($recipient)]], array_map(static fn (array $ping): array => [$ping['from_id'], $ping['to_id']], $this->storedPings()));
    }

    /**
     * @return iterable<string, array{string, array{reporter?: string, visibility?: string}, ?string}> who pings Lena,
     *                                                                                                 the ticket, and
     *                                                                                                 Mia's team role
     */
    public static function peopleWhoMayNotPing(): iterable
    {
        yield 'Mia, a viewer of the team' => ['Mia', [], 'viewer'];
        yield 'Mia, with no team role and not the reporter' => ['Mia', [], null];
        yield 'Raj, a manager who may not see a team-only ticket' => ['Raj', ['visibility' => 'team'], 'viewer'];
        yield 'Ben, of another organization' => ['Ben', [], 'viewer'];
    }

    /**
     * @param array{reporter?: string, visibility?: string} $ticket
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
     * @return iterable<string, array{string, string, array{visibility?: string}, array<string, string>}> who pings whom,
     *                                                                                                    the ticket, the
     *                                                                                                    fields, the toast
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

        $streams = $this->streamsOfTheAnswer(422, null);
        self::assertSame($expectedToast, $this->toastOf($streams));
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

        $streams = $this->streamsOfTheAnswer(422, null);
        self::assertSame('Pings are paused for a moment. Try again soon.', $this->toastOf($streams));
        self::assertSame([], $this->storedPings());
        self::assertSame(0, $this->publishedPingEvents());
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
        $parameters = [
            $this->acmeFoods->toString(), $this->backlogStep,
            $this->acmeFoods->toString(), Uuid::v7()->toRfc4122(), $this->shopProject, $this->shop->toString(), $this->backlogStep,
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
     * Asserts the answer is a Turbo Stream of the status that replaces $recipient's Ping button, when given, and then
     * updates the toast region.
     *
     * @return array<string, Crawler> each stream's template content by its target
     */
    private function streamsOfTheAnswer(int $expectedStatus, ?string $recipient): array
    {
        self::assertResponseStatusCodeSame($expectedStatus);
        self::assertSame('text/vnd.turbo-stream.html; charset=UTF-8', $this->client->getResponse()->headers->get('Content-Type'));
        preg_match_all(
            '#<turbo-stream action="([a-z]+)" target="([a-z0-9-]+)"><template>(.*?)</template></turbo-stream>#s',
            (string) $this->client->getResponse()->getContent(),
            $matches,
            \PREG_SET_ORDER,
        );
        $expectedTargets = [['update', self::TOAST_REGION_ID]];
        if (null !== $recipient) {
            array_unshift($expectedTargets, ['replace', 'ping-'.$this->idOf($recipient)]);
        }
        self::assertSame($expectedTargets, array_map(static fn (array $match): array => [$match[1], $match[2]], $matches));
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

        /* @var list<array{from_id: string, to_id: string, body: string, created_at: string}> $rows */
        return $rows;
    }

    private function publishedPingEvents(): int
    {
        $organizationId = $this->acmeFoods->toString();
        $count = 0;
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId, &$count): int {
            $count = (int) $migrator->fetchOne(
                "SELECT count(*) FROM messenger_outbox WHERE queue_name = 'outbox' AND position(? in body) > 0 AND position('PingSent' in body) > 0",
                [$organizationId],
            );

            return 0;
        });

        return $count;
    }
}
