<?php

declare(strict_types=1);

namespace App\Tests\Unit\AgentApi\Infrastructure\Mcp;

use App\AgentApi\Infrastructure\Mcp\ToolCallLimiter;
use PHPUnit\Framework\Assert;
use Psr\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\StorageInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * A ToolCallLimiter with its three limiters as rate_limiter.yaml defines them (pinned in LoginConfigTest), on the
 * given counter storage instead of the state Valkey.
 */
final class ConfiguredToolCallLimiter
{
    public static function on(StorageInterface $storage, ClockInterface $clock): ToolCallLimiter
    {
        return new ToolCallLimiter(
            self::limiter('mcp_calls_per_token_minute', $storage),
            self::limiter('mcp_calls_per_token_hour', $storage),
            self::limiter('mcp_calls_per_organization_minute', $storage),
            $clock,
        );
    }

    private static function limiter(string $name, StorageInterface $storage): RateLimiterFactory
    {
        $section = Yaml::parseFile(\dirname(__DIR__, 5).'/config/packages/rate_limiter.yaml');
        foreach (['framework', 'rate_limiter', $name] as $key) {
            Assert::assertIsArray($section);
            Assert::assertArrayHasKey($key, $section);
            $section = $section[$key];
        }
        Assert::assertIsArray($section);

        $options = [];
        foreach ($section as $option => $value) {
            $options[(string) $option] = $value;
        }
        unset($options['storage_service'], $options['lock_factory']);

        return new RateLimiterFactory(['id' => $name, ...$options], $storage, new LockFactory(new InMemoryStore()));
    }
}
