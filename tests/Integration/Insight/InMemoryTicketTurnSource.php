<?php

declare(strict_types=1);

namespace App\Tests\Integration\Insight;

use App\Insight\Application\Turn\TicketTurnFacts;
use App\Insight\Application\Turn\TicketTurnSource;
use App\SharedKernel\Domain\TicketId;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The test env's TicketTurnSource: tickets come in P03, so a test puts the facts it wants projected here.
 */
final class InMemoryTicketTurnSource implements TicketTurnSource, ResetInterface
{
    /** @var array<string, TicketTurnFacts> */
    private array $factsByTicketId = [];

    public function put(TicketTurnFacts $facts): void
    {
        $this->factsByTicketId[$facts->ticketId->toString()] = $facts;
    }

    public function forget(TicketId $ticketId): void
    {
        unset($this->factsByTicketId[$ticketId->toString()]);
    }

    public function factsFor(TicketId $ticketId): ?TicketTurnFacts
    {
        return $this->factsByTicketId[$ticketId->toString()] ?? null;
    }

    public function reset(): void
    {
        $this->factsByTicketId = [];
    }
}
