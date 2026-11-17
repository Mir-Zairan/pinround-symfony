<?php

declare(strict_types=1);

namespace App\Notification\Application\Notify;

use App\Conversation\Api\Event\QuestionAnswered;
use App\Conversation\Api\Event\QuestionAsked;
use App\Conversation\Api\Event\ReplyPosted;
use App\Conversation\Api\MessageQueries;
use App\Notification\Api\Event\PingSent;
use App\Notification\Api\NotificationQueries;
use App\Notification\Application\Realtime\BellRefreshes;
use App\Notification\Application\Realtime\RefreshBell;
use App\Notification\Domain\PingId;
use App\Notification\Domain\Pings;
use App\Organization\Api\MemberNameQueries;
use App\SharedKernel\Application\OnceOnlyHandling;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\Event\TicketAssigned;
use App\Ticketing\Api\TicketKeyQueries;
use App\Ticketing\Api\TicketTurnQueries;
use App\Ticketing\Api\TicketVisibilities;
use App\Ticketing\Api\TicketVisibilityFacts;

/**
 * Writes the ntf_notification row of each event that notifies one person (realtime-and-notifications.md › Notification
 * kinds), once per message however often it is delivered. It runs in worker-notify on event.bus (config/services.yaml),
 * in the bus's transaction under the event's organization. Nobody is notified of their own action, of a ticket they
 * muted or may no longer see, or by or as someone who is no longer a member; a ticket that is gone notifies nobody,
 * and neither does a ping cleared, a question answered or withdrawn, or a reply's message removed before its event
 * is handled. A row made in the recipient's quiet hours is held until they end (a ping keeps the time stored on it)
 * and is created at that end, as a held ping's event already is: a digest's window starts after the last digest's
 * send time, so a row created at the event would miss every digest when one went out during the hold. A shown reply
 * within REPLY_FOLD_SECONDS of the last unread one about its message folds into it instead of adding a row, when that
 * one is shown or its hold has ended; a held reply folds only
 * into a reply held until the same time, for the whole hold (Notifications::foldIntoUnreadReply). Each row added or
 * folded refreshes the recipient's bell once the bus commits, or once it is delivered when it is held.
 */
final readonly class NotifyRecipients
{
    /** The OnceOnlyHandling name; kept when the class is renamed, or redelivered events would notify again. */
    public const string HANDLER_NAME = 'notification.notify_recipients';

    /** A reply this many seconds or less after the last unread reply notification about its message updates that row. */
    public const int REPLY_FOLD_SECONDS = 120;

    public function __construct(
        private OnceOnlyHandling $onceOnlyHandling,
        private NotificationQueries $notificationQueries,
        private TicketTurnQueries $ticketTurnQueries,
        private TicketVisibilities $ticketVisibilities,
        private TicketKeyQueries $ticketKeyQueries,
        private MemberNameQueries $memberNameQueries,
        private Notifications $notifications,
        private BellRefreshes $bellRefreshes,
        private Pings $pings,
        private DeliveryTimes $deliveryTimes,
        private MessageQueries $messageQueries,
    ) {
    }

    public function __invoke(PingSent|QuestionAsked|QuestionAnswered|ReplyPosted|TicketAssigned $event): void
    {
        [$kind, $recipientId, $occurredAt, $sourceIds] = match (true) {
            $event instanceof PingSent => [NotificationKind::Ping, $event->toUserId, $event->occurredAt, ['pingId' => $event->pingId]],
            $event instanceof QuestionAsked => [NotificationKind::Question, $event->toUserId, $event->occurredAt, ['messageId' => $event->messageId]],
            $event instanceof QuestionAnswered => [NotificationKind::Answer, $event->askerId, $event->occurredAt, ['messageId' => $event->messageId]],
            $event instanceof ReplyPosted => [NotificationKind::Reply, $event->messageAuthorId, $event->occurredAt, ['messageId' => $event->messageId, 'replyId' => $event->replyId]],
            $event instanceof TicketAssigned => [NotificationKind::Assigned, $event->assigneeId, $event->assignedAt, []],
        };
        $organizationId = new OrganizationId($event->organizationId);
        $recipient = new UserId($recipientId);
        $actor = new UserId($event->actorId);
        $ticketId = new TicketId($event->ticketId);
        $pingId = $event instanceof PingSent ? new PingId($event->pingId) : null;
        $closableMessageId = $event instanceof QuestionAsked || $event instanceof ReplyPosted ? $event->messageId : null;

        $this->onceOnlyHandling->runOnce(self::HANDLER_NAME, function () use ($organizationId, $recipient, $actor, $ticketId, $pingId, $closableMessageId, $kind, $occurredAt, $sourceIds): void {
            $ping = null;
            if (null !== $pingId) {
                // The shared lock makes a clear of this ping that runs at the same time wait for this transaction, so
                // the clear's markPingsDone also covers the row written here; a ping cleared first is null and skipped.
                $ping = $this->pings->lockedOpenPing($pingId);
                if (null === $ping) {
                    return;
                }
            }
            if (null !== $closableMessageId) {
                // Same lock as MarkMessageNotificationsDone; a reply still counts once its question is answered.
                $this->notifications->lockAboutMessage($organizationId, $closableMessageId);
                $isStillOpen = NotificationKind::Question === $kind
                    ? $this->messageQueries->isOpenQuestion($closableMessageId)
                    : $this->messageQueries->isPresent($closableMessageId);
                if (!$isStillOpen) {
                    return;
                }
            }
            $snapshot = $this->snapshotFor($organizationId, $recipient, $actor, $ticketId);
            if (null === $snapshot) {
                return;
            }

            // A ping keeps the time SendPing stored on it. Only a held row has a deliver_after, so only held rows are
            // ever released later.
            $deliverAfter = null !== $ping ? $ping->deliverAfter() : $this->deliveryTimes->deliverAfterFor($organizationId, $recipient, $occurredAt);
            $notification = new NewNotification($organizationId, $recipient, $kind, $actor, $ticketId, [...$snapshot, ...$sourceIds], $deliverAfter ?? $occurredAt, $deliverAfter);
            $since = $occurredAt->modify(\sprintf('-%d seconds', self::REPLY_FOLD_SECONDS));
            if (NotificationKind::Reply !== $kind || !$this->notifications->foldIntoUnreadReply($notification, $since)) {
                $this->notifications->add($notification);
            }
            // realtime-and-notifications.md › What is pushed when; a person's name is no ticket text (ADR-0027).
            $toast = NotificationKind::Answer === $kind ? \sprintf('%s answered your question.', $snapshot['actorName']) : null;
            $this->bellRefreshes->sendOnceCommitted(new RefreshBell($organizationId->toString(), $recipient->toString(), $toast), $deliverAfter);
        });
    }

    /**
     * @return array{actorName: string, ticketKey: string}|null null when the recipient must not be notified
     */
    private function snapshotFor(OrganizationId $organizationId, UserId $recipient, UserId $actor, TicketId $ticketId): ?array
    {
        if ($recipient->equals($actor) || $this->notificationQueries->isMuted($recipient, $ticketId) || !$this->maySee($organizationId, $recipient, $ticketId)) {
            return null;
        }

        $ticketKey = $this->ticketKeyQueries->keyOf($ticketId);
        $names = $this->memberNameQueries->displayNamesOf($organizationId, [$actor, $recipient]);
        $actorName = $names[$actor->toString()] ?? null;
        if (null === $ticketKey || null === $actorName || !isset($names[$recipient->toString()])) {
            return null;
        }

        return ['actorName' => $actorName, 'ticketKey' => $ticketKey];
    }

    private function maySee(OrganizationId $organizationId, UserId $recipient, TicketId $ticketId): bool
    {
        $ticket = $this->ticketTurnQueries->factsOf($ticketId);
        if (null === $ticket) {
            return false;
        }

        return $this->ticketVisibilities->of($organizationId, $recipient)->isVisible(TicketVisibilityFacts::ofSnapshot($organizationId, $ticket));
    }
}
