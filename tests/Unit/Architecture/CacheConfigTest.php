<?php

declare(strict_types=1);

namespace App\Tests\Unit\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The prod settings of the workflow cache pool, read from cache.yaml: the test env swaps the adapter, so the tests
 * that run the cache cannot see them. valkey-cache evicts by allkeys-lru (ADR-0010), which RedisTagAwareAdapter
 * (cache.adapter.redis_tag_aware) does not support, hence tags: true on the plain Redis adapter.
 */
final class CacheConfigTest extends TestCase
{
    private const array WORKFLOW_POOL = ['adapter' => 'cache.adapter.redis', 'tags' => true, 'default_lifetime' => 86400];

    #[Test]
    public function theWorkflowPoolUsesTheTaggedRedisAdapterForADayInProd(): void
    {
        self::assertTrue(self::isTheProdWorkflowPool(self::workflowPool()));
    }

    #[Test]
    public function theCheckFailsOnTheTagAwareAdapter(): void
    {
        self::assertFalse(self::isTheProdWorkflowPool([...self::workflowPool(), 'adapter' => 'cache.adapter.redis_tag_aware']));
    }

    #[Test]
    public function theCheckFailsOnAnotherLifetime(): void
    {
        self::assertFalse(self::isTheProdWorkflowPool([...self::workflowPool(), 'default_lifetime' => 0]));
    }

    #[Test]
    public function theCheckFailsWithoutTags(): void
    {
        self::assertFalse(self::isTheProdWorkflowPool(array_diff_key(self::workflowPool(), ['tags' => true])));
    }

    /**
     * @param array<mixed> $pool
     */
    private static function isTheProdWorkflowPool(array $pool): bool
    {
        return self::WORKFLOW_POOL === $pool;
    }

    /**
     * @return array<mixed> the pool as the base (prod) config declares it
     */
    private static function workflowPool(): array
    {
        $node = Yaml::parseFile(\dirname(__DIR__, 3).'/config/packages/cache.yaml');
        foreach (['framework', 'cache', 'pools', 'cache.workflow'] as $key) {
            self::assertIsArray($node);
            self::assertArrayHasKey($key, $node);
            $node = $node[$key];
        }
        self::assertIsArray($node);

        return $node;
    }
}
