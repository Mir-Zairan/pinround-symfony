<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Messenger;

use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;

/**
 * `%env(dsn_without_stream:NAME)%` resolves to the DSN unchanged, or fails when a Redis or Valkey DSN names a stream in
 * its path or its query. The Redis transport lets that stream win over each transport's own `stream` option, so one
 * shared DSN with a stream would put all four async_* queues on one stream (ADR-0033). The error never shows the
 * DSN, which can hold a password.
 */
final readonly class DsnWithoutStreamEnvVarProcessor implements EnvVarProcessorInterface
{
    public const string PREFIX = 'dsn_without_stream';

    /**
     * @throws RuntimeException when the variable is not a string or names a stream
     */
    public function getEnv(string $prefix, string $name, \Closure $getEnv): string
    {
        $dsn = $getEnv($name);
        if (!\is_string($dsn)) {
            throw new RuntimeException(\sprintf('The "%s" environment variable must be a DSN string.', $name));
        }

        // A comma separates the hosts of a Redis Cluster DSN, and each one can carry a path.
        foreach (explode(',', $dsn) as $hostDsn) {
            if (self::namesAStream($hostDsn)) {
                throw new RuntimeException(\sprintf('The "%s" environment variable names a Redis stream in its path or its "stream" query parameter. Remove it: each Messenger transport names its own stream.', $name));
            }
        }

        return $dsn;
    }

    public static function getProvidedTypes(): array
    {
        return [self::PREFIX => 'string'];
    }

    private static function namesAStream(string $hostDsn): bool
    {
        // The same split as the Redis transport's parser: scheme, optional user:password@, address, optional query.
        if (1 !== preg_match('#^(?:redis|rediss|valkey|valkeys):(?://)?(?:[^@]*@)?(?<address>[^?]*)(?:\?(?<query>.*))?$#s', $hostDsn, $parts)) {
            return false;
        }

        parse_str($parts['query'] ?? '', $query);
        if (\array_key_exists('stream', $query)) {
            return true;
        }

        // An address that starts with a slash is a Unix socket path, not a host and a stream.
        $address = $parts['address'];
        $pathStart = strpos($address, '/');

        return 0 !== $pathStart && false !== $pathStart && '' !== trim(substr($address, $pathStart), '/');
    }
}
