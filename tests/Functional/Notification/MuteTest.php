<?php

declare(strict_types=1);

namespace App\Tests\Functional\Notification;

use App\Conversation\Api\Event\ReplyPosted;
use App\Identity\Domain\User;
use App\Notification\Api\Event\PingSent;
use App\Notification\Application\Notify\NotifyRecipients;
use App\Notification\UI\Controller\MuteController;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Infrastructure\Messenger\MessageBeingHandled;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use App\Tests\Functional\Web\IssuesCsrfTokens;
use App\Tests\Integration\Organization\TenantTransactions;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * Muting one ticket, POST /t/{key}/mute and /t/{key}/unmute, on Acme Foods' team-only SHOP-1 through the real firewall,
 * voters, session and database, and what NotifyRecipients then writes for the person who muted it. The clock is fixed
 * on 6 Nov 2026, before today: a clock ahead of the login would end the session as idle. Every refusal also proves
 * that the stored mutes did not change.
 */
final class MuteTest extends WebTestCase
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
    private const string MUTE_PATH = '/t/SHOP-1/mute';
    private const string UNMUTE_PATH = '/t/SHOP-1/unmute';

    private KernelBrowser $client;

    /** @var array<string, User> */
    private array $people;

    private string $shopTicket;

    private string $lenasPing;

    private string $omarsComment;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTPS' => 'on']);
        $this->client->disableReboot();
        self::getContainer()->set('clock', new MockClock(new \DateTimeImmutable(self::NOW)));
    }

    protected function tearDown(): void
    {
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->committedAs(new OrganizationId($organizationId), static function (Connection $migrator) use ($organizationId): int {
                foreach (['ntf_mute', 'ntf_ping', 'cnv_message', 'tkt_ticket', 'wf_step'] as $table) {
                    $migrator->executeStatement(\sprintf('DELETE FROM %s WHERE organization_id = ?', $table), [$organizationId]);
                }

                return 0;
            });
        }
        $this->removeCommittedProjects();
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function omarMutesTheTicketAndGetsNoNotificationOfItUntilHeUnmutesIt(): void
    {
        $this->seedShop();
        self::assertSame(2, $this->omarsNotificationsOfAPingAndAReply(), 'Before the mute, Omar is notified of both.');
        $this->logIn($this->client, $this->people['Omar']);

        $this->client->request('POST', self::MUTE_PATH, $this->muteFields());

        self::assertResponseStatusCodeSame(303);
        self::assertSame(self::TICKET_PAGE, $this->client->getResponse()->headers->get('Location'));
        self::assertSame([[$this->idOf('Omar'), '2026-11-06 12:00:00+00']], $this->storedMutes());
        $page = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSame(MuteController::MUTED, (string) $page->filter('.toast-region [data-toast-message-value]')->attr('data-toast-message-value'));
        self::assertSame(0, $this->omarsNotificationsOfAPingAndAReply());

        $this->client->request('POST', self::UNMUTE_PATH, $this->muteFields());

        self::assertResponseStatusCodeSame(303);
        self::assertSame(self::TICKET_PAGE, $this->client->getResponse()->headers->get('Location'));
        self::assertSame([], $this->storedMutes());
        $page = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSame(MuteController::UNMUTED, (string) $page->filter('.toast-region [data-toast-message-value]')->attr('data-toast-message-value'));
        self::assertSame(2, $this->omarsNotificationsOfAPingAndAReply());
    }

    /**
     * The path and whether the person has muted SHOP-1 before, so that either path, let through, would change the mutes.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function mutePaths(): iterable
    {
        yield 'mute' => [self::MUTE_PATH, false];
        yield 'unmute' => [self::UNMUTE_PATH, true];
    }

    #[Test]
    #[DataProvider('mutePaths')]
    public function rajWhoMayNotSeeTheTeamOnlyTicketIsNotFoundAndTheMutesStay(string $path, bool $isMutedBefore): void
    {
        $this->seedShop();
        if ($isMutedBefore) {
            $this->addMuteOf('Raj');
        }
        $mutesBefore = $this->storedMutes();
        $this->logIn($this->client, $this->people['Raj']);

        $this->client->request('POST', $path, $this->muteFields());

        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString(MuteController::TICKET_NOT_FOUND, (string) $this->client->getResponse()->getContent());
        self::assertSame($mutesBefore, $this->storedMutes());
    }

    /**
     * @return iterable<string, array{string, bool, array<string, string>}>
     */
    public static function expiredForms(): iterable
    {
        foreach (self::mutePaths() as $action => [$path, $isMutedBefore]) {
            yield $action.' with no token' => [$path, $isMutedBefore, []];
            yield $action.' with a forged token' => [$path, $isMutedBefore, [MuteController::CSRF_FIELD => 'forged']];
        }
    }

    /**
     * @param array<string, string> $fields
     */
    #[Test]
    #[DataProvider('expiredForms')]
    public function aFormWithoutItsTokenAnswers422AndChangesNoMute(string $path, bool $isMutedBefore, array $fields): void
    {
        $this->seedShop();
        if ($isMutedBefore) {
            $this->addMuteOf('Lena');
        }
        $mutesBefore = $this->storedMutes();
        $this->logIn($this->client, $this->people['Lena']);

        $this->client->request('POST', $path, $fields);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(MuteController::FORM_EXPIRED, $this->client->getResponse()->getContent());
        self::assertSame($mutesBefore, $this->storedMutes());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pathsOfNoTicket(): iterable
    {
        yield 'mute' => ['/t/SHOP-99/mute'];
        yield 'unmute' => ['/t/SHOP-99/unmute'];
    }

    #[Test]
    #[DataProvider('pathsOfNoTicket')]
    public function aKeyOfNoTicketIsNotFoundAndStoresNoMute(string $path): void
    {
        $this->seedShop();
        $this->logIn($this->client, $this->people['Omar']);

        $this->client->request('POST', $path, [MuteController::CSRF_FIELD => $this->csrfTokenFor($this->client, MuteController::csrfIdOf('SHOP-99'))]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->storedMutes());
    }

    #[Test]
    public function anAnonymousVisitorIsSentToLogInAndNothingIsStored(): void
    {
        $this->seedShop();

        $this->client->request('POST', self::MUTE_PATH);

        self::assertResponseStatusCodeSame(302);
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
        self::assertSame([], $this->storedMutes());
    }

    /**
     * Acme Foods' people and SHOP-1, a team-only bug of Shop's team in its backlog, reported by Lena and assigned to
     * Omar, with one ping from Lena to Omar and one comment by Omar.
     */
    private function seedShop(): void
    {
        $this->people = $this->createAcmeFoodsPeople();
        $shopProject = $this->addProject($this->shop, 'SHOP', 'Shop');
        $backlogStep = Uuid::v7()->toRfc4122();
        $this->shopTicket = Uuid::v7()->toRfc4122();
        $this->lenasPing = Uuid::v7()->toRfc4122();
        $this->omarsComment = Uuid::v7()->toRfc4122();
        $organizationId = $this->acmeFoods->toString();
        $parameters = [
            $organizationId, $backlogStep,
            $organizationId, $this->shopTicket, $shopProject, $this->shop->toString(), $backlogStep, $this->idOf('Lena'), $this->idOf('Omar'),
            $organizationId, $this->lenasPing, $this->shopTicket, $this->idOf('Lena'), $this->idOf('Omar'),
            $organizationId, $this->omarsComment, $this->shopTicket, $this->idOf('Omar'),
        ];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($parameters): int {
            $migrator->executeStatement(
                "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, 'Backlog', 'todo', 'backlog', 'core', 0)",
                \array_slice($parameters, 0, 2),
            );
            $migrator->executeStatement(
                "INSERT INTO tkt_ticket (organization_id, id, project_id, team_id, number, key, title, description, type, priority, priority_rank,
                     step_id, reporter_id, assignee_id, reviewer_id, waiting_on_user_id, visibility, version, components, check_reasons, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 1, 'SHOP-1', 'Cart total shows the wrong sum', '', 'bug', 'major', 2, ?, ?, ?, NULL, NULL, 'team', 1, '{}', '{}',
                     TIMESTAMPTZ '2026-11-04 10:00:00+00', TIMESTAMPTZ '2026-11-05 10:00:00+00')",
                \array_slice($parameters, 2, 7),
            );
            $migrator->executeStatement(
                "INSERT INTO ntf_ping (organization_id, id, ticket_id, from_id, to_id, body, created_at) VALUES (?, ?, ?, ?, ?, 'Could you look at SHOP-1?', TIMESTAMPTZ '2026-11-06 11:00:00+00')",
                \array_slice($parameters, 9, 5),
            );
            $migrator->executeStatement(
                "INSERT INTO cnv_message (organization_id, id, ticket_id, author_id, kind, body, created_at) VALUES (?, ?, ?, ?, 'comment', 'The sum is off by one item.', TIMESTAMPTZ '2026-11-06 11:10:00+00')",
                \array_slice($parameters, 14),
            );

            return 0;
        });
    }

    private function addMuteOf(string $person): void
    {
        $parameters = [$this->acmeFoods->toString(), $this->idOf($person), $this->shopTicket];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO ntf_mute (organization_id, user_id, ticket_id, created_at) VALUES (?, ?, ?, TIMESTAMPTZ '2026-11-05 09:00:00+00')",
            $parameters,
        ));
    }

    /**
     * Lena's ping to Omar and her reply to his message, each handled by NotifyRecipients as worker-notify would, in
     * one transaction under Acme Foods that is rolled back, so each call starts from no notification.
     *
     * @return int how many notifications Omar got
     */
    private function omarsNotificationsOfAPingAndAReply(): int
    {
        $container = self::getContainer();
        $tenantContext = $container->get(TenantContext::class);
        $tenantContext->reset();
        $entityManager = $container->get('doctrine.orm.default_entity_manager');
        $entityManager->clear();
        $organizationId = $this->acmeFoods->toString();
        $omar = $this->idOf('Omar');
        $events = [
            new PingSent($organizationId, $this->shopTicket, $this->lenasPing, $this->idOf('Lena'), $omar, $this->idOf('Lena'), new \DateTimeImmutable('2026-11-06 11:00:00 UTC')),
            new ReplyPosted($organizationId, $this->shopTicket, $this->omarsComment, Uuid::v7()->toRfc4122(), $omar, $this->idOf('Lena'), new \DateTimeImmutable('2026-11-06 11:30:00 UTC')),
        ];

        return new TenantTransactions($entityManager, $tenantContext)->rolledBackAs($organizationId, static function () use ($container, $entityManager, $events, $organizationId, $omar): int {
            $messageBeingHandled = $container->get(MessageBeingHandled::class);
            foreach ($events as $event) {
                $messageBeingHandled->set(Uuid::v7()->toRfc4122());
                try {
                    $container->get(NotifyRecipients::class)($event);
                } finally {
                    $messageBeingHandled->reset();
                }
            }
            $count = $entityManager->getConnection()->fetchOne('SELECT count(*) FROM ntf_notification WHERE organization_id = ? AND user_id = ?', [$organizationId, $omar]);
            \assert(\is_int($count));

            return $count;
        });
    }

    /**
     * @return list<array{mixed, mixed}> each stored mute of SHOP-1: who muted it and when, by person
     */
    private function storedMutes(): array
    {
        $parameters = [$this->acmeFoods->toString(), $this->shopTicket];
        $rows = [];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($parameters, &$rows): int {
            $rows = $migrator->fetchAllNumeric(
                "SELECT user_id, to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') || '+00' FROM ntf_mute WHERE organization_id = ? AND ticket_id = ? ORDER BY user_id",
                $parameters,
            );

            return 0;
        });

        return array_map(static fn (array $row): array => [$row[0], $row[1]], $rows);
    }

    /**
     * @return array<string, string> SHOP-1's mute form token
     */
    private function muteFields(): array
    {
        return [MuteController::CSRF_FIELD => $this->csrfTokenFor($this->client, MuteController::csrfIdOf(self::KEY))];
    }

    private function idOf(string $person): string
    {
        return $this->people[$person]->id()->toString();
    }
}
