<?php

declare(strict_types=1);

namespace App\Review\UI\Component;

use App\Organization\Api\CurrentActor;
use App\Review\Api\ReportDrafts;
use App\Review\UI\ReportDraftController;
use App\SharedKernel\Application\TenantContext;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * "Report drafts" with how many the person has, a link to their list (ReportDraftController::list), for pages of other
 * modules that render it by name (Web's My reports), so the drafts an agent or the extension saved can be found. The
 * count is the first page of the list, one query: "20+" when more follow, as the list shows them 20 at a time.
 *
 * Renders nothing when the request has no logged-in person under a tenant, who has no drafts to list. Run it inside the
 * request transaction.
 */
#[AsTwigComponent(name: 'ReportDraftsLink', template: '@Review/components/ReportDraftsLink.html.twig')]
final class ReportDraftsLink
{
    /** "3", "0", or "20+" when the list holds more than one page; null when the link is not shown. */
    public ?string $countText = null;

    public function __construct(
        private readonly CurrentActor $currentActor,
        private readonly TenantContext $tenantContext,
        private readonly Security $security,
        private readonly ReportDrafts $reportDrafts,
    ) {
    }

    #[PostMount]
    public function readCount(): void
    {
        $actor = $this->currentActor->actorFor($this->security->getUser());
        if (null === $actor || null === $this->tenantContext->get()) {
            return;
        }
        $firstPage = $this->reportDrafts->forOwner($actor->userId());
        $shownCount = \count($firstPage->drafts);
        $this->countText = null === $firstPage->nextCursor ? (string) $shownCount : $shownCount.'+';
    }

    public function route(): string
    {
        return ReportDraftController::LIST_ROUTE;
    }
}
