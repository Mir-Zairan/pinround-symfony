<?php

declare(strict_types=1);

namespace App\Review\Application\ReportDraft;

use App\Review\Api\AddedReportDraftScreenshot;
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
use App\Review\Application\DraftRoundContents;
use App\Review\Application\PicturesToKeep;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\ProblemReport;
use App\Ticketing\Api\ProjectChoice;
use App\Ticketing\Api\ProjectQueries;
use App\Ticketing\Api\ReportGiveTo;
use App\Ticketing\Api\ReportGiveToQueries;
use App\Ticketing\Api\TicketCommands;
use App\Ticketing\Api\TicketKeyQueries;

/**
 * ReportDrafts on rvw_report_draft (ADR-0039, ADR-0040). Send makes the ticket through Ticketing's TicketCommands::report,
 * as the person reporting it, in the project's team, with the default type and priority of Report a problem (Bug,
 * Normal) and the person "Give it to" offered, or none, so it starts in To do or Backlog with the check reasons and
 * visibility its words give; then Round 0 through RoundCommands::createReport; then removes the draft.
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
        private PicturesToKeep $picturesToKeep,
        private TenantContext $tenantContext,
        private DraftRoundContents $draftRoundContents,
        private ReportGiveToQueries $reportGiveToQueries,
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

    public function createFromDraftRound(string $roundId, UserId $owner, \DateTimeImmutable $at): string
    {
        $organizationId = $this->tenantContext->require();
        $draftRound = $this->draftRoundContents->contentsOf($roundId, $owner);
        $ticketKey = $this->ticketKeyQueries->keyOf($draftRound->ticketId) ?? throw new \LogicException('The ticket of a draft round the tenant has is the tenant\'s.');
        $foundWhileTesting = \sprintf(self::FOUND_WHILE_TESTING, $ticketKey);
        $title = $foundWhileTesting;
        foreach ($draftRound->screenshots as $screenshot) {
            if (null !== $screenshot->title && '' !== trim($screenshot->title)) {
                $title = $screenshot->title;
                break;
            }
        }
        $project = $this->reportableProject(self::projectKeyOf($ticketKey));
        $checked = ReportDraftContentRules::checked(new ReportDraftContent($title, $foundWhileTesting, $draftRound->screenshots));
        $this->refuseUnlessOwnUsablePictures($organizationId, $owner, $checked->screenshots);

        // Only once every rule of a report draft holds, so a refused move writes nothing at all.
        $this->draftRoundContents->deleteAsRead($draftRound, $owner, $at);
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

    public function addScreenshot(string $draftId, UserId $owner, ScreenshotWithPins $screenshot, \DateTimeImmutable $at): AddedReportDraftScreenshot
    {
        $draft = $this->rows->ofOwner($draftId, $owner, lock: true) ?? throw ReportDraftRefused::notFound();
        $checked = ReportDraftContentRules::checkedScreenshot($screenshot);
        foreach ($draft->screenshots as $saved) {
            if ($saved->mediaId === $checked->mediaId) {
                return new AddedReportDraftScreenshot(true, \count($draft->screenshots));
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

        return new AddedReportDraftScreenshot(false, \count($content->screenshots));
    }

    public function delete(string $draftId, UserId $owner): void
    {
        if (!$this->rows->delete($draftId, $owner)) {
            throw ReportDraftRefused::notFound();
        }
    }

    public function send(string $draftId, UserId $owner, int $expectedVersion, ?string $assigneeId, \DateTimeImmutable $at): string
    {
        $organizationId = $this->tenantContext->require();
        $draft = $this->rows->ofOwner($draftId, $owner, lock: true) ?? throw ReportDraftRefused::notFound();
        if ($draft->version !== $expectedVersion) {
            throw ReportDraftRefused::because(ReportDraftRefused::DRAFT_CHANGED);
        }
        $project = $this->reportableProject($draft->projectKey);
        $assignee = $this->offeredAssigneeOf($organizationId, $owner, $project, $assigneeId);

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
            $assignee,
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
     * @throws ReportDraftRefused ReportGiveTo::NOT_OFFERED for a person "Give it to" does not offer $owner, also when
     *                            $owner has no role in the project's team
     */
    private function offeredAssigneeOf(OrganizationId $organizationId, UserId $owner, ProjectChoice $project, ?string $assigneeId): ?UserId
    {
        if (null === $assigneeId) {
            return null;
        }
        $teamId = $project->teamId();
        $giveTo = null === $teamId ? null : $this->reportGiveToQueries->giveToOf($organizationId, $owner, $teamId);
        if (null === $giveTo || !$giveTo->offers($assigneeId)) {
            throw ReportDraftRefused::because(ReportGiveTo::NOT_OFFERED);
        }

        return new UserId($assigneeId);
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
        $refusal = $this->picturesToKeep->refusalOf($organizationId, $owner, array_map(static fn (ScreenshotWithPins $screenshot): string => $screenshot->mediaId, $screenshots));
        if (null !== $refusal) {
            throw ReportDraftRefused::because($refusal);
        }
    }

    /**
     * A ticket key is its project's key, a dash and a number (Ticketing's TicketKey), so the project key is all before
     * the last dash.
     */
    private static function projectKeyOf(string $ticketKey): string
    {
        $dash = strrpos($ticketKey, '-');

        return false === $dash ? $ticketKey : substr($ticketKey, 0, $dash);
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
