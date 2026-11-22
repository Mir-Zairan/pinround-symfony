<?php

declare(strict_types=1);

namespace App\Review\Application\Checklist;

use App\Review\Api\CheckItemChange;
use App\Review\Api\ChecklistCommands;
use App\Review\Api\ChecklistQueries;
use App\Review\Api\ChecklistRefused;
use App\Review\Api\Event\ChecklistCheckUndone;
use App\Review\Api\Event\ChecklistItemChanged;
use App\Review\Api\Event\ReviewEvent;
use App\Review\Application\ReviewEvents;
use App\Review\Domain\CheckItem;
use App\Review\Domain\CheckItemId;
use App\Review\Domain\Checklist;
use App\Review\Domain\Checklists;
use App\Review\Domain\FindingsChecklist;
use App\Review\Domain\Round;
use App\Review\Domain\Rounds;
use App\Review\Domain\RoundSlotTaken;
use App\SharedKernel\Application\PingClearing;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketTurnQueries;

/**
 * ChecklistCommands and RoundChecklistCommands on Checklists; the contracts are on {@see ChecklistCommands} and
 * {@see RoundChecklistCommands}. Every change locks its item before it reads the state, so two changes of one item are
 * made one after the other.
 */
final readonly class UseCaseChecklistCommands implements RoundChecklistCommands
{
    public function __construct(
        private Checklists $checklists,
        private Rounds $rounds,
        private ChecklistQueries $checklistQueries,
        private ReviewEvents $reviewEvents,
        private PingClearing $pingClearing,
        private TenantContext $tenantContext,
        private TicketTurnQueries $ticketTurnQueries,
    ) {
    }

    public function createFromReport(TicketId $ticketId, \DateTimeImmutable $at): ?string
    {
        $organizationId = $this->tenantContext->require();
        $report = $this->checklists->reportPinsOf($ticketId);
        if (null === $report) {
            return null;
        }
        $checklist = Checklist::fromReport(
            $this->rounds->nextIdentity(),
            $organizationId,
            $ticketId,
            $report,
            $this->checklists->nextItemIdentity(...),
            $at,
        );
        if (null === $checklist) {
            return null;
        }
        try {
            $this->checklists->add($checklist);
        } catch (RoundSlotTaken) {
            // The checklist takes no number, so the slot taken is the ticket's one checklist round.
            return null;
        }

        return $checklist->id()->toString();
    }

    public function createFromFindings(Round $sharedDraft, \DateTimeImmutable $at): int
    {
        if (!$this->tenantContext->require()->equals($sharedDraft->organizationId())) {
            throw new \LogicException(\sprintf('Round %s is not the tenant\'s.', $sharedDraft->id()->toString()));
        }
        $assigneeOf = function () use ($sharedDraft): ?UserId {
            $ticket = $this->ticketTurnQueries->factsOf($sharedDraft->ticketId())
                ?? throw new \LogicException(\sprintf('The ticket of round %s cannot be read.', $sharedDraft->id()->toString()));

            return $ticket->assigneeId;
        };
        try {
            $checklist = FindingsChecklist::ofSharedDraft($sharedDraft, $assigneeOf, $this->checklists->nextItemIdentity(...), $at);
        } catch (\InvalidArgumentException $notShared) {
            throw new \LogicException($notShared->getMessage(), 0, $notShared);
        }
        if (null === $checklist) {
            return 0;
        }
        $this->checklists->addFindings($checklist);

        return \count($checklist->items());
    }

    public function markFixed(string $itemId, UserId $actor, \DateTimeImmutable $at): CheckItemChange
    {
        return $this->change($itemId, $actor, $at, static function (CheckItem $item, OrganizationId $organizationId) use ($actor, $at): ReviewEvent {
            $item->markFixed($actor, $at);

            return self::itemChangedOf($item, $organizationId, $actor, $at);
        });
    }

    public function markOk(string $itemId, UserId $actor, \DateTimeImmutable $at): CheckItemChange
    {
        return $this->change($itemId, $actor, $at, static function (CheckItem $item, OrganizationId $organizationId) use ($actor, $at): ReviewEvent {
            $item->markOk($actor, $at);

            return self::itemChangedOf($item, $organizationId, $actor, $at);
        });
    }

    public function markNotFixed(string $itemId, UserId $actor, \DateTimeImmutable $at): CheckItemChange
    {
        return $this->change($itemId, $actor, $at, static function (CheckItem $item, OrganizationId $organizationId) use ($actor, $at): ReviewEvent {
            $item->markNotFixed($actor, $at);

            return self::itemChangedOf($item, $organizationId, $actor, $at);
        });
    }

    public function undoCheck(string $itemId, UserId $actor, \DateTimeImmutable $at): CheckItemChange
    {
        return $this->change($itemId, $actor, $at, static function (CheckItem $item, OrganizationId $organizationId) use ($actor, $at): ReviewEvent {
            $undoneState = $item->undoCheck($actor, $at);

            return new ChecklistCheckUndone(
                $organizationId->toString(),
                $item->ticketId()->toString(),
                $item->roundId()->toString(),
                $item->id()->toString(),
                $undoneState->value,
                $actor->toString(),
                $at,
            );
        });
    }

    /**
     * @param \Closure(CheckItem, OrganizationId): ReviewEvent $move makes the move on the locked item, throwing the
     *                                                               domain's refusal, and gives the event to publish
     *
     * @throws ChecklistRefused NOT_FOUND when the id is malformed or the tenant has no such item; otherwise the domain's
     *                          reason; nothing is saved, published or cleared
     */
    private function change(string $itemId, UserId $actor, \DateTimeImmutable $at, \Closure $move): CheckItemChange
    {
        $organizationId = $this->tenantContext->require();
        try {
            $id = new CheckItemId($itemId);
        } catch (\InvalidArgumentException) {
            throw new ChecklistRefused(ChecklistRefused::NOT_FOUND);
        }
        $item = $this->checklists->lockedItem($id) ?? throw new ChecklistRefused(ChecklistRefused::NOT_FOUND);
        $facts = $this->checklistQueries->accessFactsOf($id->toString())
            ?? throw new \LogicException(\sprintf('Check item %s is locked but has no access facts.', $id->toString()));
        try {
            $event = $move($item, $organizationId);
        } catch (\DomainException $refused) {
            throw new ChecklistRefused($refused->getMessage(), 0, $refused);
        }
        $this->checklists->saveItem($item);

        $this->reviewEvents->publish($event);

        return new CheckItemChange(
            $facts->pinNumber,
            $item->state()->value,
            $this->pingClearing->clearForActor($organizationId, $item->ticketId(), $actor, $at),
        );
    }

    private static function itemChangedOf(CheckItem $item, OrganizationId $organizationId, UserId $actor, \DateTimeImmutable $at): ChecklistItemChanged
    {
        return new ChecklistItemChanged(
            $organizationId->toString(),
            $item->ticketId()->toString(),
            $item->roundId()->toString(),
            $item->id()->toString(),
            $item->state()->value,
            $actor->toString(),
            $at,
        );
    }
}
