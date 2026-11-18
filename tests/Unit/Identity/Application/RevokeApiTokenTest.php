<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Api\Event\ApiTokenRevoked;
use App\Identity\Application\ApiTokenNotFound;
use App\Identity\Application\RevokeApiToken;
use App\Identity\Domain\ApiToken;
use App\Identity\Domain\ApiTokenScope;
use App\Identity\Infrastructure\Security\HmacApiTokenHashing;
use App\SharedKernel\Application\TenantNotSet;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Who may revoke which token: the owner only, in the tenant only, and a refusal never tells whether the token exists.
 * Only the revoke that revoked the token publishes ApiTokenRevoked, never a second click or a refusal.
 */
final class RevokeApiTokenTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000000fa1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-000000000fb1';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000000fd1';
    private const string LENA = '0197a3c0-0000-7000-8000-000000000fd2';
    private const string OMARS_TOKEN = '0197a3c0-0000-7000-8000-000000000fe1';
    private const string LENAS_TOKEN = '0197a3c0-0000-7000-8000-000000000fe2';
    private const string OMARS_TOKEN_AT_BETA = '0197a3c0-0000-7000-8000-000000000fe3';
    private const string OMARS_EXPIRED_TOKEN = '0197a3c0-0000-7000-8000-000000000fe4';
    private const string PEPPER = 'unit-test-pepper-of-at-least-32-bytes';
    private const string ISSUED_AT = '2027-03-01 10:00:00 UTC';
    private const string NOW = '2027-03-15 10:00:00 UTC';

    private InMemoryApiTokens $tokens;
    private RecordingIdentityEvents $events;

    protected function setUp(): void
    {
        $this->tokens = new InMemoryApiTokens(new OrganizationId(self::ACME_FOODS), new \DateTimeImmutable(self::NOW));
        $this->tokens->seed(self::token(self::OMARS_TOKEN, self::ACME_FOODS, self::OMAR));
        $this->tokens->seed(self::token(self::LENAS_TOKEN, self::ACME_FOODS, self::LENA));
        $this->tokens->seed(self::token(self::OMARS_TOKEN_AT_BETA, self::BETA_BAKERY, self::OMAR));
        $this->tokens->seed(self::token(self::OMARS_EXPIRED_TOKEN, self::ACME_FOODS, self::OMAR, 1));
        $this->events = new RecordingIdentityEvents();
    }

    #[Test]
    public function theOwnerRevokesTheirTokenAndOnlyThatOne(): void
    {
        $isRevokedNow = $this->revokeAsOmar(self::OMARS_TOKEN);

        self::assertTrue($isRevokedNow);
        self::assertSame([self::LENAS_TOKEN], $this->liveIdsOf(self::LENA));
        self::assertSame([], $this->liveIdsOf(self::OMAR));
        self::assertNotNull($this->tokens->byIdForOwner(self::OMARS_TOKEN, new UserId(self::OMAR)), 'the revoke removed the token instead');
    }

    #[Test]
    public function revokingOwnTokenPublishesApiTokenRevokedWithTheOwnerAsActor(): void
    {
        self::assertTrue($this->revokeAsOmar(self::OMARS_TOKEN));

        self::assertEquals(
            [new ApiTokenRevoked(self::ACME_FOODS, self::OMARS_TOKEN, self::OMAR, self::OMAR, new \DateTimeImmutable(self::NOW))],
            $this->events->publishedEvents,
        );
    }

    /**
     * As a second click on the button sends it again.
     */
    #[Test]
    public function aTokenRevokedAlreadyIsFoundButNotRevokedAgainNorPublishedAgain(): void
    {
        self::assertTrue($this->revokeAsOmar(self::OMARS_TOKEN));

        self::assertFalse($this->revokeAsOmar(self::OMARS_TOKEN));
        self::assertFalse($this->revokeAsOmar(strtoupper(self::OMARS_TOKEN)));
        self::assertCount(1, $this->events->publishedEvents);
    }

    /**
     * An expired token still is the owner's to revoke; the page no longer lists it.
     */
    #[Test]
    public function anExpiredTokenOfTheOwnerIsRevoked(): void
    {
        self::assertTrue($this->revokeAsOmar(self::OMARS_EXPIRED_TOKEN));
        self::assertFalse($this->revokeAsOmar(self::OMARS_EXPIRED_TOKEN));
        self::assertCount(1, $this->events->publishedEvents);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function idsOfNoTokenOfOmarsInAcmeFoods(): iterable
    {
        yield 'another person\'s' => [self::LENAS_TOKEN];
        yield 'his own in another organization' => [self::OMARS_TOKEN_AT_BETA];
        yield 'unknown' => ['0197a3c0-0000-7000-8000-000000000fff'];
        yield 'malformed' => ["not-a-token-id'"];
        yield 'empty' => [''];
    }

    #[Test]
    #[DataProvider('idsOfNoTokenOfOmarsInAcmeFoods')]
    public function anIdThatIsNotTheOwnersTokenInTheTenantIsRefusedAlikeAndNothingIsRevokedOrPublished(string $tokenId): void
    {
        try {
            $this->revokeAsOmar($tokenId);
            self::fail('A token that is not Omar\'s in Acme Foods was revoked.');
        } catch (ApiTokenNotFound $refusal) {
            self::assertSame('No token of yours has this id.', $refusal->getMessage());
        }

        self::assertSame([self::OMARS_TOKEN], $this->liveIdsOf(self::OMAR));
        self::assertSame([self::LENAS_TOKEN], $this->liveIdsOf(self::LENA));
        self::assertSame([], $this->events->publishedEvents);
    }

    /**
     * The other side of the refusal above: the same id is Omar's to revoke while he works in Beta Bakery.
     */
    #[Test]
    public function theOwnersTokenOfAnotherOrganizationIsRevokedUnderThatOrganization(): void
    {
        $betaBakery = new InMemoryApiTokens(new OrganizationId(self::BETA_BAKERY), new \DateTimeImmutable(self::NOW));
        $betaBakery->seed(self::token(self::OMARS_TOKEN_AT_BETA, self::BETA_BAKERY, self::OMAR));

        self::assertTrue(new RevokeApiToken($betaBakery, $this->events)(new UserId(self::OMAR), self::OMARS_TOKEN_AT_BETA, new \DateTimeImmutable(self::NOW)));
        self::assertSame([], $betaBakery->liveForUser(new UserId(self::OMAR), new \DateTimeImmutable(self::NOW)));
        self::assertEquals(
            [new ApiTokenRevoked(self::BETA_BAKERY, self::OMARS_TOKEN_AT_BETA, self::OMAR, self::OMAR, new \DateTimeImmutable(self::NOW))],
            $this->events->publishedEvents,
        );
    }

    #[Test]
    public function withoutATenantTheRevokeRefusesAndPublishesNothing(): void
    {
        $tokens = new InMemoryApiTokens(null, new \DateTimeImmutable(self::NOW));

        try {
            new RevokeApiToken($tokens, $this->events)(new UserId(self::OMAR), self::OMARS_TOKEN, new \DateTimeImmutable(self::NOW));
            self::fail('A token was revoked without a tenant.');
        } catch (TenantNotSet) {
        }

        self::assertSame([], $this->events->publishedEvents);
    }

    private function revokeAsOmar(string $tokenId): bool
    {
        return new RevokeApiToken($this->tokens, $this->events)(new UserId(self::OMAR), $tokenId, new \DateTimeImmutable(self::NOW));
    }

    /**
     * @return list<string>
     */
    private function liveIdsOf(string $userId): array
    {
        return array_map(static fn (ApiToken $token): string => $token->id(), $this->tokens->liveForUser(new UserId($userId), new \DateTimeImmutable(self::NOW)));
    }

    private static function token(string $id, string $organizationId, string $userId, int $lifetimeDays = ApiToken::DEFAULT_LIFETIME_DAYS): ApiToken
    {
        return ApiToken::issue($id, new OrganizationId($organizationId), new UserId($userId), 'Claude Code', ApiTokenScope::claudeCodeDefaults(), new HmacApiTokenHashing(self::PEPPER), new \DateTimeImmutable(self::ISSUED_AT), $lifetimeDays)->token();
    }
}
