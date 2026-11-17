<?php

declare(strict_types=1);

namespace App\Ticketing\Infrastructure\Messenger;

use App\Review\Api\Event\AreaVerdictSaved;
use App\Review\Api\Event\ChecklistItemChanged;
use App\Review\Api\Event\PinVerdictSet;
use App\Review\Api\Event\PinVerdictUndone;
use App\Review\Api\Event\ReviewEvent;
use App\SharedKernel\Application\OnceOnlyHandling;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\ReviewChangeLabels;
use App\Ticketing\Application\ReviewChange;
use App\Ticketing\Application\TicketHistory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Writes one tkt_ticket_event row per checklist or verdict change of a ticket's rounds (P05.T7), once per message
 * however often it is delivered. It runs in worker-notify on event.bus, in the bus's transaction under the event's
 * organization, keeps the event's time, and reads the pin number and area name as they are when it runs.
 */
final readonly class RecordReviewChangesHandler
{
    /** The OnceOnlyHandling name; kept when the class is renamed, or redelivered events would be recorded again. */
    public const string HANDLER_NAME = 'ticketing.record_review_change';

    public function __construct(
        private OnceOnlyHandling $onceOnlyHandling,
        private ReviewChangeLabels $reviewChangeLabels,
        private TicketHistory $ticketHistory,
    ) {
    }

    /**
     * @throws \LogicException for a Review event this class has no kind for, so it is retried and then waits in the
     *                         failed transport instead of going unrecorded
     */
    #[AsMessageHandler(bus: 'event.bus')]
    public function __invoke(ReviewEvent $event): void
    {
        $this->onceOnlyHandling->runOnce(self::HANDLER_NAME, function () use ($event): void {
            $this->ticketHistory->recordReviewChange(
                new OrganizationId($event->organizationId),
                new TicketId($event->ticketId),
                new UserId($event->actorId),
                $this->changeOf($event),
                $event->occurredAt,
            );
        });
    }

    private function changeOf(ReviewEvent $event): ReviewChange
    {
        return match (true) {
            $event instanceof ChecklistItemChanged => new ReviewChange(
                ReviewChange::CHECK_ITEM_CHANGED,
                $event->state,
                $this->reviewChangeLabels->checkItemPinNumberOf($event->itemId),
                null,
            ),
            $event instanceof PinVerdictSet => $this->pinChange(ReviewChange::PIN_VERDICT_SET, $event->verdict, $event->roundId, $event->pinId),
            $event instanceof PinVerdictUndone => $this->pinChange(ReviewChange::PIN_VERDICT_UNDONE, $event->undoneVerdict, $event->roundId, $event->pinId),
            $event instanceof AreaVerdictSaved => new ReviewChange(
                ReviewChange::AREA_VERDICT_SAVED,
                $event->verdict,
                null,
                $this->reviewChangeLabels->areaNameOf($event->areaId),
            ),
            default => throw new \LogicException(\sprintf('"%s" has no kind of review change.', $event::class)),
        };
    }

    private function pinChange(string $kind, string $verdict, string $roundId, string $pinId): ReviewChange
    {
        $pin = $this->reviewChangeLabels->reviewedPinOf($roundId, $pinId);

        return new ReviewChange($kind, $verdict, $pin?->number, $pin?->areaName);
    }
}
