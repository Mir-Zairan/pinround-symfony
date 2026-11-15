<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Infrastructure;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Keeps every dispatched message with whether a transaction was open at that moment, and handles nothing.
 */
final class CommitAwareMessageBus implements MessageBusInterface
{
    /** @var list<array{message: object, wasTransactionOpen: bool}> */
    private array $dispatched = [];

    public function __construct(private readonly InMemoryTransactions $transactions)
    {
    }

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $this->dispatched[] = ['message' => $message, 'wasTransactionOpen' => $this->transactions->isOpen()];

        return Envelope::wrap($message, $stamps);
    }

    /**
     * @return list<array{message: object, wasTransactionOpen: bool}> in the order they were dispatched
     */
    public function dispatched(): array
    {
        return $this->dispatched;
    }
}
