<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Messenger;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * The id a message keeps from its dispatch through the outbox, the relay, retries and the failed transport; the
 * dedupe key of OnceOnlyHandling (ADR-0009). TransportMessageIdStamp cannot be it: each transport adds its own.
 */
final readonly class MessageIdStamp implements StampInterface
{
    private const string CANONICAL_UUID_V7 = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

    /**
     * @throws \InvalidArgumentException when the id is not a lowercase canonical UUIDv7
     */
    public function __construct(private string $messageId)
    {
        if (1 !== preg_match(self::CANONICAL_UUID_V7, $messageId)) {
            throw new \InvalidArgumentException('A message id must be a lowercase canonical UUIDv7.');
        }
    }

    /**
     * A new UUIDv7 (RFC 9562): 48 bits of Unix time in milliseconds, then version, variant and 74 random bits.
     * Written out here because symfony/uid is not installed yet (ADR-0006 names it).
     */
    public static function generate(): self
    {
        $unixTimeMs = (int) floor(microtime(true) * 1000);
        $bytes = substr(pack('J', $unixTimeMs), 2).random_bytes(10);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x70);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);

        return new self(\sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20)));
    }

    public function messageId(): string
    {
        return $this->messageId;
    }
}
