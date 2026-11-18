<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Api\Event\ApiTokenRevoked;
use App\Identity\Application\ApiTokenNotFound;
use App\Identity\Application\RevokeApiToken;
use App\Identity\Domain\ApiToken;
use App\Identity\Domain\ApiTokenScope;
use App\Identity\Infrastructure\Security\HmacApiTokenHashing;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Which revokes publish ApiTokenRevoked: only the one that revoked the token, never a second click or a refusal.
 */
final class RevokeApiTokenTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000000fa1';
    private const string OMARS_TOKEN = '0197a3c0-0000-7000-8000-000000000fc1';
    private const string LENAS_TOKEN = '0197a3c0-0000-7000-8000-000000000fc2';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000000fd1';
    private const string LENA = '0197a3c0-0000-7000-8000-000000000fd2';
    private const string PEPPER = 'unit-test-pepper-of-at-least-32-bytes';
    private const string NOW = '2027-03-15 10:00:00 UTC';

    private InMemoryApiTokens $tokens;
    private RecordingIdentityEvents $events;

    protected function setUp(): void
    {
        $this->tokens = new InMemoryApiTokens(new OrganizationId(self::ACME_FOODS), new \DateTimeImmutable('2027-01-01 00:00:00 UTC'));
        $this->tokens->seed($this->tokenOf(self::OMARS_TOKEN, self::OMAR));
        $this->tokens->seed($this->tokenOf(self::LENAS_TOKEN, self::LENA));
        $this->events = new RecordingIdentityEvents();
    }

    #[Test]
    public function revokingOwnTokenPublishesApiTokenRevokedWithTheOwnerAsActor(): void
    {
        self::assertTrue($this->revokeAsOmar(self::OMARS_TOKEN));

        self::assertEquals(
            [new ApiTokenRevoked(self::ACME_FOODS, self::OMARS_TOKEN, self::OMAR, self::OMAR, new \DateTimeImmutable(self::NOW))],
            $this->events->publishedEvents,
        );
        self::assertSame(0, $this->liveCountOf(self::OMAR));
    }

    #[Test]
    public function aSecondRevokeOfTheSameTokenPublishesNothingMore(): void
    {
        $this->revokeAsOmar(self::OMARS_TOKEN);

        self::assertFalse($this->revokeAsOmar(self::OMARS_TOKEN));

        self::assertCount(1, $this->events->publishedEvents);
    }

    #[Test]
    public function anotherPersonsTokenIsNotFoundStaysLiveAndPublishesNothing(): void
    {
        try {
            $this->revokeAsOmar(self::LENAS_TOKEN);
            self::fail('Omar revoked Lena\'s token.');
        } catch (ApiTokenNotFound) {
        }

        self::assertSame([], $this->events->publishedEvents);
        self::assertSame(1, $this->liveCountOf(self::LENA));
    }

    private function revokeAsOmar(string $tokenId): bool
    {
        return new RevokeApiToken($this->tokens, $this->events)(new UserId(self::OMAR), $tokenId, new \DateTimeImmutable(self::NOW));
    }

    private function liveCountOf(string $userId): int
    {
        return \count($this->tokens->liveForUser(new UserId($userId), new \DateTimeImmutable(self::NOW)));
    }

    private function tokenOf(string $tokenId, string $userId): ApiToken
    {
        return ApiToken::issue($tokenId, new OrganizationId(self::ACME_FOODS), new UserId($userId), 'Claude Code', ApiTokenScope::claudeCodeDefaults(), new HmacApiTokenHashing(self::PEPPER), new \DateTimeImmutable('2027-03-14 10:00:00 UTC'))->token();
    }
}
