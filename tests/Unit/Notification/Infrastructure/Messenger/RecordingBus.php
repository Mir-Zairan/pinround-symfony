<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Infrastructure\Messenger;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Keeps each dispatched message with its stamps and handles none.
 */
final class RecordingBus implements MessageBusInterface
{
    /** @var list<Envelope> */
    public array $envelopes = [];

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $envelope = Envelope::wrap($message, $stamps);
        $this->envelopes[] = $envelope;

        return $envelope;
    }
}
