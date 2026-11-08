<?php

declare(strict_types=1);

namespace App\Tests\Unit\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Runs the image entrypoint's prod guard (docker/frankenphp/docker-entrypoint.sh) with `true` as the command, so a
 * start it allows does nothing and exits 0.
 */
final class EntrypointProdGuardTest extends TestCase
{
    private const string REAL_FIELD_KEYS = 'prod-2026-11:q83vEjRWeJCrze8SNFZ4kKvN7xI0VniQq83vEjRWeJA=';

    #[Test]
    public function prodStartsWithRealValues(): void
    {
        [$exitCode, $errorOutput] = self::start(self::realProdEnvironment());

        self::assertSame('', $errorOutput);
        self::assertSame(0, $exitCode);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function guardedVariables(): iterable
    {
        foreach (['APP_SECRET', 'MERCURE_PUBLISHER_JWT_KEY', 'MERCURE_SUBSCRIBER_JWT_KEY', 'FIELD_ENCRYPTION_KEYS', 'FIELD_ENCRYPTION_CURRENT_KEY_ID'] as $name) {
            yield $name => [$name];
        }
    }

    #[Test]
    #[DataProvider('guardedVariables')]
    public function prodRefusesToStartWhenAGuardedVariableIsEmpty(string $name): void
    {
        self::assertRefused($name, [$name => ''] + self::realProdEnvironment());
    }

    #[Test]
    #[DataProvider('guardedVariables')]
    public function prodRefusesToStartWhenAGuardedVariableIsUnset(string $name): void
    {
        $environment = self::realProdEnvironment();
        unset($environment[$name]);

        self::assertRefused($name, $environment);
    }

    #[Test]
    #[DataProvider('guardedVariables')]
    public function prodRefusesToStartWithAChangeMePlaceholder(string $name): void
    {
        self::assertRefused($name, [$name => 'change-me-placeholder'] + self::realProdEnvironment());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function committedEnvFiles(): iterable
    {
        yield '.env.dev' => ['.env.dev'];
        yield '.env.test' => ['.env.test'];
    }

    #[Test]
    #[DataProvider('committedEnvFiles')]
    public function prodRefusesTheCommittedFieldEncryptionKeys(string $envFile): void
    {
        $committed = self::fieldEncryptionValuesOf($envFile);

        self::assertRefused('FIELD_ENCRYPTION_KEYS', ['FIELD_ENCRYPTION_KEYS' => $committed['FIELD_ENCRYPTION_KEYS']] + self::realProdEnvironment());
        self::assertRefused('FIELD_ENCRYPTION_CURRENT_KEY_ID', ['FIELD_ENCRYPTION_CURRENT_KEY_ID' => $committed['FIELD_ENCRYPTION_CURRENT_KEY_ID']] + self::realProdEnvironment());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function keyListsWithAPlaceholderEntry(): iterable
    {
        yield 'after a comma' => [self::REAL_FIELD_KEYS.',change-me-dev:Y2hhbmdlLW1lLWRldi1vbmx5LWZpZWxkLWtleS0zMmI='];
        yield 'after a comma and a space' => [self::REAL_FIELD_KEYS.', change-me-dev:Y2hhbmdlLW1lLWRldi1vbmx5LWZpZWxkLWtleS0zMmI='];
    }

    #[Test]
    #[DataProvider('keyListsWithAPlaceholderEntry')]
    public function prodRefusesAKeyListThatStillHoldsAPlaceholderEntry(string $keyList): void
    {
        self::assertRefused('FIELD_ENCRYPTION_KEYS', ['FIELD_ENCRYPTION_KEYS' => $keyList] + self::realProdEnvironment());
    }

    #[Test]
    public function prodStartsWithARotatedKeyList(): void
    {
        $environment = [
            'FIELD_ENCRYPTION_KEYS' => self::REAL_FIELD_KEYS.', prod-2026-12:Mzv6dPU4kJ2xr0gQq83vEjRWeJCrze8SNFZ4kKvN7xI=',
            'FIELD_ENCRYPTION_CURRENT_KEY_ID' => 'prod-2026-12',
        ] + self::realProdEnvironment();

        self::assertSame(0, self::start($environment)[0]);
    }

    #[Test]
    public function devStartsWithoutTheGuardedVariables(): void
    {
        self::assertSame(0, self::start(['APP_ENV' => 'dev', 'PATH' => self::path()])[0]);
    }

    /**
     * @param array<string, string> $environment
     */
    private static function assertRefused(string $name, array $environment): void
    {
        [$exitCode, $errorOutput] = self::start($environment);

        self::assertSame(1, $exitCode);
        self::assertSame(\sprintf("Refusing to start: %s is empty or holds a change-me placeholder. Set a real value.\n", $name), $errorOutput);
    }

    /**
     * @return array<string, string>
     */
    private static function realProdEnvironment(): array
    {
        return [
            'APP_ENV' => 'prod',
            'PATH' => self::path(),
            'APP_SECRET' => 'a-real-app-secret',
            'MERCURE_PUBLISHER_JWT_KEY' => 'a-real-publisher-key',
            'MERCURE_SUBSCRIBER_JWT_KEY' => 'a-real-subscriber-key',
            'FIELD_ENCRYPTION_KEYS' => self::REAL_FIELD_KEYS,
            'FIELD_ENCRYPTION_CURRENT_KEY_ID' => 'prod-2026-11',
        ];
    }

    private static function path(): string
    {
        return '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';
    }

    /**
     * @param array<string, string> $environment the whole environment of the entrypoint; nothing is inherited
     *
     * @return array{int, string} the exit code and what it wrote to stderr
     */
    private static function start(array $environment): array
    {
        $process = proc_open(
            ['sh', \dirname(__DIR__, 3).'/docker/frankenphp/docker-entrypoint.sh', 'true'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $environment,
        );
        self::assertIsResource($process);

        stream_get_contents($pipes[1]);
        $errorOutput = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $errorOutput];
    }

    /**
     * @return array{FIELD_ENCRYPTION_KEYS: string, FIELD_ENCRYPTION_CURRENT_KEY_ID: string}
     */
    private static function fieldEncryptionValuesOf(string $envFile): array
    {
        $contents = file_get_contents(\dirname(__DIR__, 3).'/'.$envFile);
        self::assertIsString($contents);
        self::assertSame(1, preg_match('/^FIELD_ENCRYPTION_KEYS=(.+)$/m', $contents, $keys));
        self::assertSame(1, preg_match('/^FIELD_ENCRYPTION_CURRENT_KEY_ID=(.+)$/m', $contents, $currentKeyId));

        return ['FIELD_ENCRYPTION_KEYS' => $keys[1], 'FIELD_ENCRYPTION_CURRENT_KEY_ID' => $currentKeyId[1]];
    }
}
