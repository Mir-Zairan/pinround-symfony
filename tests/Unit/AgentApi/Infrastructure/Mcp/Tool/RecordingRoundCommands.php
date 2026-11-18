<?php

declare(strict_types=1);

namespace App\Tests\Unit\AgentApi\Infrastructure\Mcp\Tool;

use App\Review\Api\RoundCommands;
use App\Review\Api\RoundRefused;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * Records the draft verdict and text changes set_verdict makes. Like UseCaseRoundCommands it refuses a verdict it does
 * not know with UNKNOWN_VERDICT, and a summary over 600 characters after trimming as the domain's
 * InvalidArgumentException wrapped in RoundRefused; $refusal, when set, is thrown by the next setDraftVerdict, as the
 * real command does for a draft that is gone, shared or changed. The commands set_verdict never uses throw.
 */
final class RecordingRoundCommands implements RoundCommands
{
    /** @var list<array{string, string, string, string|null}> round, owner, screenshot and verdict of each call */
    public array $verdictsSet = [];

    /** @var list<array{string, string, string, string|null, string|null}> round, owner, screenshot, title, summary */
    public array $textsEdited = [];

    public ?RoundRefused $refusal = null;

    public function setDraftVerdict(string $roundId, UserId $ownerId, string $screenshotId, ?string $verdict, \DateTimeImmutable $at): void
    {
        if (null !== $this->refusal) {
            throw $this->refusal;
        }
        if (!\in_array($verdict, [null, self::VERDICT_LOOKS_RIGHT, self::VERDICT_NOT_RIGHT_YET], true)) {
            throw new RoundRefused(RoundRefused::UNKNOWN_VERDICT);
        }
        $this->verdictsSet[] = [$roundId, $ownerId->toString(), $screenshotId, $verdict];
    }

    public function editDraftScreenshot(string $roundId, UserId $ownerId, string $screenshotId, ?string $title, ?string $summary, \DateTimeImmutable $at): void
    {
        if (null !== $summary && mb_strlen(trim($summary)) > 600) {
            $tooLong = new \InvalidArgumentException('A picture summary holds at most 600 characters.');

            throw new RoundRefused($tooLong->getMessage(), 0, $tooLong);
        }
        $this->textsEdited[] = [$roundId, $ownerId->toString(), $screenshotId, $title, $summary];
    }

    public function createReport(TicketId $ticketId, array $screenshots, UserId $reporterId, \DateTimeImmutable $reportedAt): string
    {
        throw new \LogicException('set_verdict never creates a report.');
    }

    public function startDraft(TicketId $ticketId, UserId $ownerId, \DateTimeImmutable $startedAt): string
    {
        throw new \LogicException('set_verdict never starts a draft.');
    }

    public function addDraftScreenshot(string $roundId, UserId $ownerId, string $mediaId, \DateTimeImmutable $addedAt): string
    {
        throw new \LogicException('set_verdict never adds a screenshot.');
    }

    public function removeDraftScreenshot(string $roundId, UserId $ownerId, string $screenshotId, \DateTimeImmutable $at): void
    {
        throw new \LogicException('set_verdict never removes a screenshot.');
    }

    public function setDraftPins(string $roundId, UserId $ownerId, string $screenshotId, array $pins, \DateTimeImmutable $at): void
    {
        throw new \LogicException('set_verdict never sets pins.');
    }

    public function shareDraft(string $roundId, UserId $ownerId, \DateTimeImmutable $sharedAt): int
    {
        throw new \LogicException('set_verdict never shares a draft.');
    }

    public function shareDraftAtHandOff(string $roundId, UserId $ownerId, \DateTimeImmutable $sharedAt): int
    {
        throw new \LogicException('set_verdict never shares a draft.');
    }

    public function deleteDraft(string $roundId, UserId $ownerId, \DateTimeImmutable $deletedAt): void
    {
        throw new \LogicException('set_verdict never deletes a draft.');
    }
}
