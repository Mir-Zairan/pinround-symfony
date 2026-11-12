<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Infrastructure\Cache;

use App\Organization\Api\PossibleAssignee;
use App\Organization\Infrastructure\Cache\TeamPeopleCache;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * Each load names Omar "Omar" plus how many loads ran, so a read that should have come from the cache and a fresh
 * one give different first names.
 */
final class TeamPeopleCacheTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000002201';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-000000002202';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-000000002211';
    private const string MOBILE_TEAM = '0197a3c0-0000-7000-8000-000000002212';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000002221';
    private const string MIA = '0197a3c0-0000-7000-8000-000000002222';

    private TagAwareAdapter $pool;

    private TeamPeopleCache $cache;

    private int $loads = 0;

    protected function setUp(): void
    {
        $this->pool = new TagAwareAdapter(new ArrayAdapter());
        $this->cache = new TeamPeopleCache($this->pool, new NullLogger());
    }

    #[Test]
    public function underATenantTheTeamIsKeyedByOrganizationAndTeamAndLoadedOnce(): void
    {
        $this->cache->transactionBegan(new OrganizationId(self::ACME_FOODS));

        self::assertSame(['Omar1'], $this->firstNamesOfShop());
        self::assertSame(['Omar1'], $this->firstNamesOfShop());
        self::assertTrue($this->pool->hasItem($this->shopKey()));
        self::assertFalse($this->pool->hasItem('org.'.self::BETA_BAKERY.'.people.team.'.self::SHOP_TEAM));
    }

    #[Test]
    public function theEntryIsDroppedByItsTeamsTagOrItsPeoplesTagsAndNoOther(): void
    {
        $this->cache->transactionBegan(new OrganizationId(self::ACME_FOODS));
        $this->firstNamesOfShop();

        $this->pool->invalidateTags(['people-team-'.self::MOBILE_TEAM, 'people-user-'.self::MIA]);
        self::assertTrue($this->pool->hasItem($this->shopKey()), 'Another team\'s or person\'s tag keeps the entry.');

        $this->pool->invalidateTags(['people-team-'.self::SHOP_TEAM]);
        self::assertFalse($this->pool->hasItem($this->shopKey()));

        $this->firstNamesOfShop();
        $this->pool->invalidateTags(['people-user-'.self::OMAR]);
        self::assertFalse($this->pool->hasItem($this->shopKey()));
    }

    #[Test]
    public function withNoTransactionOrNoTenantEveryReadLoadsAndNothingIsKept(): void
    {
        self::assertSame(['Omar1'], $this->firstNamesOfShop());
        self::assertSame(['Omar2'], $this->firstNamesOfShop());

        $this->cache->transactionBegan(null);
        self::assertSame(['Omar3'], $this->firstNamesOfShop());
        self::assertSame(['Omar4'], $this->firstNamesOfShop());
        self::assertFalse($this->pool->hasItem($this->shopKey()));
    }

    #[Test]
    public function aTeamChangeLoadsAfreshUntilItsCommitAndIsDroppedAtTheCommit(): void
    {
        $this->cache->transactionBegan(new OrganizationId(self::ACME_FOODS));
        $this->firstNamesOfShop();

        $this->cache->invalidateTeamAfterCommit(new TeamId(self::SHOP_TEAM));
        self::assertSame(['Omar2'], $this->firstNamesOfShop());
        self::assertSame(['Omar3'], $this->firstNamesOfShop(), 'An uncommitted change is never kept.');
        self::assertSame(['Omar1'], $this->cachedFirstNamesOfShop(), 'The entry stays until the commit.');

        $this->cache->transactionCommitted();
        self::assertNull($this->cachedFirstNamesOfShop());
        $this->cache->transactionBegan(new OrganizationId(self::ACME_FOODS));
        self::assertSame(['Omar4'], $this->firstNamesOfShop());
        self::assertSame(['Omar4'], $this->firstNamesOfShop());
    }

    /**
     * A name change comes from the profile page, whose transaction has no tenant; it still clears Omar's teams.
     */
    #[Test]
    public function aNewNameDropsTheTeamsOfThatPersonAtTheCommitOfATransactionWithNoTenant(): void
    {
        $this->cache->transactionBegan(new OrganizationId(self::ACME_FOODS));
        $this->firstNamesOfShop();
        $this->cache->transactionCommitted();

        $this->cache->transactionBegan(null);
        $this->cache->invalidatePersonAfterCommit(new UserId(self::OMAR));
        self::assertSame(['Omar1'], $this->cachedFirstNamesOfShop());
        $this->cache->transactionCommitted();

        self::assertNull($this->cachedFirstNamesOfShop());
    }

    #[Test]
    public function aRolledBackChangeInvalidatesNothing(): void
    {
        $this->cache->transactionBegan(new OrganizationId(self::ACME_FOODS));
        $this->firstNamesOfShop();
        $this->cache->invalidateTeamAfterCommit(new TeamId(self::SHOP_TEAM));
        $this->cache->invalidatePersonAfterCommit(new UserId(self::OMAR));
        $this->cache->transactionEnded();

        self::assertSame(['Omar1'], $this->cachedFirstNamesOfShop());
        $this->cache->transactionBegan(new OrganizationId(self::ACME_FOODS));
        self::assertSame(['Omar1'], $this->firstNamesOfShop());
    }

    #[Test]
    public function aNewTransactionForgetsTheChangesOfOneThatNeverEnded(): void
    {
        $this->cache->transactionBegan(new OrganizationId(self::ACME_FOODS));
        $this->firstNamesOfShop();
        $this->cache->invalidateTeamAfterCommit(new TeamId(self::SHOP_TEAM));

        $this->cache->transactionBegan(new OrganizationId(self::ACME_FOODS));
        $this->cache->transactionCommitted();

        self::assertSame(['Omar1'], $this->cachedFirstNamesOfShop());
    }

    #[Test]
    public function resetEndsTheTransactionSoTheNextChangeIsInvalidatedAtOnce(): void
    {
        $this->cache->transactionBegan(new OrganizationId(self::ACME_FOODS));
        $this->firstNamesOfShop();
        $this->cache->invalidateTeamAfterCommit(new TeamId(self::MOBILE_TEAM));

        $this->cache->reset();

        self::assertSame(['Omar2'], $this->firstNamesOfShop(), 'With no transaction a read loads and keeps nothing.');
        $this->cache->invalidateTeamAfterCommit(new TeamId(self::SHOP_TEAM));
        self::assertNull($this->cachedFirstNamesOfShop());
    }

    #[Test]
    public function aPoolThatFailsToInvalidateAtCommitLogsOnlyTheFailuresClass(): void
    {
        $records = [];
        $logger = new class(static function (string $message, array $context) use (&$records): void {
            $records[] = [$message, $context];
        }) extends AbstractLogger {
            /** @param \Closure(string, array<mixed>): void $onLog */
            public function __construct(private readonly \Closure $onLog)
            {
            }

            public function log(mixed $level, string|\Stringable $message, array $context = []): void
            {
                ($this->onLog)((string) $message, $context);
            }
        };
        $cache = new TeamPeopleCache($this->failingPool(), $logger);
        $cache->transactionBegan(new OrganizationId(self::ACME_FOODS));
        $cache->invalidateTeamAfterCommit(new TeamId(self::SHOP_TEAM));

        $cache->transactionCommitted();

        self::assertSame([['The team people cache kept entries of a committed change.', ['exception_class' => \RuntimeException::class]]], $records);
    }

    #[Test]
    public function aPoolThatFailsToInvalidateNowThrowsSoTheHandlerIsRetried(): void
    {
        $cache = new TeamPeopleCache($this->failingPool(), new NullLogger());

        $this->expectException(\RuntimeException::class);

        $cache->invalidateTeamNow(new TeamId(self::SHOP_TEAM));
    }

    /**
     * @return list<string>
     */
    private function firstNamesOfShop(): array
    {
        $people = $this->cache->remember(new TeamId(self::SHOP_TEAM), fn (): array => [new PossibleAssignee(new UserId(self::OMAR), 'Omar'.++$this->loads)]);

        return array_map(static fn (PossibleAssignee $person): string => $person->firstName, $people);
    }

    /**
     * @return list<string>|null null when the pool holds no entry for Shop
     */
    private function cachedFirstNamesOfShop(): ?array
    {
        $item = $this->pool->getItem($this->shopKey());
        if (!$item->isHit()) {
            return null;
        }

        /** @var list<PossibleAssignee> $people */
        $people = $item->get();

        return array_map(static fn (PossibleAssignee $person): string => $person->firstName, $people);
    }

    private function shopKey(): string
    {
        return 'org.'.self::ACME_FOODS.'.people.team.'.self::SHOP_TEAM;
    }

    private function failingPool(): TagAwareCacheInterface
    {
        return new class implements TagAwareCacheInterface {
            /** @param array<mixed>|null $metadata */
            public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed
            {
                throw new \LogicException('Not read in this test.');
            }

            public function delete(string $key): bool
            {
                return false;
            }

            public function invalidateTags(array $tags): bool
            {
                return false;
            }
        };
    }
}
