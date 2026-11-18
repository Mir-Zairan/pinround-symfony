<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Infrastructure\Security;

use App\Identity\Api\ApiTokenAccess;
use App\Identity\Domain\ApiToken;
use App\Identity\Domain\ApiTokenScope;
use App\Identity\Domain\Credential;
use App\Identity\Domain\Email;
use App\Identity\Domain\PasswordHash;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Security\ApiTokenAuthentication;
use App\Identity\Infrastructure\Security\HmacApiTokenHashing;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Identity\Infrastructure\Security\SecurityUserProvider;
use App\Identity\Infrastructure\Security\TokenAuthenticator;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantSwitchGuard;
use App\SharedKernel\Application\TenantSwitchRefused;
use App\SharedKernel\Application\Transactions;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Identity\Application\InMemoryApiTokens;
use App\Tests\Unit\Identity\Application\InMemoryCredentials;
use App\Tests\Unit\Identity\Application\InMemoryUsers;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;

/**
 * The authenticator around the database: InMemoryApiTokens under Acme Foods as tenant, a TenantContext whose switch
 * guard refuses while the entity manager holds entities, as DoctrineTenantSwitchGuard does, and Transactions that count
 * their runs. TokenAuthenticatorTest in Functional runs it against PostgreSQL.
 */
final class TokenAuthenticatorTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000007a1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000007b1';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000007c2';
    private const string TOKEN_ID = '0197a3c0-0000-7000-8000-0000000007f1';
    private const string PEPPER = 'unit-test-pepper-of-at-least-32-bytes';
    /** Not the wall clock's time, so code that reads the wall clock decides differently. */
    private const string NOW = '2099-03-20 09:00:00 UTC';

    private TenantContext $tenantContext;
    private bool $doesEntityManagerHoldEntities = false;
    private int $transactionRuns = 0;

    protected function setUp(): void
    {
        $this->tenantContext = new TenantContext(new readonly class($this->doesEntityManagerHoldEntities(...)) implements TenantSwitchGuard {
            /**
             * @param \Closure(): bool $doesEntityManagerHoldEntities
             */
            public function __construct(private \Closure $doesEntityManagerHoldEntities)
            {
            }

            public function prepareSwitch(): void
            {
                if (($this->doesEntityManagerHoldEntities)()) {
                    throw new TenantSwitchRefused();
                }
            }
        });
    }

    #[Test]
    public function aLiveTokenLogsItsUserInUnderTheTokensOrganizationWithItsAccessAndTheUseItLoaded(): void
    {
        $issued = ApiToken::issue(self::TOKEN_ID, new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), 'Laptop', ApiTokenScope::claudeCodeDefaults(), new HmacApiTokenHashing(self::PEPPER), new \DateTimeImmutable('2099-03-01 09:00:00 UTC'));
        $lastUsedAt = new \DateTimeImmutable('2099-03-20 08:59:30 UTC');
        // As Doctrine loads the column: the domain never writes it.
        new \ReflectionProperty(ApiToken::class, 'lastUsedAt')->setValue($issued->token(), $lastUsedAt);
        $apiTokens = new InMemoryApiTokens(new OrganizationId(self::ACME_FOODS), new \DateTimeImmutable(self::NOW));
        $apiTokens->seed($issued->token());
        $authenticator = $this->authenticator($apiTokens);

        $passport = $authenticator->authenticate(self::requestWithBearer($issued->reveal()));
        $token = $authenticator->createToken($passport, 'api_token');

        self::assertInstanceOf(ApiTokenAuthentication::class, $token);
        self::assertSame(self::ACME_FOODS, $this->tenantContext->get()?->toString());
        $user = $token->getUser();
        self::assertInstanceOf(SecurityUser::class, $user);
        self::assertSame(self::OMAR, $user->id());
        self::assertEquals(new ApiTokenAccess(self::TOKEN_ID, new OrganizationId(self::ACME_FOODS), ['read', 'rounds:write'], new \DateTimeImmutable('2099-05-30 09:00:00 UTC')), $token->access());
        self::assertSame('2099-05-30T09:00:00+00:00', $token->access()->expiresAt->format(\DATE_ATOM), 'the access does not carry the token\'s own expiry');
        self::assertEquals($lastUsedAt, $token->lastUsedAt());
    }

    #[Test]
    public function anUnknownTokenIsRefusedBeforeAnyTenantIsSet(): void
    {
        $authenticator = $this->authenticator(new InMemoryApiTokens(new OrganizationId(self::ACME_FOODS), new \DateTimeImmutable(self::NOW)));

        try {
            $authenticator->authenticate(self::requestWithBearer(ApiToken::PLAIN_PREFIX.str_repeat('A', 43)));
            self::fail('An unknown token was let in.');
        } catch (BadCredentialsException) {
            self::assertNull($this->tenantContext->get());
        }
    }

    /**
     * The write is skipped only while the loaded use is less than a minute old; from a minute on, markUsed's own
     * condition decides, so two requests at once still write once.
     */
    #[Test]
    public function aUseIsWrittenUnlessTheLoadedOneIsLessThanAMinuteOld(): void
    {
        $now = new \DateTimeImmutable(self::NOW);
        $lastUses = [
            'never used' => null,
            '59 seconds ago' => $now->modify('-59 seconds'),
            'a minute ago' => $now->modify('-60 seconds'),
            '61 seconds ago' => $now->modify('-61 seconds'),
        ];
        $writes = [];

        foreach ($lastUses as $when => $lastUsedAt) {
            $this->tenantContext->reset();
            $this->tenantContext->set(new OrganizationId(self::ACME_FOODS));
            $this->doesEntityManagerHoldEntities = true;
            $this->transactionRuns = 0;

            $response = $this->authenticator(new InMemoryApiTokens(new OrganizationId(self::ACME_FOODS), $now))
                ->onAuthenticationSuccess(new Request(), self::tokenLoginOfOmar($lastUsedAt), 'api_token');

            self::assertNull($response, $when);
            self::assertFalse($this->doesEntityManagerHoldEntities, $when.': the loaded entities were kept, so the tenant cannot be cleared');
            self::assertSame(self::ACME_FOODS, $this->tenantContext->get()?->toString(), $when);
            $writes[$when] = $this->transactionRunCount();
        }

        self::assertSame(['never used' => 1, '59 seconds ago' => 0, 'a minute ago' => 1, '61 seconds ago' => 1], $writes);
    }

    /**
     * The user checker's refusal of a blocked user comes after authenticate() set the tenant and loaded entities.
     */
    #[Test]
    public function aRefusedLoginGetsThe401WithNoTenantAndNoLoadedEntityLeft(): void
    {
        $this->tenantContext->set(new OrganizationId(self::BETA_BAKERY));
        $this->doesEntityManagerHoldEntities = true;

        $response = $this->authenticator(new InMemoryApiTokens(new OrganizationId(self::BETA_BAKERY), new \DateTimeImmutable(self::NOW)))
            ->onAuthenticationFailure(new Request(), new BadCredentialsException());

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Bearer', $response->headers->get('WWW-Authenticate'));
        self::assertSame(['error' => TokenAuthenticator::REFUSAL_MESSAGE], json_decode((string) $response->getContent(), true));
        self::assertNull($this->tenantContext->get());
        self::assertFalse($this->doesEntityManagerHoldEntities);
    }

    private function authenticator(InMemoryApiTokens $apiTokens): TokenAuthenticator
    {
        $user = self::omar();
        $credentials = new InMemoryCredentials();
        $credentials->add(new Credential($user, new PasswordHash('$argon2id$v=19$m=10,t=3,p=1$stored'), new \DateTimeImmutable('2099-03-01 09:00:00 UTC')));
        $tenantContext = $this->tenantContext;
        $countRun = function (): void {
            ++$this->transactionRuns;
        };
        $transactions = new readonly class($tenantContext, $countRun) implements Transactions {
            /**
             * @param \Closure(): void $countRun
             */
            public function __construct(private TenantContext $tenantContext, private \Closure $countRun)
            {
            }

            public function run(\Closure $write): mixed
            {
                if (null === $this->tenantContext->get()) {
                    throw new \LogicException('A transaction of its own needs the request\'s tenant.');
                }
                ($this->countRun)();

                return $write();
            }
        };
        $entityManager = self::createStub(EntityManagerInterface::class);
        $entityManager->method('clear')->willReturnCallback(function (): void {
            $this->doesEntityManagerHoldEntities = false;
        });

        return new TokenAuthenticator(
            $apiTokens,
            new HmacApiTokenHashing(self::PEPPER),
            new SecurityUserProvider(new InMemoryUsers($user), $credentials),
            $tenantContext,
            $transactions,
            new MockClock(self::NOW),
            $entityManager,
        );
    }

    private function transactionRunCount(): int
    {
        return $this->transactionRuns;
    }

    private function doesEntityManagerHoldEntities(): bool
    {
        return $this->doesEntityManagerHoldEntities;
    }

    private static function requestWithBearer(string $plainToken): Request
    {
        return Request::create('/mcp', 'POST', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$plainToken]);
    }

    private static function tokenLoginOfOmar(?\DateTimeImmutable $lastUsedAt): ApiTokenAuthentication
    {
        $user = self::omar();
        $securityUser = SecurityUser::of($user, new Credential($user, new PasswordHash('$argon2id$v=19$m=10,t=3,p=1$stored'), new \DateTimeImmutable('2099-03-01 09:00:00 UTC')));

        return new ApiTokenAuthentication($securityUser, new ApiTokenAccess(self::TOKEN_ID, new OrganizationId(self::ACME_FOODS), ['read'], new \DateTimeImmutable('2099-05-30 09:00:00 UTC')), $lastUsedAt);
    }

    private static function omar(): User
    {
        return new User(new UserId(self::OMAR), new Email('omar@acme-foods.example'), 'Omar Haddad', 'en', 'Europe/Berlin', new \DateTimeImmutable('2099-03-01 09:00:00 UTC'));
    }
}
