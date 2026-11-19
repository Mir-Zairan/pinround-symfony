<?php

declare(strict_types=1);

namespace App\Review\UI\Media;

use App\Media\Api\MediaTargetAuthorizer;
use App\Organization\Api\CurrentActor;
use App\Review\Api\ReportDrafts;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\ProjectQueries;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Lets a person upload a picture for a report draft that is not made yet (ADR-0039, create_report), by the key of the
 * project the report is for. Everyone in the organization may report a problem (ADR-0038), so any project of the
 * organization that a team owns is a target; a key that matches none, or a project no team owns, is refused, as Send
 * could not make a ticket there. The draft checks again when it is saved that the picture is the person's own. The
 * decision is for the request's logged-in actor, so anyone else asked about, and an organization other than the
 * actor's, is refused.
 */
final readonly class NewReportMediaTarget implements MediaTargetAuthorizer
{
    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private CurrentActor $currentActor,
        private ProjectQueries $projectQueries,
    ) {
    }

    public function targetKind(): string
    {
        return ReportDrafts::NEW_REPORT_UPLOAD_TARGET_KIND;
    }

    public function mayAttach(OrganizationId $organizationId, UserId $userId, string $targetId): bool
    {
        $actor = $this->currentActor->actorFor($this->tokenStorage->getToken()?->getUser());
        if (null === $actor || !$actor->userId()->equals($userId) || !$actor->organizationId()->equals($organizationId)) {
            return false;
        }
        $project = $this->projectQueries->projectByKey($targetId);

        return null !== $project && $project->key() === $targetId && null !== $project->teamId();
    }
}
