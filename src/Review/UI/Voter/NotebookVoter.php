<?php

declare(strict_types=1);

namespace App\Review\UI\Voter;

use App\Organization\Api\CurrentActor;
use App\Review\Api\NotebookPermission;
use App\Review\Api\NotebookView;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\UI\Voter\DenyByDefaultVoter;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;

/**
 * Decides NotebookPermission VIEW and EDIT on a notebook (ADR-0046): its owner alone, under the notebook's organization
 * as tenant, from a browser session or a personal access token alike. No admin, lead or team member, and nobody of
 * another organization. Denied with nobody logged in, and when the tenant is not the actor's organization.
 *
 * @extends DenyByDefaultVoter<NotebookView>
 */
final class NotebookVoter extends DenyByDefaultVoter
{
    public function __construct(
        private readonly CurrentActor $currentActor,
        private readonly TenantContext $tenantContext,
    ) {
    }

    protected function attributes(): array
    {
        return [NotebookPermission::VIEW, NotebookPermission::EDIT];
    }

    protected function subjectClass(): string
    {
        return NotebookView::class;
    }

    protected function grants(string $attribute, object $subject, TokenInterface $token, ?Vote $vote): bool
    {
        $actor = $this->currentActor->actorFor($token->getUser());
        $tenantId = $this->tenantContext->get();
        if (null === $actor || null === $tenantId || !$tenantId->equals($actor->organizationId())) {
            return false;
        }

        return $subject->organizationId->equals($tenantId) && $subject->ownerId->equals($actor->userId());
    }
}
