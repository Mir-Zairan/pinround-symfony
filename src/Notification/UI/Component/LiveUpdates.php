<?php

declare(strict_types=1);

namespace App\Notification\UI\Component;

use App\Notification\Application\Realtime\RealtimeSubscriptions;
use App\Notification\UI\Controller\RealtimeTokenController;
use App\Organization\Api\CurrentActor;
use App\SharedKernel\Domain\TicketId;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * The live updates of a page (realtime-and-notifications.md, Mercure topics): the hub URL for the viewer's own topic
 * and, on a ticket page, the ticket's topic, the URL that renews the subscriber cookie, and the "Updates paused" banner
 * (ux-principles.md) a live-updates controller shows when the stream is lost. It renders nothing for a visitor with no
 * actor. It sets no cookie: the controller asks RealtimeTokenController for one, which checks the ticket again.
 */
#[AsTwigComponent]
final class LiveUpdates
{
    /** The ticket page's ticket id; null on a page that follows only the viewer's own topic. */
    public ?string $ticketId = null;

    /** Null when there is no actor, and then the component renders nothing. */
    public ?string $subscribeUrl = null;

    public function __construct(
        private readonly RealtimeSubscriptions $realtimeSubscriptions,
        private readonly CurrentActor $currentActor,
        private readonly Security $security,
    ) {
    }

    /**
     * @throws \InvalidArgumentException when the ticket id is not a canonical UUIDv7
     */
    #[PostMount]
    public function findSubscribeUrl(): void
    {
        $ticketId = null === $this->ticketId ? null : new TicketId($this->ticketId);
        $actor = $this->currentActor->actorFor($this->security->getUser());
        if (null === $actor) {
            return;
        }
        $this->subscribeUrl = $this->realtimeSubscriptions->subscribeUrlOf($actor->organizationId(), $actor->userId(), $ticketId);
        $this->ticketId = $ticketId?->toString();
    }

    /**
     * The element is data-turbo-permanent, and Turbo keeps it across a visit to a page with an element of the same id.
     * The id follows the subscribe URL, so a page for another ticket or another person gets a new element and stream.
     */
    public function elementId(): string
    {
        return 'live-updates-'.hash('xxh128', (string) $this->subscribeUrl);
    }

    public function tokenRoute(): string
    {
        return RealtimeTokenController::ROUTE;
    }
}
