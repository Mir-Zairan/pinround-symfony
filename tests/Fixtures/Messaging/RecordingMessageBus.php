<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Messaging;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Keeps every dispatched envelope with its stamps, as a bus whose send_message hands it to a transport, and handles
 * nothing.
 */
final class RecordingMessageBus implements MessageBusInterface
{
    /** @var list<Envelope> */
    private array $dispatched = [];

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $envelope = Envelope::wrap($message, $stamps);
        $this->dispatched[] = $envelope;

        return $envelope;
    }

    /**
     * @return list<Envelope> in the order they were dispatched
     */
    public function dispatched(): array
    {
        return $this->dispatched;
    }
}
