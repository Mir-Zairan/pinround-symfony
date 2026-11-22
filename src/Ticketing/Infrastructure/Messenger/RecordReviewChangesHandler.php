<?php

declare(strict_types=1);

namespace App\Ticketing\Infrastructure\Messenger;

use App\Review\Api\Event\AreaVerdictSaved;
use App\Review\Api\Event\ChecklistCheckUndone;
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
 * organization, keeps the event's time, and reads the pin number and area name as they are when it runs. It handles
 * these five events by name, not ReviewEvent: a Review event it has no line for must pass by, as a throw here would
 * roll back the other handlers of that delivery.
 */
final readonly class RecordReviewChangesHandler
{
    /**
     * The OnceOnlyHandling name of all five methods, as a message is only ever one of the events; kept when the class
     * is renamed, or redelivered events would be recorded again.
     */
    public const string HANDLER_NAME = 'ticketing.record_review_change';

    public function __construct(
        private OnceOnlyHandling $onceOnlyHandling,
        private ReviewChangeLabels $reviewChangeLabels,
        private TicketHistory $ticketHistory,
    ) {
    }

    #[AsMessageHandler(bus: 'event.bus')]
    public function onChecklistItemChanged(ChecklistItemChanged $event): void
    {
        $this->record($event, fn (): ReviewChange => new ReviewChange(
            ReviewChange::CHECK_ITEM_CHANGED,
            $event->state,
            $this->reviewChangeLabels->checkItemPinNumberOf($event->itemId),
            null,
        ));
    }

    #[AsMessageHandler(bus: 'event.bus')]
    public function onChecklistCheckUndone(ChecklistCheckUndone $event): void
    {
        $this->record($event, fn (): ReviewChange => new ReviewChange(
            ReviewChange::CHECK_UNDONE,
            $event->undoneState,
            $this->reviewChangeLabels->checkItemPinNumberOf($event->itemId),
            null,
        ));
    }

    #[AsMessageHandler(bus: 'event.bus')]
    public function onPinVerdictSet(PinVerdictSet $event): void
    {
        $this->record($event, fn (): ReviewChange => $this->pinChange(ReviewChange::PIN_VERDICT_SET, $event->verdict, $event->roundId, $event->pinId));
    }

    #[AsMessageHandler(bus: 'event.bus')]
    public function onPinVerdictUndone(PinVerdictUndone $event): void
    {
        $this->record($event, fn (): ReviewChange => $this->pinChange(ReviewChange::PIN_VERDICT_UNDONE, $event->undoneVerdict, $event->roundId, $event->pinId));
    }

    #[AsMessageHandler(bus: 'event.bus')]
    public function onAreaVerdictSaved(AreaVerdictSaved $event): void
    {
        $this->record($event, fn (): ReviewChange => new ReviewChange(
            ReviewChange::AREA_VERDICT_SAVED,
            $event->verdict,
            null,
            $this->reviewChangeLabels->areaNameOf($event->areaId),
        ));
    }

    /**
     * @param \Closure(): ReviewChange $changeOf reads the labels inside the once-only effect, so a redelivery reads none
     */
    private function record(ReviewEvent $event, \Closure $changeOf): void
    {
        $this->onceOnlyHandling->runOnce(self::HANDLER_NAME, function () use ($event, $changeOf): void {
            $this->ticketHistory->recordReviewChange(
                new OrganizationId($event->organizationId),
                new TicketId($event->ticketId),
                new UserId($event->actorId),
                $changeOf(),
                $event->occurredAt,
            );
        });
    }

    private function pinChange(string $kind, string $verdict, string $roundId, string $pinId): ReviewChange
    {
        $pin = $this->reviewChangeLabels->reviewedPinOf($roundId, $pinId);

        return new ReviewChange($kind, $verdict, $pin?->number, $pin?->areaName);
    }
}
