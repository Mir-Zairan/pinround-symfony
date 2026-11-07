<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Messenger;

use Symfony\Contracts\Service\ResetInterface;

/**
 * The MessageIdStamp id of the message whose handlers run now; MessageIdStampMiddleware fills it, ProcessedMessageLedger
 * reads it. The kernel resets it between messages, so worker mode never carries an id over (ADR-0008).
 */
final class MessageBeingHandled implements ResetInterface
{
    private ?string $messageId = null;

    public function messageId(): ?string
    {
        return $this->messageId;
    }

    /**
     * @param string|null $messageId null when no message is being handled
     */
    public function set(?string $messageId): void
    {
        $this->messageId = $messageId;
    }

    public function reset(): void
    {
        $this->messageId = null;
    }
}
