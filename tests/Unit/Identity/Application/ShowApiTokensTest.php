<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Application\ApiTokenView;
use App\Identity\Application\ShowApiTokens;
use App\Identity\Domain\ApiToken;
use App\Identity\Domain\ApiTokenScope;
use App\Identity\Infrastructure\Security\HmacApiTokenHashing;
use App\SharedKernel\Application\TenantNotSet;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What the tokens page lists, on InMemoryApiTokens; DoctrineApiTokensTest proves the same filter and order on
 * PostgreSQL.
 */
final class ShowApiTokensTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000000aa1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-000000000ab1';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000000ad1';
    private const string LENA = '0197a3c0-0000-7000-8000-000000000ad2';
    private const string PEPPER = 'unit-test-pepper-of-at-least-32-bytes';
    private const string NOW = '2027-03-15 10:00:00 UTC';

    #[Test]
    public function onlyThePersonsLiveTokensInTheTenantAreListedNewestFirstWithTheLaterIdFirstInOneSecond(): void
    {
        $tokens = new InMemoryApiTokens(new OrganizationId(self::ACME_FOODS), new \DateTimeImmutable(self::NOW));
        // Seeded out of order, so the list order cannot come from the seed order.
        $tokens->seed(self::token('0197a3c0-0000-7000-8000-000000000ae1', self::ACME_FOODS, self::OMAR, '2027-03-10 09:00:00 UTC'));
        $tokens->seed(self::token('0197a3c0-0000-7000-8000-000000000ae3', self::ACME_FOODS, self::OMAR, '2027-03-12 09:00:00 UTC'));
        $tokens->seed(self::token('0197a3c0-0000-7000-8000-000000000ae2', self::ACME_FOODS, self::OMAR, '2027-03-12 09:00:00 UTC'));
        $tokens->seed(self::token('0197a3c0-0000-7000-8000-000000000ae4', self::ACME_FOODS, self::OMAR, '2027-03-14 09:00:00 UTC'), new \DateTimeImmutable('2027-03-14 10:00:00 UTC'));
        $tokens->seed(self::token('0197a3c0-0000-7000-8000-000000000ae5', self::ACME_FOODS, self::OMAR, '2027-03-14 10:00:00 UTC', 1));
        $tokens->seed(self::token('0197a3c0-0000-7000-8000-000000000ae6', self::ACME_FOODS, self::LENA, '2027-03-13 09:00:00 UTC'));
        $tokens->seed(self::token('0197a3c0-0000-7000-8000-000000000ae7', self::BETA_BAKERY, self::OMAR, '2027-03-13 09:00:00 UTC'));

        $listed = new ShowApiTokens($tokens)->liveOf(new UserId(self::OMAR), new \DateTimeImmutable(self::NOW));

        self::assertSame(
            ['0197a3c0-0000-7000-8000-000000000ae3', '0197a3c0-0000-7000-8000-000000000ae2', '0197a3c0-0000-7000-8000-000000000ae1'],
            array_map(static fn (ApiTokenView $token): string => $token->id, $listed),
        );
    }

    /**
     * The one that expires at NOW is listed until the second before; a fraction of that second still counts as before.
     */
    #[Test]
    public function aTokenIsListedUntilTheSecondItExpires(): void
    {
        $tokens = new InMemoryApiTokens(new OrganizationId(self::ACME_FOODS), new \DateTimeImmutable(self::NOW));
        $tokens->seed(self::token('0197a3c0-0000-7000-8000-000000000ae1', self::ACME_FOODS, self::OMAR, '2027-03-14 10:00:00 UTC', 1));
        $show = new ShowApiTokens($tokens);
        $listedAt = static fn (string $at): int => \count($show->liveOf(new UserId(self::OMAR), new \DateTimeImmutable($at)));

        self::assertSame(
            ['a second before' => 1, 'within the second before' => 1, 'at the second' => 0, 'within the second' => 0],
            [
                'a second before' => $listedAt('2027-03-15 09:59:59 UTC'),
                'within the second before' => $listedAt('2027-03-15 09:59:59.600 UTC'),
                'at the second' => $listedAt(self::NOW),
                'within the second' => $listedAt('2027-03-15 10:00:00.400 UTC'),
            ],
        );
    }

    /**
     * As idn_api_token keeps them (DoctrineApiTokensTest): the times of the two tokens lose their fraction of a second,
     * so they tie on the second and the later id comes first, and the first expires when its second starts.
     */
    #[Test]
    public function tokensIssuedWithinASecondCountFromTheStartOfThatSecond(): void
    {
        $tokens = new InMemoryApiTokens(new OrganizationId(self::ACME_FOODS), new \DateTimeImmutable(self::NOW));
        $tokens->seed(self::token('0197a3c0-0000-7000-8000-000000000ae1', self::ACME_FOODS, self::OMAR, '2027-03-14 10:00:00.700 UTC', 1));
        $tokens->seed(self::token('0197a3c0-0000-7000-8000-000000000ae2', self::ACME_FOODS, self::OMAR, '2027-03-14 10:00:00.200 UTC', 2));
        $show = new ShowApiTokens($tokens);
        $listedAt = static fn (string $at): array => array_map(static fn (ApiTokenView $token): string => $token->id, $show->liveOf(new UserId(self::OMAR), new \DateTimeImmutable($at)));

        self::assertSame(['0197a3c0-0000-7000-8000-000000000ae2', '0197a3c0-0000-7000-8000-000000000ae1'], $listedAt('2027-03-15 09:59:59.900 UTC'));
        self::assertSame(['0197a3c0-0000-7000-8000-000000000ae2'], $listedAt('2027-03-15 10:00:00.300 UTC'));
    }

    #[Test]
    public function aListedTokenShowsWhatThePageNeedsAndNeverItsHash(): void
    {
        $tokens = new InMemoryApiTokens(new OrganizationId(self::ACME_FOODS), new \DateTimeImmutable(self::NOW));
        $issued = ApiToken::issue('0197a3c0-0000-7000-8000-000000000ae1', new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), 'Omar laptop', [ApiTokenScope::TicketsMove, ApiTokenScope::Read], new HmacApiTokenHashing(self::PEPPER), new \DateTimeImmutable('2027-03-10 09:00:00 UTC'), 30);
        $tokens->seed($issued->token());

        $listed = new ShowApiTokens($tokens)->liveOf(new UserId(self::OMAR), new \DateTimeImmutable(self::NOW));

        self::assertCount(1, $listed);
        self::assertEquals(
            new ApiTokenView(
                '0197a3c0-0000-7000-8000-000000000ae1',
                'Omar laptop',
                substr($issued->reveal(), 0, 11),
                ['read', 'tickets:move'],
                new \DateTimeImmutable('2027-03-10 09:00:00 UTC'),
                null,
                new \DateTimeImmutable('2027-04-09 09:00:00 UTC'),
            ),
            $listed[0],
        );
        self::assertStringNotContainsString(substr($issued->reveal(), 11), print_r($listed, true));
    }

    #[Test]
    public function withoutATenantTheListRefuses(): void
    {
        $show = new ShowApiTokens(new InMemoryApiTokens(null, new \DateTimeImmutable(self::NOW)));

        $this->expectException(TenantNotSet::class);

        $show->liveOf(new UserId(self::OMAR), new \DateTimeImmutable(self::NOW));
    }

    #[Test]
    public function theFormOffersEveryScopeAndStartsWithClaudeCodesDefaults(): void
    {
        $show = new ShowApiTokens(new InMemoryApiTokens(null, new \DateTimeImmutable(self::NOW)));

        self::assertSame(['read', 'rounds:write', 'comments:write', 'tickets:move', 'reports:write', 'notebooks:write'], $show->scopeNames());
        self::assertSame(['read', 'rounds:write'], $show->defaultScopeNames());
    }

    private static function token(string $id, string $organizationId, string $userId, string $issuedAt, int $lifetimeDays = ApiToken::DEFAULT_LIFETIME_DAYS): ApiToken
    {
        return ApiToken::issue($id, new OrganizationId($organizationId), new UserId($userId), 'Claude Code', ApiTokenScope::claudeCodeDefaults(), new HmacApiTokenHashing(self::PEPPER), new \DateTimeImmutable($issuedAt), $lifetimeDays)->token();
    }
}
