<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity\Application;

use App\Identity\Api\Event\ApiTokenRevoked;
use App\Identity\Api\OAuthConnection;
use App\Identity\Api\OAuthConnectionNotFound;
use App\Identity\Api\OAuthConnectionRefused;
use App\Identity\Application\SessionConfirmations;
use App\Identity\Domain\ApiToken;
use App\Identity\Domain\ApiTokenHashing;
use App\Identity\Domain\ApiTokens;
use App\Identity\Domain\ApiTokenScope;
use App\Identity\Domain\Credential;
use App\Identity\Domain\Email;
use App\Identity\Domain\OAuthRefreshToken;
use App\Identity\Domain\OAuthRefreshTokens;
use App\Identity\Domain\PasswordHash;
use App\Identity\Domain\TwoStepLogin;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Doctrine\DoctrineApiTokens;
use App\Identity\Infrastructure\Doctrine\DoctrineOAuthRefreshTokens;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Identity\HandWiredOAuthConnections;
use App\Tests\Integration\Identity\WaitsForWaitingChanges;
use App\Tests\Integration\Organization\OrganizationRows;
use App\Tests\Integration\Organization\TenantTransactions;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Process\Process;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * EndOAuthConnection, and OrganizationOAuthConnections::end() an Admin page will call, on the real tables as
 * pinround_app with the app's voters, each end in its own committed transaction under a tenant as a request runs it.
 * Both are wired by hand (HandWiredOAuthConnections). The person is logged in with a password session; Lena and Mia
 * also have two-step login on and a code typed 30 minutes ago in the request's session, so only Lena's Admin role tells
 * them apart. The clock is frozen on 20 Mar 2099, as the access token resolver compares expiry with the database clock.
 *
 * Omar's connection CONNECTION of Acme Foods was renewed an hour ago: its first refresh token is traded and its first
 * access token stopped by the rotation; its second pair works. His OTHER_CONNECTION of Acme Foods and his
 * BETA_CONNECTION of Beta Bakery work too.
 */
final class EndOAuthConnectionTest extends KernelTestCase
{
    use WaitsForWaitingChanges;

    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000042a1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000042b1';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000042c1';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000042c2';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000042c3';

    private const string CONNECTION = '0197a3c0-0000-7000-8000-0000000042f1';
    private const string OTHER_CONNECTION = '0197a3c0-0000-7000-8000-0000000042f2';
    private const string BETA_CONNECTION = '0197a3c0-0000-7000-8000-0000000042f3';

    private const string CLIENT_ID = 'end-connection-test-client-00042';
    private const string NOW = '2099-03-20 09:00:00 UTC';
    private const string NOW_IN_UTC = '2099-03-20 09:00:00';
    private const string AN_HOUR_AGO_IN_UTC = '2099-03-20 08:00:00';

    private const string NOT_FOUND = 'No connection of this organization has this id.';

    /** @var array<string, array{access: ApiToken, refresh: OAuthRefreshToken}> each seeded pair by name */
    private array $pairs = [];

    protected function setUp(): void
    {
        self::getContainer()->set('clock', new MockClock(self::NOW));
        $this->removeSeededRows();
        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            $this->addMembership(self::ACME_FOODS, self::OMAR, '{}');
            $this->addMembership(self::ACME_FOODS, self::LENA, '{admin}');
            $this->addMembership(self::ACME_FOODS, self::MIA, '{}');
        });
        $this->transactions()->committedAs(self::BETA_BAKERY, fn () => $this->addMembership(self::BETA_BAKERY, self::OMAR, '{}'));
        $this->entityManager()->wrapInTransaction(function (EntityManagerInterface $entityManager): void {
            foreach ([self::LENA => 'Lena', self::MIA => 'Mia'] as $userId => $name) {
                $user = self::userOf($userId, $name);
                $twoStepLogin = new TwoStepLogin($user, 'never decrypted here', $this->at('-2 hours'));
                $twoStepLogin->confirm(1, $this->at('-2 hours'), ['$argon2id$never-checked-here']);
                $entityManager->persist($user);
                $entityManager->persist($twoStepLogin);
            }
        });
        $this->entityManager()->clear();

        $this->pairs['traded'] = $this->addPair(self::ACME_FOODS, self::CONNECTION, '-2 hours');
        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            self::assertNotNull($this->refreshTokens()->markUsed($this->pairs['traded']['refresh']->tokenHash(), self::CLIENT_ID, $this->at('-1 hour')));
            self::assertTrue($this->apiTokens()->revoke($this->pairs['traded']['access']->id(), $this->at('-1 hour')));
        });
        $this->pairs['live'] = $this->addPair(self::ACME_FOODS, self::CONNECTION, '-1 hour', $this->pairs['traded']['refresh']->familyCreatedAt());
        $this->pairs['other'] = $this->addPair(self::ACME_FOODS, self::OTHER_CONNECTION, '-3 hours');
        $this->pairs['beta'] = $this->addPair(self::BETA_BAKERY, self::BETA_CONNECTION, '-1 hour');
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('security.token_storage')->setToken(null);
        $requestStack = self::getContainer()->get('request_stack');
        while (null !== $requestStack->pop()) {
        }
        self::getContainer()->get('doctrine')->resetManager();
        $this->tenantContext()->reset();
        $this->removeSeededRows();
        parent::tearDown();
    }

    #[Test]
    public function thePersonEndsTheirConnectionAndItsAccessAndRefreshTokensStopWorking(): void
    {
        self::assertTrue($this->worksAsAccessToken('live'));
        self::assertTrue($this->worksAsRefreshToken('live'));
        $this->logIn(self::OMAR, 'Omar');

        self::assertTrue($this->transactions()->committedAs(self::ACME_FOODS, fn (): bool => $this->useCases()->endOAuthConnection()(self::CONNECTION, $this->at('+0 seconds'))));

        self::assertFalse($this->worksAsAccessToken('live'));
        self::assertFalse($this->worksAsRefreshToken('live'));
        self::assertSame($this->rowsWithConnectionEnded(), $this->tokenRows());
        self::assertSame([[self::ACME_FOODS, $this->pairs['live']['access']->id(), self::OMAR, self::OMAR, self::NOW_IN_UTC]], $this->revokedEvents());
        self::assertSame(
            [self::OTHER_CONNECTION],
            array_map(static fn (OAuthConnection $connection): string => $connection->familyId, $this->transactions()->rolledBackAs(
                self::ACME_FOODS,
                fn (): array => $this->useCases()->organizationOAuthConnections()->livePage($this->at('+0 seconds'), 10),
            )),
        );
    }

    #[Test]
    public function anAdminOfTheOrganizationEndsAnotherPersonsConnectionAsTheActor(): void
    {
        $this->logIn(self::LENA, 'Lena', withCodeProof: true);

        self::assertTrue($this->transactions()->committedAs(self::ACME_FOODS, fn (): bool => $this->useCases()->organizationOAuthConnections()->end(self::CONNECTION, $this->at('+0 seconds'))));

        self::assertFalse($this->worksAsAccessToken('live'));
        self::assertFalse($this->worksAsRefreshToken('live'));
        self::assertSame($this->rowsWithConnectionEnded(), $this->tokenRows());
        self::assertSame([[self::ACME_FOODS, $this->pairs['live']['access']->id(), self::OMAR, self::LENA, self::NOW_IN_UTC]], $this->revokedEvents());
    }

    /**
     * REACH: Mia has the same login assurance as Lena but is no Admin; nobody logged in is refused alike.
     */
    #[Test]
    public function anotherPersonWhoIsNoAdminOrNobodyIsRefusedAndTheConnectionStaysLive(): void
    {
        $rowsBefore = $this->tokenRows();
        foreach (['Mia' => fn () => $this->logIn(self::MIA, 'Mia', withCodeProof: true), 'nobody' => $this->logOut(...)] as $who => $logIn) {
            $logIn();
            try {
                $this->transactions()->committedAs(self::ACME_FOODS, fn (): bool => $this->useCases()->endOAuthConnection()(self::CONNECTION, $this->at('+0 seconds')));
                self::fail($who.' ended Omar\'s connection.');
            } catch (OAuthConnectionRefused $refusal) {
                self::assertSame('Only its person or an Admin of this organization can end this connection.', $refusal->getMessage(), $who);
            }
            self::getContainer()->get('doctrine')->resetManager();
        }

        self::assertSame($rowsBefore, $this->tokenRows());
        self::assertTrue($this->worksAsAccessToken('live'));
        self::assertTrue($this->worksAsRefreshToken('live'));
        self::assertSame([], $this->revokedEvents());
    }

    /**
     * REACH: an id of another organization is refused as an unknown or malformed one, even to its own person and to an
     * Admin of the tenant.
     */
    #[Test]
    public function anIdOfAnotherOrganizationOrAMalformedOrUnknownIdIsNotFoundAndNothingChanges(): void
    {
        $rowsBefore = $this->tokenRows();
        $tries = [
            'Omar, his connection of Beta Bakery' => [self::OMAR, 'Omar', false, self::BETA_CONNECTION],
            'Lena, a connection of Beta Bakery' => [self::LENA, 'Lena', true, self::BETA_CONNECTION],
            'Lena, a malformed id' => [self::LENA, 'Lena', true, 'not-a-uuid'],
            'Lena, an empty id' => [self::LENA, 'Lena', true, ''],
            'Lena, the id with a final newline' => [self::LENA, 'Lena', true, self::CONNECTION."\n"],
            'Lena, an unknown id' => [self::LENA, 'Lena', true, '0197a3c0-0000-7000-8000-0000000042ff'],
        ];
        foreach ($tries as $try => [$userId, $name, $withCodeProof, $familyId]) {
            $this->logIn($userId, $name, $withCodeProof);
            try {
                $this->transactions()->committedAs(self::ACME_FOODS, fn (): bool => $this->useCases()->organizationOAuthConnections()->end($familyId, $this->at('+0 seconds')));
                self::fail($try.' ended a connection.');
            } catch (OAuthConnectionNotFound $refusal) {
                self::assertSame(self::NOT_FOUND, $refusal->getMessage(), $try);
            }
            self::getContainer()->get('doctrine')->resetManager();
            $this->logOut();
        }

        self::assertSame($rowsBefore, $this->tokenRows());
        self::assertTrue($this->worksAsAccessToken('beta'));
        self::assertSame([], $this->revokedEvents());
    }

    #[Test]
    public function aSecondEndOfTheSameConnectionSaysItWasEndedAlreadyAndChangesNothing(): void
    {
        $this->logIn(self::OMAR, 'Omar');
        self::assertTrue($this->transactions()->committedAs(self::ACME_FOODS, fn (): bool => $this->useCases()->endOAuthConnection()(self::CONNECTION, $this->at('+0 seconds'))));
        $rowsAfterTheEnd = $this->tokenRows();

        self::assertFalse($this->transactions()->committedAs(self::ACME_FOODS, fn (): bool => $this->useCases()->endOAuthConnection()(strtoupper(self::CONNECTION), $this->at('+1 minute'))));

        self::assertSame($rowsAfterTheEnd, $this->tokenRows());
        self::assertSame([[self::ACME_FOODS, $this->pairs['live']['access']->id(), self::OMAR, self::OMAR, self::NOW_IN_UTC]], $this->revokedEvents());
    }

    /**
     * Two ends of one connection on two app connections at once, as after a double click. The test holds the family's
     * lock until both wait on it, then lets go: the first ends it, the second finds it ended.
     */
    #[Test]
    public function twoEndsAtOnceEndTheConnectionOnceAndOnlyOneSaysItEndedIt(): void
    {
        $ends = [];
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        $this->migrator()->beginTransaction();
        try {
            $this->migrator()->executeStatement('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [\sprintf(DoctrineOAuthRefreshTokens::FAMILY_LOCK_KEY_FORMAT, self::ACME_FOODS, self::CONNECTION)]);
            $ends['first'] = $this->startEnd();
            $this->waitUntilWaiting($this->connection(), 1, $ends);
            $ends['second'] = $this->startEnd();
            $this->waitUntilWaiting($this->connection(), 2, $ends);
        } finally {
            $this->migrator()->rollBack();
            $this->tenantContext()->reset();
        }

        $outcomes = [];
        foreach ($ends as $name => $end) {
            $end->wait();
            self::assertTrue($end->isSuccessful(), $name.': '.$end->getErrorOutput());
            $outcomes[] = $end->getOutput();
        }
        // Which of the two gets the lock first is PostgreSQL's choice.
        sort($outcomes);
        self::assertSame(['ended', 'ended already'], $outcomes);
        self::assertSame($this->rowsWithConnectionEnded(), $this->tokenRows());
        self::assertSame([[self::ACME_FOODS, $this->pairs['live']['access']->id(), self::OMAR, self::OMAR, self::NOW_IN_UTC]], $this->revokedEvents());
    }

    /**
     * @return list<array{string, string, string|null}> what tokenRows() gives once CONNECTION is ended at NOW: all its
     *                                                  refresh tokens and its working access token revoked then, the
     *                                                  access token the rotation stopped left as it was
     */
    private function rowsWithConnectionEnded(): array
    {
        return self::sortedRows([
            ['access '.self::ACME_FOODS, $this->pairs['traded']['access']->id(), self::AN_HOUR_AGO_IN_UTC],
            ['access '.self::ACME_FOODS, $this->pairs['live']['access']->id(), self::NOW_IN_UTC],
            ['access '.self::ACME_FOODS, $this->pairs['other']['access']->id(), null],
            ['access '.self::BETA_BAKERY, $this->pairs['beta']['access']->id(), null],
            ['refresh '.self::ACME_FOODS, $this->pairs['traded']['refresh']->id(), self::NOW_IN_UTC],
            ['refresh '.self::ACME_FOODS, $this->pairs['live']['refresh']->id(), self::NOW_IN_UTC],
            ['refresh '.self::ACME_FOODS, $this->pairs['other']['refresh']->id(), null],
            ['refresh '.self::BETA_BAKERY, $this->pairs['beta']['refresh']->id(), null],
        ]);
    }

    /**
     * @return list<array{string, string, string|null}> every access and refresh token of both organizations: its kind
     *                                                  and organization, its id, and when it was revoked in UTC
     */
    private function tokenRows(): array
    {
        $rows = [];
        foreach ([self::ACME_FOODS, self::BETA_BAKERY] as $organizationId) {
            $rowsOfOrganization = $this->transactions()->rolledBackAs($organizationId, fn (): array => $this->connection()->fetchAllNumeric(
                "SELECT 'access '||organization_id::text, id::text, to_char(revoked_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') FROM idn_api_token WHERE organization_id = ?"
                ." UNION ALL SELECT 'refresh '||organization_id::text, id::text, to_char(revoked_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') FROM idn_oauth_refresh_token WHERE organization_id = ?",
                [$organizationId, $organizationId],
            ));
            foreach ($rowsOfOrganization as [$kind, $id, $revokedAt]) {
                self::assertIsString($kind);
                self::assertIsString($id);
                self::assertTrue(null === $revokedAt || \is_string($revokedAt));
                $rows[] = [$kind, $id, $revokedAt];
            }
        }
        self::assertCount(8, $rows);

        return self::sortedRows($rows);
    }

    /**
     * @param list<array{string, string, string|null}> $rows
     *
     * @return list<array{string, string, string|null}>
     */
    private static function sortedRows(array $rows): array
    {
        usort($rows, static fn (array $one, array $other): int => [$one[0], $one[1]] <=> [$other[0], $other[1]]);

        return $rows;
    }

    /**
     * @return list<array{string, string, string, string, string}> each ApiTokenRevoked of both organizations waiting
     *                                                             in the outbox: organization, token, owner, actor and
     *                                                             time in UTC, oldest first
     */
    private function revokedEvents(): array
    {
        $serializer = new PhpSerializer();
        $events = [];
        foreach ($this->outboxBodiesOfSeededOrganizations() as $body) {
            $event = $serializer->decode(['body' => $body])->getMessage();
            if ($event instanceof ApiTokenRevoked) {
                $events[] = [$event->organizationId, $event->tokenId, $event->userId, $event->actorId, $event->occurredAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')];
            }
        }

        return $events;
    }

    /**
     * @return list<string>
     */
    private function outboxBodiesOfSeededOrganizations(): array
    {
        $bodies = [];
        foreach ($this->connection()->fetchFirstColumn(
            "SELECT body FROM messenger_outbox WHERE queue_name = 'outbox' AND (body LIKE ? OR body LIKE ?) ORDER BY id",
            ['%'.self::ACME_FOODS.'%', '%'.self::BETA_BAKERY.'%'],
        ) as $body) {
            self::assertIsString($body);
            $bodies[] = $body;
        }

        return $bodies;
    }

    /**
     * Whether the access token resolver of /mcp and /api/v1 still lets the pair's access token in.
     */
    private function worksAsAccessToken(string $pair): bool
    {
        return null !== $this->apiTokens()->ownerOfLiveToken($this->pairs[$pair]['access']->tokenHash());
    }

    /**
     * Whether the token endpoint could still trade the pair's refresh token; tried in a rolled-back transaction, so the
     * token stays as it was.
     */
    private function worksAsRefreshToken(string $pair): bool
    {
        $refreshToken = $this->pairs[$pair]['refresh'];

        return null !== $this->transactions()->rolledBackAs(
            $refreshToken->organizationId()->toString(),
            fn (): ?OAuthRefreshToken => $this->refreshTokens()->markUsed($refreshToken->tokenHash(), self::CLIENT_ID, $this->at('+1 minute')),
        );
    }

    /**
     * A password session as the login form leaves it, in a request whose session holds a code typed 30 minutes ago when
     * $withCodeProof; the person needs an idn_user row and a two-step login then.
     */
    private function logIn(string $userId, string $name, bool $withCodeProof = false): void
    {
        $user = self::userOf($userId, $name);
        $securityUser = SecurityUser::of($user, new Credential($user, new PasswordHash('$argon2id$v=19$m=65536,t=4,p=1$NUkxU2sxQlFxbXpDaUZUQQ$suVp+0jzKZuqeONGVmUghGQxxI2vd02CMokdMtaQcfA'), $this->at('-1 day')));
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($securityUser, 'main', $securityUser->getRoles()));
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::getContainer()->get('request_stack')->push($request);
        if ($withCodeProof) {
            self::getContainer()->get(SessionConfirmations::class)->recordTwoStepConfirmation($user->id(), $this->at('-30 minutes'));
        }
    }

    private function logOut(): void
    {
        self::getContainer()->get('security.token_storage')->setToken(null);
        self::getContainer()->get('request_stack')->pop();
    }

    private static function userOf(string $userId, string $name): User
    {
        return new User(new UserId($userId), new Email('end-oauth-'.strtolower($name).'@acme-foods.example'), $name, 'en', 'Europe/Berlin', new \DateTimeImmutable('2026-11-02 09:30:00 UTC'));
    }

    /**
     * One step of Omar's grant: an access token and the refresh token of the family that names it, as
     * IssueOAuthTokenPair adds them, but with no ApiTokenIssued in the outbox.
     *
     * @param ?\DateTimeImmutable $familyCreatedAt the traded refresh token's, for the family's next pair
     *
     * @return array{access: ApiToken, refresh: OAuthRefreshToken}
     */
    private function addPair(string $organizationId, string $familyId, string $sinceNow, ?\DateTimeImmutable $familyCreatedAt = null): array
    {
        $hashing = self::getContainer()->get('test.identity_api_token_hashing');
        self::assertInstanceOf(ApiTokenHashing::class, $hashing);
        $accessToken = ApiToken::issueForOAuthClient($this->apiTokens()->nextIdentity(), new OrganizationId($organizationId), new UserId(self::OMAR), 'Claude Code', [ApiTokenScope::Read], $hashing, $this->at($sinceNow))->token();
        $refreshToken = OAuthRefreshToken::issue(
            $this->refreshTokens()->nextIdentity(),
            $familyId,
            new OrganizationId($organizationId),
            new UserId(self::OMAR),
            self::CLIENT_ID,
            [ApiTokenScope::Read],
            $accessToken->id(),
            $hashing,
            $this->at($sinceNow),
            $familyCreatedAt,
        )->token();
        $this->transactions()->committedAs($organizationId, function () use ($accessToken, $refreshToken): void {
            $this->apiTokens()->add($accessToken);
            $this->refreshTokens()->add($refreshToken);
        });

        return ['access' => $accessToken, 'refresh' => $refreshToken];
    }

    private function addMembership(string $organizationId, string $userId, string $orgRoles): void
    {
        $this->connection()->executeStatement(
            "INSERT INTO org_membership (organization_id, user_id, org_roles, joined_at) VALUES (?, ?, ?, '2099-03-01 10:00:00+00')",
            [$organizationId, $userId, $orgRoles],
        );
    }

    private function startEnd(): Process
    {
        $process = new Process([\PHP_BINARY, \dirname(__DIR__).'/end-oauth-connection.php', self::ACME_FOODS, self::CONNECTION, self::OMAR, self::NOW]);
        $process->start();

        return $process;
    }

    private function at(string $sinceNow): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW)->modify($sinceNow);
    }

    /**
     * Through the migrator, which owns the tables and so may delete; FORCE ROW LEVEL SECURITY binds it too.
     */
    private function removeSeededRows(): void
    {
        foreach ([self::ACME_FOODS, self::BETA_BAKERY] as $organizationId) {
            $this->tenantContext()->set(new OrganizationId($organizationId));
            try {
                $this->migrator()->transactional(static function (Connection $migrator) use ($organizationId): void {
                    $migrator->executeStatement('DELETE FROM idn_oauth_refresh_token WHERE organization_id = ?', [$organizationId]);
                    $migrator->executeStatement('DELETE FROM idn_api_token WHERE organization_id = ?', [$organizationId]);
                });
            } finally {
                $this->tenantContext()->clear();
            }
        }
        new OrganizationRows($this->migrator(), $this->tenantContext())->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        $this->migrator()->executeStatement('DELETE FROM idn_mfa_secret WHERE user_id IN (?, ?)', [self::LENA, self::MIA]);
        $this->migrator()->executeStatement('DELETE FROM idn_user WHERE id IN (?, ?)', [self::LENA, self::MIA]);
        $this->migrator()->executeStatement(
            "DELETE FROM messenger_outbox WHERE queue_name = 'outbox' AND (body LIKE ? OR body LIKE ?)",
            ['%'.self::ACME_FOODS.'%', '%'.self::BETA_BAKERY.'%'],
        );
    }

    private function useCases(): HandWiredOAuthConnections
    {
        return new HandWiredOAuthConnections(self::getContainer());
    }

    private function refreshTokens(): OAuthRefreshTokens
    {
        $refreshTokens = self::getContainer()->get('test.identity_oauth_refresh_tokens');
        self::assertInstanceOf(DoctrineOAuthRefreshTokens::class, $refreshTokens);

        return $refreshTokens;
    }

    private function apiTokens(): ApiTokens
    {
        $apiTokens = self::getContainer()->get('test.identity_api_tokens');
        self::assertInstanceOf(DoctrineApiTokens::class, $apiTokens);

        return $apiTokens;
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), $this->tenantContext());
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    private function migrator(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }
}
