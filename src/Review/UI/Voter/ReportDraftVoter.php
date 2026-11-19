<?php

declare(strict_types=1);

namespace App\Review\UI\Voter;

use App\Identity\Api\LoginAssurance;
use App\Organization\Api\CurrentActor;
use App\Review\Api\ReportDraftPermission;
use App\Review\Api\ReportDraftView;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\UI\Voter\DenyByDefaultVoter;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;

/**
 * Decides ReportDraftPermission VIEW, EDIT and SEND on a report draft (ADR-0039): its owner alone, under the owner's
 * organization as tenant. SEND also needs a browser session: a personal access token never makes a ticket from a
 * draft, so an agent can prepare a report but only a person's click sends it. Denied with nobody logged in, and when
 * the tenant is not the actor's organization.
 *
 * @extends DenyByDefaultVoter<ReportDraftView>
 */
final class ReportDraftVoter extends DenyByDefaultVoter
{
    public const string TOKEN_REFUSAL_REASON = 'Sending a report draft needs your click in pinround, not a personal access token.';

    public function __construct(
        private readonly CurrentActor $currentActor,
        private readonly TenantContext $tenantContext,
        private readonly LoginAssurance $loginAssurance,
    ) {
    }

    protected function attributes(): array
    {
        return [ReportDraftPermission::VIEW, ReportDraftPermission::EDIT, ReportDraftPermission::SEND];
    }

    protected function subjectClass(): string
    {
        return ReportDraftView::class;
    }

    protected function grants(string $attribute, object $subject, TokenInterface $token, ?Vote $vote): bool
    {
        $actor = $this->currentActor->actorFor($token->getUser());
        $tenantId = $this->tenantContext->get();
        if (null === $actor || null === $tenantId || !$tenantId->equals($actor->organizationId())) {
            return false;
        }
        if (ReportDraftPermission::SEND === $attribute && $this->loginAssurance->isTokenAuthenticated($token)) {
            $vote?->addReason(self::TOKEN_REFUSAL_REASON);

            return false;
        }

        return $subject->ownerId->equals($actor->userId());
    }
}
