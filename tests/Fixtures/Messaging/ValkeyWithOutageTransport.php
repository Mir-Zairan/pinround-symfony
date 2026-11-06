<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Messaging;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Test-only Valkey stream that is down until a test calls bringBack(): every send fails before that, as a send to an
 * unreachable Valkey does, and is kept in memory after it.
 */
final class ValkeyWithOutageTransport implements TransportInterface
{
    private bool $isReachable = false;

    public function __construct(private readonly InMemoryTransport $queue)
    {
    }

    public function bringBack(): void
    {
        $this->isReachable = true;
    }

    /**
     * @throws TransportException while Valkey is down
     */
    public function send(Envelope $envelope): Envelope
    {
        if (!$this->isReachable) {
            throw new TransportException('Valkey is unreachable.');
        }

        return $this->queue->send($envelope);
    }

    public function get(): iterable
    {
        return $this->queue->get();
    }

    public function ack(Envelope $envelope): void
    {
        $this->queue->ack($envelope);
    }

    public function reject(Envelope $envelope): void
    {
        $this->queue->reject($envelope);
    }

    /**
     * @return list<Envelope>
     */
    public function getSent(): array
    {
        return array_values($this->queue->getSent());
    }
}
