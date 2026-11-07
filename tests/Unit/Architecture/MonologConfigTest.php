<?php

declare(strict_types=1);

namespace App\Tests\Unit\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The log handlers of each environment, read from monolog.yaml, so no prod kernel (and no prod cache) is booted. Every
 * handler that writes a log stream formats JSON, and prod writes to stdout (ADR-0029). The dev console handler is the
 * terminal of whoever runs a command, so it keeps Symfony's console format.
 */
final class MonologConfigTest extends TestCase
{
    private const string JSON = 'monolog.formatter.json';
    private const string ENV_LOG_FILE = '%kernel.logs_dir%/%kernel.environment%.log';

    /**
     * @return iterable<string, array{string, array<string, array{type: string, handler: ?string, path: ?string, formatter: ?string}>}>
     */
    public static function handlersPerEnvironment(): iterable
    {
        yield 'dev' => ['when@dev', [
            'main' => ['type' => 'stream', 'handler' => null, 'path' => self::ENV_LOG_FILE, 'formatter' => self::JSON],
            'console' => ['type' => 'console', 'handler' => null, 'path' => null, 'formatter' => null],
        ]];
        yield 'test' => ['when@test', [
            'main' => ['type' => 'fingers_crossed', 'handler' => 'nested', 'path' => null, 'formatter' => null],
            'nested' => ['type' => 'stream', 'handler' => null, 'path' => self::ENV_LOG_FILE, 'formatter' => self::JSON],
        ]];
        yield 'prod' => ['when@prod', [
            'main' => ['type' => 'fingers_crossed', 'handler' => 'nested', 'path' => null, 'formatter' => null],
            'nested' => ['type' => 'stream', 'handler' => null, 'path' => 'php://stdout', 'formatter' => self::JSON],
            'console' => ['type' => 'console', 'handler' => null, 'path' => null, 'formatter' => self::JSON],
            'deprecation' => ['type' => 'stream', 'handler' => null, 'path' => 'php://stdout', 'formatter' => self::JSON],
        ]];
    }

    /**
     * @param array<string, array{type: string, handler: ?string, path: ?string, formatter: ?string}> $expectedHandlers
     */
    #[Test]
    #[DataProvider('handlersPerEnvironment')]
    public function eachEnvironmentWritesJsonThroughExactlyTheseHandlers(string $environmentKey, array $expectedHandlers): void
    {
        $config = self::config();
        self::assertArrayHasKey($environmentKey, $config);
        $environmentConfig = $config[$environmentKey];
        self::assertIsArray($environmentConfig);
        self::assertSame(['monolog'], array_keys($environmentConfig));
        self::assertIsArray($environmentConfig['monolog']);
        self::assertSame(['handlers'], array_keys($environmentConfig['monolog']));
        $handlers = $environmentConfig['monolog']['handlers'];
        self::assertIsArray($handlers);

        self::assertSame($expectedHandlers, array_map(
            static fn (mixed $handler): array => \is_array($handler) ? [
                'type' => $handler['type'] ?? null,
                'handler' => $handler['handler'] ?? null,
                'path' => $handler['path'] ?? null,
                'formatter' => $handler['formatter'] ?? null,
            ] : [],
            $handlers,
        ));
    }

    /**
     * A handler outside the when@ blocks would run in every environment, unchecked by the test above.
     */
    #[Test]
    public function noHandlerIsDeclaredForEveryEnvironment(): void
    {
        $config = self::config();

        self::assertEqualsCanonicalizing(['monolog', 'when@dev', 'when@test', 'when@prod'], array_keys($config));
        self::assertIsArray($config['monolog']);
        self::assertSame(['channels'], array_keys($config['monolog']));
    }

    /**
     * @return array<mixed>
     */
    private static function config(): array
    {
        $config = Yaml::parseFile(\dirname(__DIR__, 3).'/config/packages/monolog.yaml');
        self::assertIsArray($config);

        return $config;
    }
}
