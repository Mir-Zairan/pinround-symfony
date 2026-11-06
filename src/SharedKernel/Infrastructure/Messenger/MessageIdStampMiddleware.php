<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Messenger;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * Gives a message without a MessageIdStamp a new one before it is sent, so every copy of it later received carries
 * the same id, and makes that id the MessageBeingHandled while the rest of the bus runs. It must come after
 * dispatch_after_current_bus: a message that waits there for the current one to finish is handled under its own id.
 */
final readonly class MessageIdStampMiddleware implements MiddlewareInterface
{
    public function __construct(private MessageBeingHandled $messageBeingHandled)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $stamp = $envelope->last(MessageIdStamp::class);
        if (!$stamp instanceof MessageIdStamp) {
            $stamp = MessageIdStamp::generate();
            $envelope = $envelope->with($stamp);
        }

        // A handler can dispatch a message that is handled at once; the outer message is the current one again after.
        $outerMessageId = $this->messageBeingHandled->messageId();
        $this->messageBeingHandled->set($stamp->messageId());
        try {
            return $stack->next()->handle($envelope, $stack);
        } finally {
            $this->messageBeingHandled->set($outerMessageId);
        }
    }
}
