<?php

declare(strict_types=1);

namespace App\Review\UI\Voter;

use App\Organization\Api\CurrentActor;
use App\Review\Api\CheckItemAccessFacts;
use App\Review\Api\ChecklistPermission;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TicketTurnSnapshot;
use App\SharedKernel\Domain\UserId;
use App\SharedKernel\UI\Voter\DenyByDefaultVoter;
use App\Ticketing\Api\TicketPermission;
use App\Ticketing\Api\TicketTurnQueries;
use App\Ticketing\Api\TicketVisibilityFacts;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;

/**
 * Decides ChecklistPermission MARK_FIXED and CHECK on one checklist item (ADR-0013, FR-RND-04): MARK_FIXED the ticket's
 * assignee, CHECK the ticket's tester, or any tester of its team while the ticket has none stored, each while
 * TicketPermission VIEW and EDIT both grant them the ticket. The lead, other members, a Manager and an Admin with
 * neither role are denied. Denied with nobody logged in, when the tenant is not the actor's organization, for an item
 * of another organization (the ticket is not read for those), and when the ticket's facts cannot be read.
 *
 * Reads the item's ticket under row-level security, so it needs the request's open transaction under the actor's
 * organization.
 *
 * @extends DenyByDefaultVoter<CheckItemAccessFacts>
 */
final class CheckItemVoter extends DenyByDefaultVoter
{
    public function __construct(
        private readonly CurrentActor $currentActor,
        private readonly TenantContext $tenantContext,
        private readonly TicketTurnQueries $ticketTurnQueries,
        private readonly AccessDecisionManagerInterface $accessDecisionManager,
    ) {
    }

    protected function attributes(): array
    {
        return [ChecklistPermission::MARK_FIXED, ChecklistPermission::CHECK];
    }

    protected function subjectClass(): string
    {
        return CheckItemAccessFacts::class;
    }

    protected function grants(string $attribute, object $subject, TokenInterface $token, ?Vote $vote): bool
    {
        $actor = $this->currentActor->actorFor($token->getUser());
        $tenantId = $this->tenantContext->get();
        if (null === $actor || null === $tenantId || !$tenantId->equals($actor->organizationId())) {
            return false;
        }
        if (!$subject->organizationId->equals($actor->organizationId())) {
            return false;
        }
        $ticket = $this->ticketOf($subject);
        if (null === $ticket) {
            return false;
        }
        $hasTheRole = ChecklistPermission::MARK_FIXED === $attribute
            ? null !== $ticket->assigneeId && $ticket->assigneeId->equals($actor->userId())
            : self::isTesterOf($ticket, $actor->userId());
        if (!$hasTheRole) {
            return false;
        }
        $visibilityFacts = TicketVisibilityFacts::ofSnapshot($subject->organizationId, $ticket);

        return $this->accessDecisionManager->decide($token, [TicketPermission::VIEW], $visibilityFacts)
            && $this->accessDecisionManager->decide($token, [TicketPermission::EDIT], $visibilityFacts);
    }

    /**
     * The tester rule of VerdictVoter: the stored tester, else any tester of the ticket's team.
     */
    private static function isTesterOf(TicketTurnSnapshot $ticket, UserId $userId): bool
    {
        if (null !== $ticket->testerId) {
            return $ticket->testerId->equals($userId);
        }
        foreach ($ticket->testerIds as $teamTesterId) {
            if ($teamTesterId->equals($userId)) {
                return true;
            }
        }

        return false;
    }

    private function ticketOf(CheckItemAccessFacts $item): ?TicketTurnSnapshot
    {
        try {
            return $this->ticketTurnQueries->factsOf($item->ticketId);
        } catch (\LogicException) {
            return null;
        }
    }
}
