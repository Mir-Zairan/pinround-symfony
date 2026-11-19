<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Api\Event\ApiTokenRevoked;
use App\Identity\Api\OrganizationApiToken;
use App\Identity\Api\OrganizationApiTokens;
use App\Identity\Application\ApiTokensOfOrganization;
use App\Identity\Application\RevokeApiToken;
use App\Identity\Domain\ApiToken;
use App\Identity\Domain\ApiTokenScope;
use App\Identity\Infrastructure\Security\HmacApiTokenHashing;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The Admin's view of the organization's tokens: every person's live ones, the soonest to expire first, a page at a
 * time, and the Admin's revoke of any of them.
 */
final class ApiTokensOfOrganizationTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000000aa1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-000000000ab1';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000000ad1';
    private const string LENA = '0197a3c0-0000-7000-8000-000000000ad2';
    private const string SARA = '0197a3c0-0000-7000-8000-000000000ad3';
    private const string OMAR_SOON = '0197a3c0-0000-7000-8000-000000000ae5';
    private const string LENA_TIED_HIGH = '0197a3c0-0000-7000-8000-000000000ae4';
    private const string OMAR_TIED_LOW = '0197a3c0-0000-7000-8000-000000000ae2';
    private const string LENA_LATE = '0197a3c0-0000-7000-8000-000000000ae1';
    private const string OMAR_REVOKED = '0197a3c0-0000-7000-8000-000000000ae6';
    private const string LENA_EXPIRED = '0197a3c0-0000-7000-8000-000000000ae7';
    private const string OMAR_AT_BETA = '0197a3c0-0000-7000-8000-000000000ae8';
    private const string PEPPER = 'unit-test-pepper-of-at-least-32-bytes';
    private const string NOW = '2027-03-15 10:00:00 UTC';

    private InMemoryApiTokens $tokens;
    private RecordingIdentityEvents $events;
    private ApiTokensOfOrganization $organizationTokens;

    /**
     * Seeded out of order; the two tied ones expire in the same second, and the one with the lower id must come first.
     */
    protected function setUp(): void
    {
        $this->tokens = new InMemoryApiTokens(new OrganizationId(self::ACME_FOODS), new \DateTimeImmutable(self::NOW));
        $this->seed(self::LENA_LATE, self::ACME_FOODS, self::LENA, '2027-03-10 10:00:00 UTC', 30, 'Laptop of Lena');
        $this->seed(self::LENA_TIED_HIGH, self::ACME_FOODS, self::LENA, '2027-03-10 10:00:00 UTC', 20);
        $this->seed(self::OMAR_SOON, self::ACME_FOODS, self::OMAR, '2027-03-10 10:00:00 UTC', 10);
        $this->seed(self::OMAR_TIED_LOW, self::ACME_FOODS, self::OMAR, '2027-03-10 10:00:00.600 UTC', 20);
        $this->seed(self::OMAR_REVOKED, self::ACME_FOODS, self::OMAR, '2027-03-10 10:00:00 UTC', 25, revokedAt: '2027-03-10 11:00:00 UTC');
        $this->seed(self::LENA_EXPIRED, self::ACME_FOODS, self::LENA, '2027-03-10 10:00:00 UTC', 5);
        $this->seed(self::OMAR_AT_BETA, self::BETA_BAKERY, self::OMAR, '2027-03-10 10:00:00 UTC', 40);
        $this->events = new RecordingIdentityEvents();
        $this->organizationTokens = new ApiTokensOfOrganization($this->tokens, new RevokeApiToken($this->tokens, $this->events));
    }

    #[Test]
    public function theLiveTokensOfEveryPersonAreListedSoonestToExpireFirstAndTheLowerIdFirstOnATie(): void
    {
        $page = $this->organizationTokens->livePage(new \DateTimeImmutable(self::NOW), OrganizationApiTokens::MAX_PAGE_SIZE);

        self::assertSame([self::OMAR_SOON, self::OMAR_TIED_LOW, self::LENA_TIED_HIGH, self::LENA_LATE], self::idsOf($page));
        self::assertEquals(
            new OrganizationApiToken(self::LENA_LATE, new UserId(self::LENA), 'Laptop of Lena', $page[3]->prefix, new \DateTimeImmutable('2027-04-09 10:00:00 UTC')),
            $page[3],
        );
        self::assertStringStartsWith(ApiToken::PLAIN_PREFIX, $page[3]->prefix);
        self::assertSame(\strlen(ApiToken::PLAIN_PREFIX) + ApiToken::DISPLAY_PREFIX_LENGTH, \strlen($page[3]->prefix));
    }

    /**
     * The page ends between the two tied tokens, so only the id in the cursor tells the next page where to start.
     */
    #[Test]
    public function theNextPageStartsAfterTheLastTokenShownEvenOnATie(): void
    {
        $now = new \DateTimeImmutable(self::NOW);
        $firstPage = $this->organizationTokens->livePage($now, 2);
        $secondPage = $this->organizationTokens->livePage($now, 2, $firstPage[1]->expiresAt, $firstPage[1]->id);
        $lastPage = $this->organizationTokens->livePage($now, 2, $secondPage[1]->expiresAt, $secondPage[1]->id);

        self::assertSame([self::OMAR_SOON, self::OMAR_TIED_LOW], self::idsOf($firstPage));
        self::assertSame([self::LENA_TIED_HIGH, self::LENA_LATE], self::idsOf($secondPage));
        self::assertSame([], $lastPage);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function pageSizesOutOfBounds(): iterable
    {
        yield 'none' => [0];
        yield 'negative' => [-1];
        yield 'one past the cap' => [OrganizationApiTokens::MAX_PAGE_SIZE + 1];
    }

    #[Test]
    #[DataProvider('pageSizesOutOfBounds')]
    public function aPageSizeOutsideOneToTheCapIsRefused(int $limit): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A page of tokens holds 1 to 100.');

        $this->organizationTokens->livePage(new \DateTimeImmutable(self::NOW), $limit);
    }

    #[Test]
    public function anAdminsRevokeTakesTheTokenOffTheListAndNamesTheAdminAsActor(): void
    {
        self::assertTrue($this->organizationTokens->revokeAsAdmin(new UserId(self::SARA), self::LENA_LATE, new \DateTimeImmutable(self::NOW)));
        self::assertFalse($this->organizationTokens->revokeAsAdmin(new UserId(self::SARA), self::LENA_LATE, new \DateTimeImmutable(self::NOW)));

        self::assertSame(
            [self::OMAR_SOON, self::OMAR_TIED_LOW, self::LENA_TIED_HIGH],
            self::idsOf($this->organizationTokens->livePage(new \DateTimeImmutable(self::NOW), OrganizationApiTokens::MAX_PAGE_SIZE)),
        );
        self::assertEquals(
            [new ApiTokenRevoked(self::ACME_FOODS, self::LENA_LATE, self::LENA, self::SARA, new \DateTimeImmutable(self::NOW))],
            $this->events->publishedEvents,
        );
    }

    /**
     * @param list<OrganizationApiToken> $page
     *
     * @return list<string>
     */
    private static function idsOf(array $page): array
    {
        return array_map(static fn (OrganizationApiToken $token): string => $token->id, $page);
    }

    private function seed(string $id, string $organizationId, string $userId, string $issuedAt, int $lifetimeDays, string $name = 'Claude Code', ?string $revokedAt = null): void
    {
        $token = ApiToken::issue($id, new OrganizationId($organizationId), new UserId($userId), $name, ApiTokenScope::claudeCodeDefaults(), new HmacApiTokenHashing(self::PEPPER), new \DateTimeImmutable($issuedAt), $lifetimeDays)->token();
        $this->tokens->seed($token, null === $revokedAt ? null : new \DateTimeImmutable($revokedAt));
    }
}
