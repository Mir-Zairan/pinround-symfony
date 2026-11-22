<?php

declare(strict_types=1);

namespace App\Notification\UI\Component;

use App\Notification\UI\Controller\PingController;
use App\Notification\UI\PingedState;
use App\Organization\Api\CurrentActor;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * One person's Ping button on the ticket page's People and the sheet it opens (dialogs-and-popovers.md, Ping sheet);
 * also the sheet alone, opened as it loads, in a My reports card's frame (PingController::sheet).
 * The page shows it only to someone granted PingPermission::SEND; PingController checks that again on POST. When the
 * viewer pinged the person about the ticket within the limit, it shows "Pinged · again in N h" instead, which is also
 * what PingController's stream answer puts in its place.
 *
 * Renders nothing for the viewer's own row, nor when the request has no actor.
 */
#[AsTwigComponent(name: 'PingSheet', template: '@Notification/components/PingSheet.html.twig')]
final class PingSheet
{
    /** The form's data-failed-toast: assets/app.js shows it after an answer with no Turbo Stream reloads the page. */
    public const string PING_FAILED = 'The ping was not sent. Try again.';

    public string $ticketId = '';

    public string $ticketKey = '';

    public string $ticketTitle = '';

    /** The person to ping, a canonical uuid. */
    public string $toUserId = '';

    public string $toFirstName = '';

    /** The ticket's Now line, which the draft message quotes; null for a ticket no team owns, which has no turn. */
    public ?string $nowText = null;

    /**
     * The user ids of every People row, canonical uuids, so one read of the ping window serves all rows of the page.
     *
     * @var list<string>
     */
    public array $peopleUserIds = [];

    /** Whether the row is the viewer's own, who may not ping themselves. */
    public bool $isViewer = false;

    /**
     * Whether the sheet opens as it loads, with no Ping button of its own: a My reports card's button loaded it
     * (PingController::sheet) and is its trigger.
     */
    public bool $isOpenedOnLoad = false;

    /** Whether there is a Ping button or a pinged state to show; false for the viewer's own row or with no actor. */
    public bool $isShown = false;

    /** "again in 3 h" when the viewer pinged the person within the limit, else null and the Ping button shows. */
    public ?string $againIn = null;

    /** The organization's ping limit, 1, 4, 8 or 24, for the sheet's note. */
    public int $limitHours = 0;

    public function __construct(
        private readonly PingedState $pingedState,
        private readonly CurrentActor $currentActor,
        private readonly Security $security,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Reads the viewer's held ping slots for all People rows once per request and ticket, through PingedState, so a
     * page of many rows asks the ping window once; a row missing from peopleUserIds is asked along with them. The
     * ping limit comes through PingedState too, once per request, in a transaction of its own when none is open.
     *
     * @throws \LogicException when the stored ping limit is not one of 1, 4, 8 or 24, a slot is unreadable, or no
     *                         transaction is open and the request has no tenant
     */
    #[PostMount]
    public function readPingedState(): void
    {
        if ($this->isViewer) {
            return;
        }
        $actor = $this->currentActor->actorFor($this->security->getUser());
        if (null === $actor) {
            return;
        }
        $recipientIds = [];
        foreach ($this->peopleUserIds as $personId) {
            $recipientIds[] = new UserId($personId);
        }
        if (!\in_array($this->toUserId, $this->peopleUserIds, true)) {
            $recipientIds[] = new UserId($this->toUserId);
        }
        $heldUntilById = $this->pingedState->heldUntilOf($actor->organizationId(), $actor->userId(), new TicketId($this->ticketId), $recipientIds);
        $againAt = $heldUntilById[new UserId($this->toUserId)->toString()] ?? null;
        $this->limitHours = $this->pingedState->pingLimitHoursOf($actor->organizationId());
        $this->againIn = null === $againAt ? null : PingedState::againInOf($againAt, $this->clock->now());
        $this->isShown = true;
    }

    /**
     * The element PingController's stream answer replaces.
     */
    public function targetId(): string
    {
        return PingController::targetIdOf($this->ticketKey, $this->toUserId);
    }

    public function route(): string
    {
        return PingController::ROUTE;
    }

    public function csrfId(): string
    {
        return PingController::csrfIdOf($this->ticketKey);
    }

    /**
     * "Hi Lena, could you look at SHOP-1? Now: Omar to fix it." The Now line keeps its own end mark, so the draft has
     * no double ".".
     */
    public function draftMessage(): string
    {
        $draft = \sprintf('Hi %s, could you look at %s?', $this->toFirstName, $this->ticketKey);
        $nowText = trim($this->nowText ?? '');
        if ('' === $nowText) {
            return $draft;
        }

        return \sprintf('%s Now: %s%s', $draft, $nowText, 1 === preg_match('/[.!?]\z/u', $nowText) ? '' : '.');
    }

    public function limitNote(): string
    {
        return \sprintf(
            '%s gets one notification. You can ping again after %d %s (set by your admin).',
            $this->toFirstName,
            $this->limitHours,
            1 === $this->limitHours ? 'hour' : 'hours',
        );
    }
}
