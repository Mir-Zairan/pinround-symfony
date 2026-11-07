<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Messenger;

/**
 * The host DSNs of a Redis or Valkey DSN as Symfony Messenger's Redis transport reads it: it splits the DSN at every
 * comma and refuses a piece that does not start with a Redis or Valkey scheme. So a comma may only begin another host
 * (`redis://a:6379,redis://b:6379`); a password must write a comma URL-encoded as %2C, which Messenger and RedisAdapter
 * both decode. A `?` in a password needs no encoding: both read the user-info up to its first `@`.
 */
final readonly class RedisDsnHosts
{
    private const string SCHEME = '(?:redis|rediss|valkey|valkeys):';

    /**
     * @return non-empty-list<string> one DSN per host, in order
     *
     * @throws \InvalidArgumentException when the DSN is not a Redis or Valkey one, or a comma does not begin another
     *                                   host; the message never shows the DSN
     */
    public static function of(
        #[\SensitiveParameter]
        string $dsn,
    ): array {
        $hostDsns = preg_split('#,(?='.self::SCHEME.')#', $dsn);
        if (false === $hostDsns || [] === $hostDsns) {
            throw new \InvalidArgumentException('The DSN could not be split into hosts.');
        }
        foreach ($hostDsns as $hostDsn) {
            if (1 !== preg_match('#^'.self::SCHEME.'#', $hostDsn)) {
                throw new \InvalidArgumentException('The DSN is not a Redis or Valkey DSN.');
            }
            if (str_contains($hostDsn, ',')) {
                throw new \InvalidArgumentException('The DSN has a comma that does not begin another host. Write a comma in a password as %2C.');
            }
        }

        return $hostDsns;
    }
}
