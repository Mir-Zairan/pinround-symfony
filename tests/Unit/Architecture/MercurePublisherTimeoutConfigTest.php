<?php

declare(strict_types=1);

namespace App\Tests\Unit\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * worker-notify publishes every refresh to the hub over HTTP; on http_client's defaults a stalled hub holds the worker
 * for 60 s per message, so the hub publishes through a client of its own that gives up within the coalescing window.
 */
final class MercurePublisherTimeoutConfigTest extends TestCase
{
    private const string CLIENT_ID = 'mercure.publisher_http_client';
    private const float MAX_WAIT_SECONDS = 2.0;

    #[Test]
    public function theHubPublishesThroughAClientThatGivesUpWithinTwoSeconds(): void
    {
        self::assertSame([], self::problemsIn(self::mercureYaml()));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function brokenConfigs(): iterable
    {
        $mercureYaml = self::mercureYaml();

        yield 'the hub uses the default client' => [
            str_replace("http_client: '".self::CLIENT_ID."'", '', $mercureYaml),
            ['the hub does not publish through mercure.publisher_http_client'],
        ];
        yield 'the client has no idle timeout' => [
            str_replace('{ timeout: 2, max_duration: 2 }', '{ max_duration: 2 }', $mercureYaml),
            ['the publisher client has no timeout of at most 2 s'],
        ];
        yield 'the client waits too long in total' => [
            str_replace('{ timeout: 2, max_duration: 2 }', '{ timeout: 2, max_duration: 60 }', $mercureYaml),
            ['the publisher client has no max_duration of at most 2 s'],
        ];
        yield 'the client is not derived from http_client' => [
            str_replace("factory: ['@http_client', 'withOptions']", "factory: ['@http_client', 'stream']", $mercureYaml),
            ['the publisher client is not http_client with its own options'],
        ];
    }

    /**
     * @param list<string> $expectedProblems
     */
    #[Test]
    #[DataProvider('brokenConfigs')]
    public function aBrokenConfigIsCaught(string $mercureYaml, array $expectedProblems): void
    {
        self::assertSame($expectedProblems, self::problemsIn($mercureYaml));
    }

    /**
     * @return list<string>
     */
    private static function problemsIn(string $mercureYaml): array
    {
        $config = Yaml::parse($mercureYaml);
        $problems = [];
        if (self::CLIENT_ID !== self::valueAt($config, ['mercure', 'hubs', 'default', 'http_client'])) {
            $problems[] = 'the hub does not publish through mercure.publisher_http_client';
        }
        if (['@http_client', 'withOptions'] !== self::valueAt($config, ['services', self::CLIENT_ID, 'factory'])) {
            $problems[] = 'the publisher client is not http_client with its own options';
        }
        foreach (['timeout', 'max_duration'] as $option) {
            $seconds = self::valueAt($config, ['services', self::CLIENT_ID, 'arguments', 0, $option]);
            if (!\is_int($seconds) && !\is_float($seconds) || $seconds <= 0 || $seconds > self::MAX_WAIT_SECONDS) {
                $problems[] = \sprintf('the publisher client has no %s of at most 2 s', $option);
            }
        }

        return $problems;
    }

    /**
     * @param list<int|string> $path
     */
    private static function valueAt(mixed $config, array $path): mixed
    {
        foreach ($path as $key) {
            if (!\is_array($config) || !\array_key_exists($key, $config)) {
                return null;
            }
            $config = $config[$key];
        }

        return $config;
    }

    private static function mercureYaml(): string
    {
        $contents = file_get_contents(\dirname(__DIR__, 3).'/config/packages/mercure.yaml');
        self::assertIsString($contents);

        return $contents;
    }
}
