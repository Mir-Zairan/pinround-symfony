<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Health;

use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * A Valkey answers within a second. config/services.yaml defines one per Valkey (the cache and the state one,
 * ADR-0010), each with its name and DSN.
 */
#[Exclude]
final readonly class ValkeyAnswers implements ReadinessCheck
{
    /** In seconds, for the connect and for each reply. */
    private const float TIMEOUT_SECONDS = 1.0;

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
        // createConnection connects, authenticates and runs SELECT on the DSN's database before it returns, and
        // throws when any of them fails: returning is the answer. The connection closes when it goes out of scope.
        RedisAdapter::createConnection($this->dsn, [
            'lazy' => false,
            'persistent' => false,
            'timeout' => self::TIMEOUT_SECONDS,
            'read_timeout' => self::TIMEOUT_SECONDS,
            'retry_interval' => 0,
        ]);
    }
}
