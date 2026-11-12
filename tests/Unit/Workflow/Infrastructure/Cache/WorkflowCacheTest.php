<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow\Infrastructure\Cache;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Infrastructure\Cache\AfterCommitTagInvalidation;
use App\Workflow\Infrastructure\Cache\WorkflowCache;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

final class WorkflowCacheTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000002101';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-000000002102';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-000000002111';
    private const string MOBILE_TEAM = '0197a3c0-0000-7000-8000-000000002112';

    private TagAwareAdapter $pool;

    private AfterCommitTagInvalidation $invalidation;

    private WorkflowCache $cache;

    private int $loads = 0;

    protected function setUp(): void
    {
        $this->pool = new TagAwareAdapter(new ArrayAdapter());
        $this->invalidation = new AfterCommitTagInvalidation(new NullLogger());
        $this->cache = new WorkflowCache($this->pool, $this->invalidation);
    }

    #[Test]
    public function underATenantTheEntryIsKeyedByOrganizationTeamAndEntryAndReadOnce(): void
    {
        $this->invalidation->transactionBegan(new OrganizationId(self::ACME_FOODS));

        self::assertSame('load 1', $this->rememberShop());
        self::assertSame('load 1', $this->rememberShop());
        self::assertTrue($this->pool->hasItem('org.'.self::ACME_FOODS.'.wf.team.'.self::SHOP_TEAM.'.steps'));
    }

    #[Test]
    public function theEntryCarriesTheTeamTagAndTheOrganizationTagAndNoOther(): void
    {
        $this->invalidation->transactionBegan(new OrganizationId(self::ACME_FOODS));
        $key = 'org.'.self::ACME_FOODS.'.wf.team.'.self::SHOP_TEAM.'.steps';

        $this->rememberShop();
        $this->pool->invalidateTags(['wf-team-'.self::MOBILE_TEAM, 'wf-org-'.self::BETA_BAKERY]);
        self::assertTrue($this->pool->hasItem($key), 'Another team\'s or organization\'s tag keeps the entry.');

        $this->pool->invalidateTags(['wf-team-'.self::SHOP_TEAM]);
        self::assertFalse($this->pool->hasItem($key));

        $this->rememberShop();
        $this->pool->invalidateTags(['wf-org-'.self::ACME_FOODS]);
        self::assertFalse($this->pool->hasItem($key));
    }

    #[Test]
    public function withNoTransactionOrNoTenantEveryReadLoadsAndNothingIsKept(): void
    {
        self::assertSame('load 1', $this->rememberShop());
        self::assertSame('load 2', $this->rememberShop());

        $this->invalidation->transactionBegan(null);
        self::assertSame('load 3', $this->rememberShop());
        self::assertSame('load 4', $this->rememberShop());
    }

    #[Test]
    public function whileAChangeWaitsForItsCommitEveryReadLoadsAndTheCommitInvalidatesIt(): void
    {
        $this->invalidation->transactionBegan(new OrganizationId(self::ACME_FOODS));
        $this->rememberShop();

        $this->cache->invalidateAfterCommit(new OrganizationId(self::ACME_FOODS), new TeamId(self::SHOP_TEAM));
        self::assertSame('load 2', $this->rememberShop());
        self::assertSame('load 3', $this->rememberShop(), 'An uncommitted change is never kept.');

        $this->invalidation->transactionCommitted();
        $this->invalidation->transactionBegan(new OrganizationId(self::ACME_FOODS));
        self::assertSame('load 4', $this->rememberShop());
    }

    #[Test]
    public function aChangeOfTheOrganizationsOwnStepsOrRulesInvalidatesEveryTeamOfItAtCommit(): void
    {
        $this->invalidation->transactionBegan(new OrganizationId(self::ACME_FOODS));
        $this->rememberShop();
        $this->cache->invalidateAfterCommit(new OrganizationId(self::ACME_FOODS), null);
        $this->invalidation->transactionCommitted();

        $this->invalidation->transactionBegan(new OrganizationId(self::ACME_FOODS));
        self::assertSame('load 2', $this->rememberShop());
    }

    #[Test]
    public function aRolledBackChangeInvalidatesNothing(): void
    {
        $this->invalidation->transactionBegan(new OrganizationId(self::ACME_FOODS));
        $this->rememberShop();
        $this->cache->invalidateAfterCommit(new OrganizationId(self::ACME_FOODS), new TeamId(self::SHOP_TEAM));
        $this->invalidation->transactionEnded();

        $this->invalidation->transactionBegan(new OrganizationId(self::ACME_FOODS));
        self::assertSame('load 1', $this->rememberShop());
        $this->invalidation->transactionCommitted();
        self::assertSame('load 1', $this->cachedShopValue(), 'The dropped tags are not invalidated by a later commit.');
    }

    #[Test]
    public function aNewTransactionDropsTagsAClosedConnectionLeftWaiting(): void
    {
        $this->invalidation->transactionBegan(new OrganizationId(self::ACME_FOODS));
        $this->rememberShop();
        $this->cache->invalidateAfterCommit(new OrganizationId(self::ACME_FOODS), new TeamId(self::SHOP_TEAM));

        $this->invalidation->transactionBegan(new OrganizationId(self::ACME_FOODS));
        self::assertSame('load 1', $this->rememberShop());
    }

    #[Test]
    public function withNoTransactionOpenAChangeInvalidatesAtOnce(): void
    {
        $this->invalidation->transactionBegan(new OrganizationId(self::ACME_FOODS));
        $this->rememberShop();
        $this->invalidation->transactionEnded();

        $this->cache->invalidateAfterCommit(new OrganizationId(self::ACME_FOODS), new TeamId(self::SHOP_TEAM));

        self::assertNull($this->cachedShopValue());
    }

    #[Test]
    public function invalidateNowDropsTheTeamsEntryInsideATransactionToo(): void
    {
        $this->invalidation->transactionBegan(new OrganizationId(self::ACME_FOODS));
        $this->rememberShop();

        $this->cache->invalidateNow(new OrganizationId(self::ACME_FOODS), new TeamId(self::SHOP_TEAM));

        self::assertNull($this->cachedShopValue());
        self::assertSame('load 2', $this->rememberShop(), 'invalidateNow queues nothing, so the next read is kept again.');
        self::assertSame('load 2', $this->rememberShop());
    }

    #[Test]
    public function resetForgetsTheTenantAndTheWaitingTags(): void
    {
        $this->invalidation->transactionBegan(new OrganizationId(self::ACME_FOODS));
        $this->rememberShop();
        $this->cache->invalidateAfterCommit(new OrganizationId(self::ACME_FOODS), new TeamId(self::SHOP_TEAM));

        $this->invalidation->reset();

        self::assertSame('load 2', $this->rememberShop(), 'With no transaction the read loads.');
        self::assertSame('load 1', $this->cachedShopValue(), 'The reset dropped the waiting tags without invalidating.');
        $this->invalidation->transactionCommitted();
        self::assertSame('load 1', $this->cachedShopValue());
    }

    #[Test]
    public function aPoolThatFailsToInvalidateAtCommitIsLoggedWithoutThrowing(): void
    {
        $messages = [];
        $logger = new class(static function (string $message) use (&$messages): void {
            $messages[] = $message;
        }) extends AbstractLogger {
            /** @param \Closure(string): void $onLog */
            public function __construct(private readonly \Closure $onLog)
            {
            }

            public function log(mixed $level, string|\Stringable $message, array $context = []): void
            {
                ($this->onLog)((string) $message);
            }
        };
        $invalidation = new AfterCommitTagInvalidation($logger);
        $cache = new WorkflowCache($this->failingPool(), $invalidation);
        $invalidation->transactionBegan(new OrganizationId(self::ACME_FOODS));
        $cache->invalidateAfterCommit(new OrganizationId(self::ACME_FOODS), null);

        $invalidation->transactionCommitted();

        self::assertSame(['A cache kept entries of a committed change; its event handler drops them.'], $messages);
    }

    #[Test]
    public function aPoolThatFailsToInvalidateNowThrowsSoTheHandlerIsRetried(): void
    {
        $cache = new WorkflowCache($this->failingPool(), new AfterCommitTagInvalidation(new NullLogger()));

        $this->expectException(\RuntimeException::class);

        $cache->invalidateNow(new OrganizationId(self::ACME_FOODS), new TeamId(self::SHOP_TEAM));
    }

    private function rememberShop(): string
    {
        return $this->cache->remember(new TeamId(self::SHOP_TEAM), 'steps', fn (): string => 'load '.++$this->loads);
    }

    private function cachedShopValue(): mixed
    {
        $item = $this->pool->getItem('org.'.self::ACME_FOODS.'.wf.team.'.self::SHOP_TEAM.'.steps');

        return $item->isHit() ? $item->get() : null;
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
