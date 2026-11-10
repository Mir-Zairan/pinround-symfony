<?php

declare(strict_types=1);

namespace App\Tests\Unit\Architecture;

use App\SharedKernel\Infrastructure\Health\ValkeyAnswers;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The Valkey readiness checks as config/services.yaml defines them, read from the file: the test env replaces the
 * state one, so no booted test kernel sees the definition dev and prod run.
 */
final class ReadinessWiringTest extends TestCase
{
    #[Test]
    public function devAndProdCheckTheCacheValkeyAndTheStateValkeyMessengerUses(): void
    {
        $services = self::services(self::config());

        self::assertSame(
            ['class' => ValkeyAnswers::class, 'arguments' => ['cache_valkey', '%env(REDIS_CACHE_DSN)%']],
            $services['health.cache_valkey_answers'] ?? null,
        );
        self::assertSame(
            ['class' => ValkeyAnswers::class, 'arguments' => ['state_valkey', '%env(MESSENGER_TRANSPORT_DSN)%']],
            $services['health.state_valkey_answers'] ?? null,
        );
    }

    #[Test]
    public function onlyTheTestEnvReplacesTheStateValkeysDsn(): void
    {
        $config = self::config();

        self::assertSame(
            ['class' => ValkeyAnswers::class, 'arguments' => ['state_valkey', '%env(TEST_STATE_VALKEY_DSN)%'], 'autoconfigure' => true],
            self::services($config['when@test'] ?? null)['health.state_valkey_answers'] ?? null,
        );

        $environmentsReplacingIt = [];
        foreach ($config as $key => $section) {
            if (str_starts_with((string) $key, 'when@') && \is_array($section) && \is_array($section['services'] ?? null)
                && \array_key_exists('health.state_valkey_answers', $section['services'])) {
                $environmentsReplacingIt[] = $key;
            }
        }
        self::assertSame(['when@test'], $environmentsReplacingIt);
    }

    /**
     * @return array<mixed>
     */
    private static function services(mixed $section): array
    {
        self::assertIsArray($section);
        self::assertIsArray($section['services'] ?? null);

        return $section['services'];
    }

    /**
     * @return array<mixed>
     */
    private static function config(): array
    {
        $config = Yaml::parseFile(\dirname(__DIR__, 3).'/config/services.yaml', Yaml::PARSE_CUSTOM_TAGS);
        self::assertIsArray($config);

        return $config;
    }
}
