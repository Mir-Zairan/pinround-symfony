<?php

declare(strict_types=1);

namespace App\Review\UI\Voter;

use App\Organization\Api\CurrentActor;
use App\Review\Api\RoundAccessFacts;
use App\Review\Api\RoundPermission;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\UI\Voter\DenyByDefaultVoter;
use App\Ticketing\Api\TicketPermission;
use App\Ticketing\Api\TicketTurnQueries;
use App\Ticketing\Api\TicketVisibilityFacts;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;

/**
 * Decides RoundPermission VIEW and EDIT on one round (ADR-0013, ADR-0017). An unshared draft is its owner's alone: not
 * a lead's or an admin's either, and its ticket is not read. A shared round is whoever TicketPermission VIEW lets see
 * its ticket for VIEW, and whoever TicketPermission VIEW and EDIT both grant for EDIT. A deleted draft is nobody's.
 * Denied with nobody logged in, when the tenant is not the actor's organization, for a round of another organization,
 * and for a shared round whose ticket the tenant has not or whose facts cannot be read.
 *
 * Callers must answer a denied VIEW on an unshared draft with 404, never 403, the same as an unknown round id, so a
 * draft's existence does not leak to anyone but its owner.
 *
 * Reads a shared round's ticket under row-level security, so it needs the request's open transaction under the actor's
 * organization.
 *
 * @extends DenyByDefaultVoter<RoundAccessFacts>
 */
final class RoundVoter extends DenyByDefaultVoter
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
        return [RoundPermission::VIEW, RoundPermission::EDIT];
    }

    protected function subjectClass(): string
    {
        return RoundAccessFacts::class;
    }

    protected function grants(string $attribute, object $subject, TokenInterface $token, ?Vote $vote): bool
    {
        $actor = $this->currentActor->actorFor($token->getUser());
        $tenantId = $this->tenantContext->get();
        if (null === $actor || null === $tenantId || !$tenantId->equals($actor->organizationId())) {
            return false;
        }
        if (!$subject->organizationId->equals($actor->organizationId()) || $subject->isDeleted) {
            return false;
        }
        if ($subject->isUnsharedDraft) {
            return $subject->ownerId->equals($actor->userId());
        }
        $ticket = $this->ticketOf($subject);
        if (null === $ticket || !$this->accessDecisionManager->decide($token, [TicketPermission::VIEW], $ticket)) {
            return false;
        }

        return match ($attribute) {
            RoundPermission::VIEW => true,
            RoundPermission::EDIT => $this->accessDecisionManager->decide($token, [TicketPermission::EDIT], $ticket),
            default => false,
        };
    }

    private function ticketOf(RoundAccessFacts $round): ?TicketVisibilityFacts
    {
        try {
            $ticket = $this->ticketTurnQueries->factsOf($round->ticketId);
        } catch (\LogicException) {
            return null;
        }

        return null === $ticket ? null : TicketVisibilityFacts::ofSnapshot($round->organizationId, $ticket);
    }
}
