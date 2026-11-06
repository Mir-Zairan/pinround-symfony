<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Messenger;

use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * The id a message keeps from its dispatch through the outbox, the relay, retries and the failed transport; the
 * dedupe key of OnceOnlyHandling (ADR-0033). TransportMessageIdStamp cannot be it: each transport adds its own.
 */
final readonly class MessageIdStamp implements StampInterface
{
    // A string, so a transport serializer stores it as is.
    private string $messageId;

    /**
     * @throws \InvalidArgumentException when the id is not a UUIDv7 in lowercase canonical form
     */
    public function __construct(string $messageId)
    {
        $isCanonicalUuidV7 = Uuid::isValid($messageId) && Uuid::fromString($messageId) instanceof UuidV7
            && Uuid::fromString($messageId)->toRfc4122() === $messageId;
        if (!$isCanonicalUuidV7) {
            throw new \InvalidArgumentException('A message id must be a lowercase canonical UUIDv7.');
        }

        $this->messageId = $messageId;
    }

    public static function generate(): self
    {
        return new self(Uuid::v7()->toRfc4122());
    }

    public function messageId(): string
    {
        return $this->messageId;
    }
}
