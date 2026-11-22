<?php

declare(strict_types=1);

namespace App\Tests\Unit\AgentApi\Infrastructure\Mcp\Tool;

use App\Review\Api\CheckItemChange;
use App\Review\Api\ChecklistCommands;
use App\Review\Api\ChecklistRefused;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * Records the items set_verdict marks fixed, each as pin 3 with no pings cleared; $refusal, when set, is thrown
 * instead and nothing is recorded, as the real command saves nothing when it refuses. The commands set_verdict never
 * uses throw.
 */
final class RecordingChecklistMarks implements ChecklistCommands
{
    /** @var list<array{string, string, string}> item, actor and time of each markFixed */
    public array $markedFixed = [];

    public ?ChecklistRefused $refusal = null;

    public function markFixed(string $itemId, UserId $actor, \DateTimeImmutable $at): CheckItemChange
    {
        if (null !== $this->refusal) {
            throw $this->refusal;
        }
        $this->markedFixed[] = [$itemId, $actor->toString(), $at->format('Y-m-d H:i:s')];

        return new CheckItemChange(3, self::STATE_FIXED, 0);
    }

    public function createFromReport(TicketId $ticketId, \DateTimeImmutable $at): ?string
    {
        throw new \LogicException('set_verdict never creates a checklist.');
    }

    public function markOk(string $itemId, UserId $actor, \DateTimeImmutable $at): CheckItemChange
    {
        throw new \LogicException('set_verdict never checks an item OK.');
    }

    public function markNotFixed(string $itemId, UserId $actor, \DateTimeImmutable $at): CheckItemChange
    {
        throw new \LogicException('set_verdict never sends an item back.');
    }

    public function undoCheck(string $itemId, UserId $actor, \DateTimeImmutable $at): CheckItemChange
    {
        throw new \LogicException('set_verdict never undoes a check.');
    }
}
