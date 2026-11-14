<?php

declare(strict_types=1);

namespace App\Notification\Application\Ping;

use App\Notification\Api\Event\PingSent;
use App\Notification\Api\PingCommands;
use App\Notification\Api\PingMessageRefused;
use App\Notification\Api\PingRecipientCannotView;
use App\Notification\Api\PingSlot;
use App\Notification\Api\PingsPaused;
use App\Notification\Api\PingToYourself;
use App\Notification\Domain\Ping;
use App\Notification\Domain\PingId;
use App\Notification\Domain\Pings;
use App\Organization\Api\MembershipQueries;
use App\Organization\Api\PingSettingsQueries;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketNotFound;
use App\Ticketing\Api\TicketQueries;
use App\Ticketing\Api\TicketVisibilities;

/**
 * The checks run cheapest first and every refusal comes before the ping window is taken, so a refused ping never uses
 * up the sender's slot. The ping shows at once: quiet hours do not hold it yet.
 */
final readonly class SendPing implements PingCommands
{
    public function __construct(
        private TicketQueries $ticketQueries,
        private TicketVisibilities $ticketVisibilities,
        private MembershipQueries $membershipQueries,
        private PingSettingsQueries $pingSettingsQueries,
        private PingWindow $pingWindow,
        private Pings $pings,
        private PingEvents $pingEvents,
    ) {
    }

    public function ping(OrganizationId $organizationId, string $ticketKey, UserId $fromUserId, UserId $toUserId, string $body, \DateTimeImmutable $at): PingSlot
    {
        if ($fromUserId->equals($toUserId)) {
            throw new PingToYourself();
        }

        $length = mb_strlen(trim($body));
        if (0 === $length || $length > Ping::MAX_BODY_LENGTH) {
            throw new PingMessageRefused(Ping::MAX_BODY_LENGTH);
        }

        $ticket = $this->ticketQueries->pageOf($ticketKey, $this->ticketVisibilities->of($organizationId, $fromUserId))
            ?? throw new TicketNotFound('The organization has no ticket with that key the sender may see.');

        $isMember = [] !== $this->membershipQueries->membersAmong($organizationId, [$toUserId]);
        if (!$isMember || !$this->ticketVisibilities->of($organizationId, $toUserId)->isVisible($ticket->visibilityFacts)) {
            throw new PingRecipientCannotView();
        }

        $pingId = $this->pings->nextIdentity();
        $limitHours = $this->pingSettingsQueries->pingLimitHoursOf($organizationId);
        $this->pingWindow->take($organizationId, $fromUserId, $toUserId, $ticket->id, $pingId, $limitHours, $at);

        try {
            $this->pings->add(Ping::send($pingId, $organizationId, $ticket->id, $fromUserId, $toUserId, $body, null, $at));
            $this->pingEvents->publish(new PingSent($organizationId->toString(), $ticket->id->toString(), $pingId->toString(), $fromUserId->toString(), $toUserId->toString(), $fromUserId->toString(), $at));
        } catch (\Throwable $failure) {
            try {
                $this->pingWindow->release($organizationId, $fromUserId, $toUserId, $ticket->id, $pingId);
            } catch (PingsPaused) {
                // The store went down meanwhile; the slot then ends by itself after the ping limit, and the cause of
                // the failure matters more than this.
            }

            throw $failure;
        }

        return new PingSlot($organizationId, $fromUserId, $toUserId, $ticket->id, $pingId->toString(), $at->add(new \DateInterval(\sprintf('PT%dH', $limitHours))));
    }

    public function releaseSlot(PingSlot $slot): void
    {
        $this->pingWindow->release($slot->organizationId, $slot->fromUserId, $slot->toUserId, $slot->ticketId, new PingId($slot->pingId));
    }
}
