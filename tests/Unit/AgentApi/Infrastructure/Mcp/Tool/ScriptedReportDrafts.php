<?php

declare(strict_types=1);

namespace App\Tests\Unit\AgentApi\Infrastructure\Mcp\Tool;

use App\Review\Api\AddedReportDraftScreenshot;
use App\Review\Api\ReportDraftContent;
use App\Review\Api\ReportDraftCursor;
use App\Review\Api\ReportDraftRefused;
use App\Review\Api\ReportDrafts;
use App\Review\Api\ReportDraftsPage;
use App\Review\Api\ReportDraftView;
use App\Review\Api\ScreenshotWithPins;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * ReportDrafts as add_report_screenshot uses them: ofOwner() finds only the one draft it holds, by its id exactly and
 * for its owner alone, as the real one does. addScreenshot() reads the pictures again as the real one does under the
 * row's lock: $mediaIdsUnderLock, the pictures another request may have saved since ofOwner(). In the real one's order
 * it refuses a draft ofOwner() does not find as not found, answers a picture in the draft already as added before with
 * nothing saved, also on a full draft, then refuses a full draft; it records a picture only when it adds it.
 */
final class ScriptedReportDrafts implements ReportDrafts
{
    /** @var list<array{string, ScreenshotWithPins, \DateTimeImmutable}> draft id, picture, time */
    public array $added = [];

    /**
     * @param list<string> $mediaIdsUnderLock in lowercase, as the real one keeps them
     */
    public function __construct(private readonly ReportDraftView $draft, private array $mediaIdsUnderLock)
    {
    }

    public function ofOwner(string $draftId, UserId $owner): ?ReportDraftView
    {
        return $this->draft->id === $draftId && $this->draft->ownerId->equals($owner) ? $this->draft : null;
    }

    public function addScreenshot(string $draftId, UserId $owner, ScreenshotWithPins $screenshot, \DateTimeImmutable $at): AddedReportDraftScreenshot
    {
        if (null === $this->ofOwner($draftId, $owner)) {
            throw ReportDraftRefused::notFound();
        }
        $mediaId = strtolower($screenshot->mediaId);
        if (\in_array($mediaId, $this->mediaIdsUnderLock, true)) {
            return new AddedReportDraftScreenshot(true, \count($this->mediaIdsUnderLock));
        }
        if (\count($this->mediaIdsUnderLock) >= self::MAX_SCREENSHOTS) {
            throw ReportDraftRefused::because(ReportDraftRefused::TOO_MANY_SCREENSHOTS);
        }
        $this->mediaIdsUnderLock[] = $mediaId;
        $this->added[] = [$draftId, $screenshot, $at];

        return new AddedReportDraftScreenshot(false, \count($this->mediaIdsUnderLock));
    }

    public function create(OrganizationId $organizationId, UserId $owner, string $projectKey, ReportDraftContent $content, \DateTimeImmutable $at): string
    {
        throw new \LogicException('add_report_screenshot makes no draft.');
    }

    public function createFromDraftRound(string $roundId, UserId $owner, \DateTimeImmutable $at): string
    {
        throw new \LogicException('add_report_screenshot makes no draft.');
    }

    public function forOwner(UserId $owner, ?ReportDraftCursor $after = null): ReportDraftsPage
    {
        throw new \LogicException('add_report_screenshot lists no drafts.');
    }

    public function update(string $draftId, UserId $owner, int $expectedVersion, ReportDraftContent $content, \DateTimeImmutable $at): void
    {
        throw new \LogicException('add_report_screenshot replaces no content.');
    }

    public function delete(string $draftId, UserId $owner): void
    {
        throw new \LogicException('add_report_screenshot deletes no draft.');
    }

    public function send(string $draftId, UserId $owner, int $expectedVersion, ?string $assigneeId, \DateTimeImmutable $at): string
    {
        throw new \LogicException('add_report_screenshot sends no draft.');
    }
}
