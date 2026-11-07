<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Health;

use App\SharedKernel\Infrastructure\Messenger\RedisDsnHosts;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * A Valkey answers within a second. config/services.yaml defines one per Valkey (the cache and the state one,
 * ADR-0010), each with its name and DSN. A DSN of several hosts (`redis://a:6379,redis://b:6379`, as Messenger
 * takes it) must answer on every host. A comma anywhere else, such as a raw one in a password, fails the check: for
 * the state Valkey because Messenger refuses that DSN when a worker starts; for the cache one, whose client would read
 * it, only so both DSNs follow one rule. Write it as %2C (RedisDsnHosts).
 *
 * The second bounds the connect and each reply, not the lookup of a host name, which no PHP timeout covers.
 */
#[Exclude]
final readonly class ValkeyAnswers implements ReadinessCheck
{
    /** In seconds, for the connect and for each reply. */
    private const float TIMEOUT_SECONDS = 1.0;

    /**
     * DSN query options that RedisAdapter lets win over the probe's own: `lazy=1` would skip the connect altogether,
     * and the others would stretch or retry it.
     */
    private const array PROBE_OPTIONS = ['lazy', 'persistent', 'persistent_id', 'timeout', 'read_timeout', 'retry_interval'];

    public function __construct(
        private string $name,
        #[\SensitiveParameter]
        private string $dsn,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function verify(): void
    {
        foreach (RedisDsnHosts::of($this->dsn) as $hostDsn) {
            // createConnection connects, authenticates and runs SELECT on the DSN's database before it returns, and
            // throws when any of them fails: returning is the answer. The connection closes when it goes out of scope.
            RedisAdapter::createConnection(self::withoutProbeOptions($hostDsn), [
                'lazy' => false,
                'persistent' => false,
                'timeout' => self::TIMEOUT_SECONDS,
                'read_timeout' => self::TIMEOUT_SECONDS,
                'retry_interval' => 0,
            ]);
        }
    }

    private static function withoutProbeOptions(
        #[\SensitiveParameter]
        string $hostDsn,
    ): string {
        // As in RedisAdapter, the user-info ends at the first `@`; the password before it may hold a `?`.
        $userInfoEnd = strpos($hostDsn, '@');
        $queryStart = strpos($hostDsn, '?', false === $userInfoEnd ? 0 : $userInfoEnd + 1);
        if (false === $queryStart) {
            return $hostDsn;
        }

        parse_str(substr($hostDsn, $queryStart + 1), $query);
        $query = array_diff_key($query, array_flip(self::PROBE_OPTIONS));

        return substr($hostDsn, 0, $queryStart).([] === $query ? '' : '?'.http_build_query($query));
    }
}
