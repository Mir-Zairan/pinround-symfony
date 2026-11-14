<?php

declare(strict_types=1);

namespace App\Conversation\UI\Voter;

use App\Conversation\Api\ConversationPermission;
use App\Identity\Api\CurrentUser;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\UI\Voter\DenyByDefaultVoter;
use App\Ticketing\Api\TicketVisibilities;
use App\Ticketing\Api\TicketVisibilityFacts;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;

/**
 * Decides ConversationPermission COMMENT on one ticket: the ticket visibility predicate of the token's user, so anyone
 * who may see the ticket comments, an admin with no team role too (personas-and-roles.md, "Comment, reply"). Denied
 * with nobody logged in, with no tenant (the request's user is no member), and for a ticket of another organization.
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
    ) {
    }

    protected function attributes(): array
    {
        return [ConversationPermission::COMMENT];
    }

    protected function subjectClass(): string
    {
        return TicketVisibilityFacts::class;
    }

    protected function grants(string $attribute, object $subject, TokenInterface $token, ?Vote $vote): bool
    {
        $userId = $this->currentUser->idOf($token->getUser());
        $organizationId = $this->tenantContext->get();
        if (null === $userId || null === $organizationId || !$subject->organizationId->equals($organizationId)) {
            return false;
        }

        return $this->ticketVisibilities->of($organizationId, $userId)->isVisible($subject);
    }
}
