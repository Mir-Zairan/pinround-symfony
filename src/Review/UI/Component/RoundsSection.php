<?php

declare(strict_types=1);

namespace App\Review\UI\Component;

use App\Organization\Api\CurrentActor;
use App\Review\UI\RoundCards;
use App\Review\UI\RoundsSectionView;
use App\SharedKernel\Domain\TicketId;
use App\Ticketing\Api\TicketVisibility;
use Psr\Clock\ClockInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The ticket page's Rounds section (P05.T4, ticket-page.md, Rounds): Round 0 with its screenshot and pins, the review
 * rounds, shared drafts and the viewer's own draft. Ticketing's UI may not use Review's Api, so the ticket page embeds
 * it by name, as it does Conversation's ThreadFrame. The caller has already decided that the viewer may see the
 * ticket; RoundQueries checks it again before it reads a round, so a hidden ticket gets the empty state, never a round.
 */
#[AsTwigComponent(name: 'RoundsSection', template: '@Review/components/RoundsSection.html.twig')]
final class RoundsSection
{
    public const string EMPTY = 'No rounds yet. The developer starts round 1 from Claude Code: it shoots the screens and puts them here.';

    /** The ticket's id. */
    public string $ticketId = '';

    /** The viewer's visibility the page has already built, so it is not read again; null builds it. */
    public ?TicketVisibility $visibility = null;

    public function __construct(
        private readonly RoundCards $roundCards,
        private readonly CurrentActor $currentActor,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly ClockInterface $clock,
    ) {
    }

    public function view(): RoundsSectionView
    {
        $actor = $this->currentActor->actorFor($this->tokenStorage->getToken()?->getUser()) ?? throw new AccessDeniedException();

        return $this->roundCards->forTicket($actor->organizationId(), new TicketId($this->ticketId), $actor->userId(), $this->clock->now(), $this->visibility);
    }
}
