<?php

declare(strict_types=1);

namespace App\Notification\Application\Notify;

use App\Conversation\Api\Event\MessageRemoved;
use App\Conversation\Api\Event\QuestionAnswered;
use App\Notification\Application\Realtime\BellRefreshes;
use App\Notification\Application\Realtime\RefreshBell;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;

/**
 * Marks done the bell rows about a message that closed, in worker-notify on event.bus (config/services.yaml), in the
 * bus's transaction under the event's organization: an answer closes the asked person's question row, and a
 * withdrawn question or a removed comment closes the question row and the author's reply rows about it. Held rows are
 * marked too, so neither the bell nor the digest lists them once their hold ends. Each recipient of a marked row gets
 * one bell refresh once the bus commits. A second delivery finds nothing open and refreshes nobody, so it needs no
 * once-only guard.
 */
final readonly class MarkMessageNotificationsDone
{
    public function __construct(private Notifications $notifications, private BellRefreshes $bellRefreshes)
    {
    }

    public function __invoke(QuestionAnswered|MessageRemoved $event): void
    {
        $kinds = $event instanceof QuestionAnswered ? [NotificationKind::Question] : [NotificationKind::Question, NotificationKind::Reply];

        $recipientIds = $this->notifications->markDoneAboutMessage(
            new OrganizationId($event->organizationId),
            new TicketId($event->ticketId),
            $event->messageId,
            $kinds,
            $event->occurredAt,
        );
        foreach ($recipientIds as $recipientId) {
            $this->bellRefreshes->sendOnceCommitted(new RefreshBell($event->organizationId, $recipientId->toString(), null), null);
        }
    }
}
