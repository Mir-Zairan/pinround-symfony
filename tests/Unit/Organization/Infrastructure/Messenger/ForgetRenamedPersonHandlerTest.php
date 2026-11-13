<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Infrastructure\Messenger;

use App\Identity\Api\Event\UserRenamed;
use App\Organization\Api\MemberJob;
use App\Organization\Api\PossibleAssignee;
use App\Organization\Infrastructure\Cache\TeamPeopleCache;
use App\Organization\Infrastructure\Messenger\ForgetRenamedPersonHandler;
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
 * The relayed UserRenamed arrives in the worker's own transaction, after the new name committed, so it cannot wait for
 * a commit of its own.
 */
final class ForgetRenamedPersonHandlerTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000002401';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-000000002402';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-000000002411';
    private const string MOBILE_TEAM = '0197a3c0-0000-7000-8000-000000002412';
    private const string OVENS_TEAM = '0197a3c0-0000-7000-8000-000000002413';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000002421';
    private const string MIA = '0197a3c0-0000-7000-8000-000000002422';

    #[Test]
    public function theRelayedEventDropsAtOnceEveryTeamOfThePersonInEveryOrganizationAndNoOtherTeam(): void
    {
        $pool = new TagAwareAdapter(new ArrayAdapter());
        $invalidation = new AfterCommitTagInvalidation(new NullLogger());
        $cache = new TeamPeopleCache($pool, $invalidation);
        $this->fill($invalidation, $cache, self::ACME_FOODS, [self::SHOP_TEAM => [self::OMAR, self::MIA], self::MOBILE_TEAM => [self::MIA]]);
        $this->fill($invalidation, $cache, self::BETA_BAKERY, [self::OVENS_TEAM => [self::OMAR]]);
        $invalidation->transactionBegan(null);

        new ForgetRenamedPersonHandler($cache)(new UserRenamed(self::OMAR));

        self::assertSame(
            ['shop' => false, 'mobile' => true, 'ovens' => false],
            [
                'shop' => $pool->hasItem('org.'.self::ACME_FOODS.'.people.v2.team.'.self::SHOP_TEAM),
                'mobile' => $pool->hasItem('org.'.self::ACME_FOODS.'.people.v2.team.'.self::MOBILE_TEAM),
                'ovens' => $pool->hasItem('org.'.self::BETA_BAKERY.'.people.v2.team.'.self::OVENS_TEAM),
            ],
        );
    }

    /**
     * @param array<string, list<string>> $peopleByTeam
     */
    private function fill(AfterCommitTagInvalidation $invalidation, TeamPeopleCache $cache, string $organization, array $peopleByTeam): void
    {
        $invalidation->transactionBegan(new OrganizationId($organization));
        foreach ($peopleByTeam as $team => $people) {
            $cache->remember(new TeamId($team), static fn (): array => array_map(static fn (string $person): PossibleAssignee => new PossibleAssignee(new UserId($person), 'Name', 'Name', MemberJob::Developer, false), $people));
        }
        $invalidation->transactionCommitted();
    }
}
