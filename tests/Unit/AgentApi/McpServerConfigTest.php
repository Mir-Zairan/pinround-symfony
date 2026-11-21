<?php

declare(strict_types=1);

namespace App\Tests\Unit\AgentApi;

use App\AgentApi\Infrastructure\Mcp\McpServerInstructions;
use App\AgentApi\Infrastructure\Mcp\McpSessionBinding;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\AI\McpBundle\McpBundle;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Yaml\Yaml;

/**
 * The MCP server's prod settings, read from mcp.yaml and cache.yaml (ADR-0021). The tests that run the endpoint cannot
 * see them: DEFAULT_URI is http://localhost there, which the SDK allows on its own without allowed_hosts, and any
 * session store works for a single test kernel, while prod needs the shared, never-evicting state Valkey (ADR-0010).
 */
final class McpServerConfigTest extends TestCase
{
    private const array SERVER_HTTP = ['path' => '/mcp', 'allowed_hosts' => ['%env(key:host:url:DEFAULT_URI)%']];
    private const array SERVER_SESSION = ['store' => 'cache', 'cache_pool' => 'agent_api.mcp_session_cache', 'ttl' => McpSessionBinding::TTL_SECONDS];
    private const array SESSION_CACHE_SERVICE = ['class' => Psr16Cache::class, 'arguments' => ['@cache.mcp_sessions']];
    private const array SESSION_POOL = ['adapter' => 'cache.adapter.redis', 'provider' => 'state_valkey.connection', 'default_lifetime' => 3600];

    #[Test]
    public function theServerAllowsOnlyThePublicHostAndKeepsSessionsInTheSharedPoolInEveryEnvironment(): void
    {
        self::assertTrue(self::isTheProdServer(self::config('packages/mcp.yaml')));
    }

    #[Test]
    public function theServerCheckFailsWithoutAllowedHosts(): void
    {
        $mcp = self::withoutKey(self::config('packages/mcp.yaml'), 'mcp', 'servers', 'pinround', 'http', 'allowed_hosts');

        self::assertFalse(self::isTheProdServer($mcp));
    }

    #[Test]
    public function theServerCheckFailsOnThePerContainerFileSessionStore(): void
    {
        $mcp = self::withValueAt(self::config('packages/mcp.yaml'), ['store' => 'file', 'ttl' => 3600], 'mcp', 'servers', 'pinround', 'session');

        self::assertFalse(self::isTheProdServer($mcp));
    }

    #[Test]
    public function theServerCheckFailsWhenTheSessionCacheWrapsAnotherPool(): void
    {
        $mcp = self::withValueAt(self::config('packages/mcp.yaml'), ['@cache.app'], 'services', 'agent_api.mcp_session_cache', 'arguments');

        self::assertFalse(self::isTheProdServer($mcp));
    }

    #[Test]
    public function theServerCheckFailsWhenAnEnvironmentRedefinesTheServer(): void
    {
        $mcp = self::withValueAt(self::config('packages/mcp.yaml'), ['path' => '/mcp'], 'when@prod', 'mcp', 'servers', 'pinround', 'http');

        self::assertFalse(self::isTheProdServer($mcp));
    }

    #[Test]
    public function theSessionPoolLivesOnTheStateValkeyInEveryEnvironment(): void
    {
        self::assertTrue(self::isTheSharedSessionPool(self::config('packages/cache.yaml')));
    }

    #[Test]
    public function theSessionPoolCheckFailsWithoutTheStateValkeyProvider(): void
    {
        $cache = self::withoutKey(self::config('packages/cache.yaml'), 'framework', 'cache', 'pools', 'cache.mcp_sessions', 'provider');

        self::assertFalse(self::isTheSharedSessionPool($cache));
    }

    #[Test]
    public function theSessionPoolCheckFailsWhenTheTestEnvSwapsItsAdapter(): void
    {
        $cache = self::withValueAt(
            self::config('packages/cache.yaml'),
            ['adapter' => 'test.cache.adapter.array_kept_between_requests'],
            'when@test',
            'framework',
            'cache',
            'pools',
            'cache.mcp_sessions',
        );

        self::assertFalse(self::isTheSharedSessionPool($cache));
    }

    /**
     * The bundle reads the instructions as a string when the container is built, so mcp.yaml must name the constant
     * itself, not a copy of its text that would go stale.
     */
    #[Test]
    public function theServerSendsTheCaptureRuleAsItsInstructionsInEveryEnvironment(): void
    {
        $mcp = Yaml::parseFile(\dirname(__DIR__, 3).'/config/packages/mcp.yaml', Yaml::PARSE_CONSTANT);
        self::assertIsArray($mcp);

        self::assertSame(McpServerInstructions::TEXT, self::at($mcp, 'mcp', 'servers', 'pinround', 'instructions'));
        self::assertStringContainsString('Upload nothing to pinround until the person asks you to.', McpServerInstructions::TEXT);
        self::assertSame([], array_filter(array_keys($mcp), static fn (string|int $name): bool => str_starts_with((string) $name, 'when@')));
    }

    #[Test]
    public function theBundleRunsInEveryEnvironmentAndServesTheServersRoutes(): void
    {
        $bundles = require \dirname(__DIR__, 3).'/config/bundles.php';
        self::assertIsArray($bundles);

        self::assertSame(['all' => true], $bundles[McpBundle::class] ?? null);
        self::assertSame(['mcp' => ['resource' => '.', 'type' => 'mcp']], self::config('routes/mcp.yaml'));
    }

    /**
     * @param array<mixed> $mcp
     */
    private static function isTheProdServer(array $mcp): bool
    {
        return ['mcp', 'services'] === array_keys($mcp)
            && ['pinround'] === array_keys((array) self::at($mcp, 'mcp', 'servers'))
            && self::SERVER_HTTP === self::at($mcp, 'mcp', 'servers', 'pinround', 'http')
            && self::SERVER_SESSION === self::at($mcp, 'mcp', 'servers', 'pinround', 'session')
            && ['agent_api.mcp_session_cache' => self::SESSION_CACHE_SERVICE] === self::at($mcp, 'services');
    }

    /**
     * @param array<mixed> $cache
     */
    private static function isTheSharedSessionPool(array $cache): bool
    {
        if (self::SESSION_POOL !== self::at($cache, 'framework', 'cache', 'pools', 'cache.mcp_sessions')) {
            return false;
        }

        return array_all($cache, static fn ($environment, $name) => !(str_starts_with((string) $name, 'when@') && \is_array($environment) && null !== self::at($environment, 'framework', 'cache', 'pools', 'cache.mcp_sessions')));
    }

    /**
     * The value at the path of keys, or null when a key is missing.
     *
     * @param array<mixed> $config
     */
    private static function at(array $config, string ...$keys): mixed
    {
        $value = $config;
        foreach ($keys as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * @param array<mixed> $config
     *
     * @return array<mixed> a copy of the config with the value set at the path, missing levels added
     */
    private static function withValueAt(array $config, mixed $value, string $key, string ...$deeperKeys): array
    {
        if ([] === $deeperKeys) {
            $config[$key] = $value;

            return $config;
        }
        $child = $config[$key] ?? [];
        self::assertIsArray($child);
        $config[$key] = self::withValueAt($child, $value, ...$deeperKeys);

        return $config;
    }

    /**
     * @param array<mixed> $config
     *
     * @return array<mixed> a copy of the config without the key at the path, which must exist
     */
    private static function withoutKey(array $config, string $key, string ...$deeperKeys): array
    {
        self::assertArrayHasKey($key, $config);
        if ([] === $deeperKeys) {
            unset($config[$key]);

            return $config;
        }
        $child = $config[$key];
        self::assertIsArray($child);
        $config[$key] = self::withoutKey($child, ...$deeperKeys);

        return $config;
    }

    /**
     * @return array<mixed>
     */
    private static function config(string $file): array
    {
        $config = Yaml::parseFile(\dirname(__DIR__, 3).'/config/'.$file);
        self::assertIsArray($config);

        return $config;
    }
}
