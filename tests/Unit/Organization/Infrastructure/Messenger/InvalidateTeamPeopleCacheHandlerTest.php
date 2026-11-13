<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Infrastructure\Messenger;

use App\Organization\Api\Event\TeamRoleChanged;
use App\Organization\Api\MemberJob;
use App\Organization\Api\PossibleAssignee;
use App\Organization\Infrastructure\Cache\TeamPeopleCache;
use App\Organization\Infrastructure\Messenger\InvalidateTeamPeopleCacheHandler;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\SharedKernel\Infrastructure\Cache\AfterCommitTagInvalidation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;

/**
 * The relayed event arrives in the worker's own transaction, so the second invalidation cannot wait for a commit.
 */
final class InvalidateTeamPeopleCacheHandlerTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000002301';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-000000002311';
    private const string MOBILE_TEAM = '0197a3c0-0000-7000-8000-000000002312';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000002321';

    #[Test]
    public function theRelayedEventDropsItsTeamAtOnceInsideAnOpenTransactionAndNoOtherTeam(): void
    {
        $pool = new TagAwareAdapter(new ArrayAdapter());
        $invalidation = new AfterCommitTagInvalidation(new NullLogger());
        $cache = new TeamPeopleCache($pool, $invalidation);
        $invalidation->transactionBegan(new OrganizationId(self::ACME_FOODS));
        foreach ([self::SHOP_TEAM, self::MOBILE_TEAM] as $team) {
            $cache->remember(new TeamId($team), static fn (): array => [new PossibleAssignee(new UserId(self::OMAR), 'Omar', 'Omar Demir', MemberJob::Developer, false)]);
        }

        new InvalidateTeamPeopleCacheHandler($cache)(new TeamRoleChanged(self::ACME_FOODS, self::SHOP_TEAM, self::OMAR, 'member', 'viewer', null, new \DateTimeImmutable('2026-03-04 10:00:00 UTC')));

        self::assertSame(
            [false, true],
            [$pool->hasItem('org.'.self::ACME_FOODS.'.people.v2.team.'.self::SHOP_TEAM), $pool->hasItem('org.'.self::ACME_FOODS.'.people.v2.team.'.self::MOBILE_TEAM)],
        );
    }
}
