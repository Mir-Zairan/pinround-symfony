<?php

declare(strict_types=1);

namespace App\Tests\Integration\Insight;

use App\Insight\Application\Turn\TicketTurnFacts;
use App\Insight\Application\Turn\TicketTurnSource;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\TicketTurnSnapshot;
use Symfony\Contracts\Service\ResetInterface;

/**
 * A TicketTurnSource a test puts the facts it wants projected into, with no ticket, team or user behind them.
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

    /**
     * No test projects from a snapshot through this double; the real mapping is tested on DbalTicketTurnSource.
     */
    public function factsFrom(TicketTurnSnapshot $snapshot, ?array $waitingTurns = null): never
    {
        throw new \LogicException('InMemoryTicketTurnSource cannot build facts from a snapshot; use DbalTicketTurnSource.');
    }

    public function reset(): void
    {
        $this->factsByTicketId = [];
    }
}
