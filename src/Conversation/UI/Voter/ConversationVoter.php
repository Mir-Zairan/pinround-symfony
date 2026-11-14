<?php

declare(strict_types=1);

namespace App\Conversation\UI\Voter;

use App\Conversation\Api\ConversationPermission;
use App\Identity\Api\CurrentUser;
use App\Organization\Api\CurrentActor;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\UI\Voter\DenyByDefaultVoter;
use App\Ticketing\Api\TicketPermission;
use App\Ticketing\Api\TicketVisibilities;
use App\Ticketing\Api\TicketVisibilityFacts;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;

/**
 * Decides the ConversationPermission attributes on one ticket. COMMENT: the ticket visibility predicate of the token's
 * user, so anyone who may see the ticket comments, an admin with no team role too (personas-and-roles.md, "Comment,
 * reply"). ASK (personas-and-roles.md, "Ask a question"): only someone TicketPermission VIEW lets see the ticket, and
 * of them an Admin, a Manager, a Lead or Member of the ticket's team, or its reporter; a Viewer of the team is denied,
 * also when they reported the ticket. Both are denied with nobody logged in, with no tenant (the request's user is no
 * member), and for a ticket of another organization.
 *
 * Reads the viewer's roles under row-level security, so it needs the request's open transaction under the tenant.
 *
 * @extends DenyByDefaultVoter<TicketVisibilityFacts>
 */
final class ConversationVoter extends DenyByDefaultVoter
{
    public function __construct(
        private readonly CurrentUser $currentUser,
        private readonly TenantContext $tenantContext,
        private readonly TicketVisibilities $ticketVisibilities,
        private readonly CurrentActor $currentActor,
        private readonly AccessDecisionManagerInterface $accessDecisionManager,
    ) {
    }

    protected function attributes(): array
    {
        return [ConversationPermission::COMMENT, ConversationPermission::ASK];
    }

    protected function subjectClass(): string
    {
        return TicketVisibilityFacts::class;
    }

    protected function grants(string $attribute, object $subject, TokenInterface $token, ?Vote $vote): bool
    {
        return match ($attribute) {
            ConversationPermission::COMMENT => $this->mayComment($subject, $token),
            ConversationPermission::ASK => $this->mayAsk($subject, $token),
            default => false,
        };
    }

    private function mayComment(TicketVisibilityFacts $ticket, TokenInterface $token): bool
    {
        $userId = $this->currentUser->idOf($token->getUser());
        $organizationId = $this->tenantContext->get();
        if (null === $userId || null === $organizationId || !$ticket->organizationId->equals($organizationId)) {
            return false;
        }

        return $this->ticketVisibilities->of($organizationId, $userId)->isVisible($ticket);
    }

    private function mayAsk(TicketVisibilityFacts $ticket, TokenInterface $token): bool
    {
        $actor = $this->currentActor->actorFor($token->getUser());
        if (null === $actor || !$ticket->organizationId->equals($actor->organizationId())) {
            return false;
        }
        if (!$this->accessDecisionManager->decide($token, [TicketPermission::VIEW], $ticket)) {
            return false;
        }
        $roles = $actor->roles();
        if ($roles->isAdmin() || $roles->isManager()) {
            return true;
        }
        if (null !== $ticket->teamId) {
            if ($roles->isLeadOf($ticket->teamId) || $roles->isMemberOf($ticket->teamId)) {
                return true;
            }
            // The matrix's "only as reporter" is the Other user column's; its Viewer column has no exception.
            if ($roles->isViewerOf($ticket->teamId)) {
                return false;
            }
        }

        return $ticket->reporterId->equals($actor->userId());
    }
}
