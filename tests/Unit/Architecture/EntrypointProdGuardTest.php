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
        foreach (['APP_SECRET', 'MERCURE_PUBLISHER_JWT_KEY', 'MERCURE_SUBSCRIBER_JWT_KEY', 'FIELD_ENCRYPTION_KEYS', 'FIELD_ENCRYPTION_CURRENT_KEY_ID', 'OBJECT_STORAGE_SECRET_ACCESS_KEY', 'API_TOKEN_PEPPER'] as $name) {
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

    #[Test]
    #[DataProvider('committedEnvFiles')]
    public function prodRefusesTheCommittedAppSecret(string $envFile): void
    {
        self::assertRefused('APP_SECRET', ['APP_SECRET' => self::committedValueOf($envFile, 'APP_SECRET')] + self::realProdEnvironment());
    }

    #[Test]
    #[DataProvider('committedEnvFiles')]
    public function prodRefusesTheCommittedObjectStorageSecret(string $envFile): void
    {
        self::assertRefused('OBJECT_STORAGE_SECRET_ACCESS_KEY', ['OBJECT_STORAGE_SECRET_ACCESS_KEY' => self::committedValueOf($envFile, 'OBJECT_STORAGE_SECRET_ACCESS_KEY')] + self::realProdEnvironment());
    }

    #[Test]
    #[DataProvider('committedEnvFiles')]
    public function prodRefusesTheCommittedApiTokenPepper(string $envFile): void
    {
        self::assertRefused('API_TOKEN_PEPPER', ['API_TOKEN_PEPPER' => self::committedValueOf($envFile, 'API_TOKEN_PEPPER')] + self::realProdEnvironment());
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

    /**
     * @return iterable<string, array{string}>
     */
    public static function committedKeysUnderARenamedId(): iterable
    {
        foreach (self::committedEnvFiles() as $envFile => [$file]) {
            $committedKey = explode(':', self::fieldEncryptionValuesOf($file)['FIELD_ENCRYPTION_KEYS'], 2)[1];

            yield $envFile.' key alone' => ['prod-1:'.$committedKey];
            yield $envFile.' key after a space' => ['prod-1: '.$committedKey];
            yield $envFile.' key after a real entry' => [self::REAL_FIELD_KEYS.',prod-1:'.$committedKey];
            // The ids the dev and test keys had before they were renamed to change-me ones.
            yield $envFile.' key under its old id' => [('.env.dev' === $file ? 'dev-1:' : 'test-1:').$committedKey];
        }
    }

    #[Test]
    #[DataProvider('committedKeysUnderARenamedId')]
    public function prodRefusesACommittedKeyUnderAnyId(string $keyList): void
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
            'OBJECT_STORAGE_SECRET_ACCESS_KEY' => 'a-real-object-storage-secret',
            'API_TOKEN_PEPPER' => 'a-real-api-token-pepper-of-32-bytes-or-more',
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
        return [
            'FIELD_ENCRYPTION_KEYS' => self::committedValueOf($envFile, 'FIELD_ENCRYPTION_KEYS'),
            'FIELD_ENCRYPTION_CURRENT_KEY_ID' => self::committedValueOf($envFile, 'FIELD_ENCRYPTION_CURRENT_KEY_ID'),
        ];
    }

    /**
     * @return string the value of the NAME= line, without the quotes dotenv strips
     */
    private static function committedValueOf(string $envFile, string $name): string
    {
        $contents = file_get_contents(\dirname(__DIR__, 3).'/'.$envFile);
        self::assertIsString($contents);
        self::assertSame(1, preg_match('/^'.$name.'=(.+)$/m', $contents, $match), \sprintf('%s sets no %s.', $envFile, $name));

        return trim($match[1], '\'"');
    }
}
