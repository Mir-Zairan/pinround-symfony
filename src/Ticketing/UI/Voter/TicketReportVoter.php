<?php

declare(strict_types=1);

namespace App\Ticketing\UI\Voter;

use App\Organization\Api\CurrentActor;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\UI\Voter\DenyByDefaultVoter;
use App\Ticketing\Api\TicketPermission;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;

/**
 * Decides TicketPermission::REPORT on an organization (ADR-0038): every member of it may report a problem, whatever
 * their roles, under that organization as tenant. Denied with nobody logged in and for another organization.
 *
 * @extends DenyByDefaultVoter<OrganizationId>
 */
final class TicketReportVoter extends DenyByDefaultVoter
{
    public function __construct(private readonly CurrentActor $currentActor, private readonly TenantContext $tenantContext)
    {
    }

    protected function attributes(): array
    {
        return [TicketPermission::REPORT];
    }

    protected function subjectClass(): string
    {
        return OrganizationId::class;
    }

    protected function grants(string $attribute, object $subject, TokenInterface $token, ?Vote $vote): bool
    {
        $actor = $this->currentActor->actorFor($token->getUser());
        $tenantId = $this->tenantContext->get();

        return null !== $actor && null !== $tenantId && $tenantId->equals($actor->organizationId()) && $subject->equals($actor->organizationId());
    }
}
