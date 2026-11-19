<?php

declare(strict_types=1);

namespace App\Review\Application\ReportDraft;

use App\Media\Api\MediaImageStatus;
use App\Media\Api\UploadedMedia;
use App\Review\Api\PlacedPin;
use App\Review\Api\ReportDraftContent;
use App\Review\Api\ReportDraftCursor;
use App\Review\Api\ReportDraftRefused;
use App\Review\Api\ReportDrafts;
use App\Review\Api\ReportDraftsPage;
use App\Review\Api\ReportDraftView;
use App\Review\Api\RoundCommands;
use App\Review\Api\RoundRefused;
use App\Review\Api\ScreenshotWithPins;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\ProblemReport;
use App\Ticketing\Api\ProjectChoice;
use App\Ticketing\Api\ProjectQueries;
use App\Ticketing\Api\TicketCommands;
use App\Ticketing\Api\TicketKeyQueries;

/**
 * ReportDrafts on rvw_report_draft (ADR-0039, ADR-0040). Send makes the ticket through Ticketing's TicketCommands::report,
 * as the person reporting it, in the project's team, with the default type and priority of Report a problem (Bug,
 * Normal) and no assignee, so it starts in Backlog with the check reasons and visibility its words give; then Round 0
 * through RoundCommands::createReport; then removes the draft.
 * Holds no state (ADR-0008).
 */
final readonly class UseCaseReportDrafts implements ReportDrafts
{
    private const string DEFAULT_TYPE = 'bug';
    private const string DEFAULT_PRIORITY = 'normal';

    public function __construct(
        private ReportDraftRows $rows,
        private ProjectQueries $projectQueries,
        private TicketCommands $ticketCommands,
        private TicketKeyQueries $ticketKeyQueries,
        private RoundCommands $roundCommands,
        private UploadedMedia $uploadedMedia,
        private TenantContext $tenantContext,
    ) {
    }

    public function create(OrganizationId $organizationId, UserId $owner, string $projectKey, ReportDraftContent $content, \DateTimeImmutable $at): string
    {
        $this->requireTenant($organizationId);
        $project = $this->reportableProject($projectKey);
        $checked = ReportDraftContentRules::checked($content);
        $this->refuseUnlessOwnUsablePictures($organizationId, $owner, $checked->screenshots);
        $id = $this->rows->nextId();
        $this->rows->insert($organizationId, $id, $owner, $project->key(), $checked, $at);

        return $id;
    }

    public function forOwner(UserId $owner, ?ReportDraftCursor $after = null): ReportDraftsPage
    {
        return $this->rows->forOwner($owner, $after, self::DRAFTS_PER_PAGE);
    }

    public function ofOwner(string $draftId, UserId $owner): ?ReportDraftView
    {
        return $this->rows->ofOwner($draftId, $owner);
    }

    public function update(string $draftId, UserId $owner, int $expectedVersion, ReportDraftContent $content, \DateTimeImmutable $at): void
    {
        $draft = $this->rows->ofOwner($draftId, $owner) ?? throw ReportDraftRefused::notFound();
        $checked = ReportDraftContentRules::checked($content);
        $this->refuseUnlessOwnUsablePictures($this->tenantContext->require(), $owner, $checked->screenshots);
        if (!$this->rows->update($draft->id, $owner, $expectedVersion, $checked, $at)) {
            throw ReportDraftRefused::because(ReportDraftRefused::DRAFT_CHANGED);
        }
    }

    public function addScreenshot(string $draftId, UserId $owner, ScreenshotWithPins $screenshot, \DateTimeImmutable $at): void
    {
        $draft = $this->rows->ofOwner($draftId, $owner, lock: true) ?? throw ReportDraftRefused::notFound();
        $checked = ReportDraftContentRules::checkedScreenshot($screenshot);
        foreach ($draft->screenshots as $saved) {
            if ($saved->mediaId === $checked->mediaId) {
                return;
            }
        }
        if (\count($draft->screenshots) >= self::MAX_SCREENSHOTS) {
            throw ReportDraftRefused::because(ReportDraftRefused::TOO_MANY_SCREENSHOTS);
        }
        $this->refuseUnlessOwnUsablePictures($this->tenantContext->require(), $owner, [$checked]);
        $content = new ReportDraftContent($draft->title, $draft->detail, [...$draft->screenshots, $checked]);
        if (!$this->rows->update($draft->id, $owner, $draft->version, $content, $at)) {
            throw ReportDraftRefused::because(ReportDraftRefused::DRAFT_CHANGED);
        }
    }

    public function delete(string $draftId, UserId $owner): void
    {
        if (!$this->rows->delete($draftId, $owner)) {
            throw ReportDraftRefused::notFound();
        }
    }

    public function send(string $draftId, UserId $owner, int $expectedVersion, \DateTimeImmutable $at): string
    {
        $organizationId = $this->tenantContext->require();
        $draft = $this->rows->ofOwner($draftId, $owner, lock: true) ?? throw ReportDraftRefused::notFound();
        if ($draft->version !== $expectedVersion) {
            throw ReportDraftRefused::because(ReportDraftRefused::DRAFT_CHANGED);
        }
        $project = $this->reportableProject($draft->projectKey);

        $pinNotes = array_merge([], ...array_map(static fn (ScreenshotWithPins $screenshot): array => array_map(static fn (PlacedPin $pin): string => $pin->note, $screenshot->pins), $draft->screenshots));
        // Through report, as Report a problem makes a ticket: Ticketing works out the check reasons and team-only
        // visibility from the words, so a draft an agent filed is checked like any report.
        $key = $this->ticketCommands->report(new ProblemReport(
            $organizationId,
            $owner,
            $project->id(),
            $project->teamId(),
            $draft->title,
            $draft->detail,
            self::DEFAULT_TYPE,
            self::DEFAULT_PRIORITY,
            null,
            $pinNotes,
            $at,
        ))->key;
        if ([] !== $draft->screenshots) {
            $ticketId = $this->ticketKeyQueries->idOf($key) ?? throw new \LogicException('The ticket Send just made is not found by its key.');
            try {
                $this->roundCommands->createReport($ticketId, $draft->screenshots, $owner, $at);
            } catch (RoundRefused $refused) {
                throw ReportDraftRefused::because($refused->getMessage(), $refused);
            }
        }
        if (!$this->rows->delete($draft->id, $owner, $draft->version)) {
            throw new \LogicException('The report draft Send holds locked was changed or removed meanwhile.');
        }

        return $key;
    }

    /**
     * @throws ReportDraftRefused PROJECT_NOT_FOUND or PROJECT_HAS_NO_TEAM
     */
    private function reportableProject(string $projectKey): ProjectChoice
    {
        $project = $this->projectQueries->projectByKey($projectKey) ?? throw ReportDraftRefused::because(ReportDraftRefused::PROJECT_NOT_FOUND);
        if (null === $project->teamId()) {
            throw ReportDraftRefused::because(ReportDraftRefused::PROJECT_HAS_NO_TEAM);
        }

        return $project;
    }

    /**
     * A draft may hold a picture still processing, as an agent adds it right after its upload; Send waits for it.
     *
     * @param list<ScreenshotWithPins> $screenshots
     *
     * @throws ReportDraftRefused with RoundRefused's MEDIA_NOT_UPLOADED_BY_YOU or MEDIA_REJECTED
     */
    private function refuseUnlessOwnUsablePictures(OrganizationId $organizationId, UserId $owner, array $screenshots): void
    {
        if ([] === $screenshots) {
            return;
        }
        $mediaIds = array_map(static fn (ScreenshotWithPins $screenshot): string => $screenshot->mediaId, $screenshots);
        $states = $this->uploadedMedia->statesOf($organizationId, $owner, $mediaIds);
        foreach ($mediaIds as $mediaId) {
            $refusal = match ($states[$mediaId] ?? null) {
                null => RoundRefused::MEDIA_NOT_UPLOADED_BY_YOU,
                MediaImageStatus::Rejected => RoundRefused::MEDIA_REJECTED,
                MediaImageStatus::Pending, MediaImageStatus::Ready => null,
            };
            if (null !== $refusal) {
                throw ReportDraftRefused::because($refusal);
            }
        }
    }

    /**
     * @throws \LogicException when no tenant is set or it is another organization
     */
    private function requireTenant(OrganizationId $organizationId): void
    {
        if (!$this->tenantContext->require()->equals($organizationId)) {
            throw new \LogicException('A report draft is saved under its organization as tenant.');
        }
    }
}
