<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Identity\Infrastructure\Security\ConsumeFirstLoginRateLimiter;
use App\Identity\Infrastructure\Security\ValkeyLimiterStorage;
use App\SharedKernel\Infrastructure\Http\MergingSessionHandler;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\RedisSessionHandler;
use Symfony\Component\Yaml\Yaml;

/**
 * The login settings as the config files define them for dev and prod. The test env replaces the session storage and
 * the state Valkey's DSN, so no booted test kernel sees what dev and prod run.
 */
final class LoginConfigTest extends TestCase
{
    private const string USER_CLASS = \App\Identity\Infrastructure\Security\SecurityUser::class;

    #[Test]
    public function passwordsAreHashedWithArgon2idNamedExplicitlyInEveryEnvironment(): void
    {
        $security = self::config('packages/security.yaml');

        self::assertSame(['algorithm' => 'argon2id'], self::at($security, 'security', 'password_hashers', self::USER_CLASS));
        self::assertSame(
            ['algorithm' => 'argon2id', 'time_cost' => 3, 'memory_cost' => 10],
            self::at($security, 'when@test', 'security', 'password_hashers', self::USER_CLASS),
        );
    }

    #[Test]
    public function everyRouteButTheLoginLoginCodeAndPasswordResetPagesAndTheDevAndTestRoutesNeedsALogin(): void
    {
        $security = self::config('packages/security.yaml');

        self::assertSame(
            [
                ['route' => '^identity_login$', 'roles' => 'PUBLIC_ACCESS'],
                ['route' => '^identity_login_code$', 'roles' => 'PUBLIC_ACCESS'],
                ['route' => '^identity_password_(forgot|reset_token|reset)$', 'roles' => 'PUBLIC_ACCESS'],
                ['route' => '^organization_invitation(_token)?$', 'roles' => 'PUBLIC_ACCESS'],
                ['route' => '^web_component_gallery$', 'roles' => 'PUBLIC_ACCESS'],
                ['route' => '^test_', 'roles' => 'PUBLIC_ACCESS'],
                ['path' => '^/', 'roles' => 'IS_AUTHENTICATED_FULLY'],
            ],
            self::at($security, 'security', 'access_control'),
        );
        self::assertSame(['health', 'main'], array_keys((array) self::at($security, 'security', 'firewalls')));
        self::assertSame(['pattern' => '^/health/', 'security' => false], self::at($security, 'security', 'firewalls', 'health'));
    }

    #[Test]
    public function theFirewallRotatesTheSessionAtLoginAndRunsBeforeTheRequestTransaction(): void
    {
        $security = self::config('packages/security.yaml');

        self::assertSame('migrate', self::at($security, 'security', 'session_fixation_strategy'));
        self::assertFalse(self::at($security, 'security', 'firewalls', 'main', 'lazy'));
        self::assertSame(['limiter' => ConsumeFirstLoginRateLimiter::class], self::at($security, 'security', 'firewalls', 'main', 'login_throttling'));
        self::assertTrue(self::at($security, 'security', 'firewalls', 'main', 'form_login', 'enable_csrf'));
        self::assertTrue(self::at($security, 'security', 'firewalls', 'main', 'logout', 'enable_csrf'));
    }

    #[Test]
    public function devAndProdKeepSessionsInTheStateValkeyUnderTheirOwnPrefix(): void
    {
        $services = self::config('services.yaml');
        $framework = self::config('packages/framework.yaml');

        self::assertSame('__Host-pinround', self::at($services, 'parameters', 'app.session_cookie_name'));
        self::assertSame(
            [
                'handler_id' => RedisSessionHandler::class,
                'name' => '%app.session_cookie_name%',
                'cookie_secure' => true,
                'cookie_httponly' => true,
                'cookie_samesite' => 'lax',
                'cookie_path' => '/',
                'metadata_update_threshold' => 120,
            ],
            self::at($framework, 'framework', 'session'),
        );
        self::assertSame('session:', self::at($services, 'parameters', 'app.session_key_prefix'));
        self::assertSame(
            ['arguments' => ['@state_valkey.connection', ['prefix' => '%app.session_key_prefix%', 'ttl' => '%identity.session_idle_seconds%']]],
            self::at($services, 'services', RedisSessionHandler::class),
        );
        self::assertSame(
            [
                'decorates' => RedisSessionHandler::class,
                'decoration_priority' => 1,
                'arguments' => ['$sessionHandler' => '@.inner', '$connection' => '@state_valkey.connection', '$keyPrefix' => '%app.session_key_prefix%'],
            ],
            self::at($services, 'services', MergingSessionHandler::class),
        );
        self::assertSame(
            [
                'class' => 'Redis',
                'factory' => [\Symfony\Component\Cache\Adapter\RedisAdapter::class, 'createConnection'],
                'arguments' => ['%env(STATE_VALKEY_DSN)%', ['lazy' => true]],
            ],
            self::at($services, 'services', 'state_valkey.connection'),
        );
    }

    #[Test]
    public function sessionsEndAfterEightIdleHoursOrThirtyDays(): void
    {
        $services = self::config('services.yaml');

        self::assertSame(28800, self::at($services, 'parameters', 'identity.session_idle_seconds'));
        self::assertSame(30, self::at($services, 'parameters', 'identity.session_absolute_days'));
        self::assertSame([], self::environmentsRedefining($services, 'parameters', 'identity.session_idle_seconds'));
        self::assertSame([], self::environmentsRedefining($services, 'parameters', 'identity.session_absolute_days'));
    }

    #[Test]
    public function onlyTheTestEnvReplacesTheStateValkeyAndTheSessionStorage(): void
    {
        $services = self::config('services.yaml');
        $framework = self::config('packages/framework.yaml');

        self::assertSame(['%env(TEST_STATE_VALKEY_DSN)%', ['lazy' => true]], self::at($services, 'when@test', 'services', 'state_valkey.connection', 'arguments'));
        self::assertSame(['when@test'], self::environmentsRedefining($services, 'services', 'state_valkey.connection'));
        self::assertSame([], self::environmentsRedefining($services, 'services', RedisSessionHandler::class));
        self::assertSame([], self::environmentsRedefining($services, 'services', MergingSessionHandler::class));
        self::assertSame([], self::environmentsRedefining($services, 'parameters', 'app.session_key_prefix'));
        self::assertSame(['storage_factory_id' => 'test.session.storage.factory.mock_file'], self::at($framework, 'when@test', 'framework', 'session'));
        self::assertSame(['when@test'], self::environmentsRedefining($framework, 'framework', 'session'));
    }

    #[Test]
    public function theLoginPasswordResetPasswordChangeTwoStepCodeAndUploadBeginCountersLiveOnTheStateValkeyInEveryEnvironment(): void
    {
        $rateLimiter = self::config('packages/rate_limiter.yaml');

        self::assertSame(
            [
                'login_per_email_and_ip' => ['policy' => 'fixed_window', 'limit' => 5, 'interval' => '%identity.login_per_email_and_ip_minutes% minutes', 'storage_service' => ValkeyLimiterStorage::class, 'lock_factory' => 'lock.factory'],
                'login_per_ip' => ['policy' => 'fixed_window', 'limit' => 50, 'interval' => '%identity.login_per_ip_minutes% minutes', 'storage_service' => ValkeyLimiterStorage::class, 'lock_factory' => 'lock.factory'],
                'password_reset_per_email_and_ip' => ['policy' => 'fixed_window', 'limit' => 5, 'interval' => '%identity.password_reset_per_email_and_ip_minutes% minutes', 'storage_service' => ValkeyLimiterStorage::class, 'lock_factory' => 'lock.factory'],
                'password_reset_per_ip' => ['policy' => 'fixed_window', 'limit' => 50, 'interval' => '%identity.password_reset_per_ip_minutes% minutes', 'storage_service' => ValkeyLimiterStorage::class, 'lock_factory' => 'lock.factory'],
                'password_change_per_user' => ['policy' => 'fixed_window', 'limit' => 5, 'interval' => '%identity.password_change_per_user_minutes% minutes', 'storage_service' => ValkeyLimiterStorage::class, 'lock_factory' => 'lock.factory'],
                'two_step_code_per_user' => ['policy' => 'fixed_window', 'limit' => 5, 'interval' => '%identity.two_step_code_per_user_minutes% minutes', 'storage_service' => ValkeyLimiterStorage::class, 'lock_factory' => 'lock.factory'],
                'media_upload_begin_per_user' => ['policy' => 'fixed_window', 'limit' => 60, 'interval' => '%media.upload_begin_per_user_minutes% minutes', 'storage_service' => ValkeyLimiterStorage::class, 'lock_factory' => 'lock.factory'],
                'media_upload_status_per_user' => ['policy' => 'fixed_window', 'limit' => 120, 'interval' => '%media.upload_status_per_user_minutes% minutes', 'storage_service' => ValkeyLimiterStorage::class, 'lock_factory' => 'lock.factory'],
                'ai_calls_per_user' => ['policy' => 'fixed_window', 'limit' => 30, 'interval' => '60 minutes', 'storage_service' => ValkeyLimiterStorage::class, 'lock_factory' => 'lock.factory'],
            ],
            self::at($rateLimiter, 'framework', 'rate_limiter'),
        );
        self::assertSame(
            [
                'identity.login_per_email_and_ip_minutes' => 15,
                'identity.login_per_ip_minutes' => 60,
                'identity.password_reset_per_email_and_ip_minutes' => 15,
                'identity.password_reset_per_ip_minutes' => 60,
                'identity.password_change_per_user_minutes' => 15,
                'identity.two_step_code_per_user_minutes' => 15,
                'media.upload_begin_per_user_minutes' => 1,
                'media.upload_status_per_user_minutes' => 1,
            ],
            self::at($rateLimiter, 'parameters'),
        );
        self::assertSame(['parameters', 'framework'], array_keys($rateLimiter));
        self::assertSame(['framework' => ['lock' => 'state_valkey.connection']], self::config('packages/lock.yaml'));
    }

    /**
     * @param array<mixed> $config
     *
     * @return list<string>
     */
    private static function environmentsRedefining(array $config, string $section, string $key): array
    {
        $environments = [];
        foreach ($config as $name => $environment) {
            if (str_starts_with((string) $name, 'when@') && \is_array($environment) && \is_array($environment[$section] ?? null)
                && \array_key_exists($key, $environment[$section])) {
                $environments[] = (string) $name;
            }
        }

        return $environments;
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
     * @return array<mixed>
     */
    private static function config(string $file): array
    {
        $config = Yaml::parseFile(\dirname(__DIR__, 3).'/config/'.$file, Yaml::PARSE_CUSTOM_TAGS);
        self::assertIsArray($config);

        return $config;
    }
}
