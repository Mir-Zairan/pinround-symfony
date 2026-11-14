<?php

declare(strict_types=1);

namespace App\Notification\UI\Component;

use App\Notification\Application\Bell\BellInbox;
use App\Notification\UI\Controller\BellController;
use App\Organization\Api\CurrentActor;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * The header's bell (dialogs-and-popovers.md, Pings popover): the button with the count of the viewer's open
 * notifications and a popover whose frame loads GET /bell when it opens. It renders nothing for a visitor with no actor.
 */
#[AsTwigComponent]
final class Bell
{
    /** Null when there is no actor, and then the component renders nothing. */
    public ?int $openCount = null;

    public function __construct(
        private readonly BellInbox $bellInbox,
        private readonly CurrentActor $currentActor,
        private readonly Security $security,
        private readonly ClockInterface $clock,
    ) {
    }

    #[PostMount]
    public function countOpen(): void
    {
        $actor = $this->currentActor->actorFor($this->security->getUser());
        if (null === $actor) {
            return;
        }
        $this->openCount = $this->bellInbox->openCountOf($actor->userId(), $this->clock->now());
    }

    public function frameRoute(): string
    {
        return BellController::ROUTE;
    }

    public function frameId(): string
    {
        return BellController::FRAME_ID;
    }
}
