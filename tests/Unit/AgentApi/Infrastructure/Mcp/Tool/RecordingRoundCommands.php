<?php

declare(strict_types=1);

namespace App\Tests\Unit\AgentApi\Infrastructure\Mcp\Tool;

use App\Review\Api\RoundCommands;
use App\Review\Api\RoundRefused;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * Records the verdicts and summaries set_verdict saves. Like UseCaseRoundCommands it refuses a verdict it does not
 * know with UNKNOWN_VERDICT as invalid content; $refusal, when set, is thrown by the next call instead and nothing is
 * recorded, as the real command saves nothing when it refuses. The content rules, such as the summary's cap, are
 * Review's: the functional test proves them through the real command. The commands set_verdict never uses throw.
 * Like the real command it answers with the latest try of the named screenshot's chain, in lowercase: the one
 * $latestTryIds names, else the screenshot itself.
 */
final class RecordingRoundCommands implements RoundCommands
{
    /** @var list<array{string, string, string, string, string|null}> round, owner, screenshot, verdict and summary of each call */
    public array $verdictsSet = [];

    /** @var array<string, string> latest try id by the lowercase id of an earlier try of its chain */
    public array $latestTryIds = [];

    public ?RoundRefused $refusal = null;

    public function setDraftVerdictWithSummary(string $roundId, UserId $ownerId, string $screenshotId, string $verdict, ?string $summary, \DateTimeImmutable $at): string
    {
        if (null !== $this->refusal) {
            throw $this->refusal;
        }
        if (!\in_array($verdict, [self::VERDICT_LOOKS_RIGHT, self::VERDICT_NOT_RIGHT_YET], true)) {
            throw RoundRefused::invalidContent(RoundRefused::UNKNOWN_VERDICT);
        }
        $this->verdictsSet[] = [$roundId, $ownerId->toString(), $screenshotId, $verdict, $summary];

        return strtolower($this->latestTryIds[strtolower($screenshotId)] ?? $screenshotId);
    }

    public function setDraftVerdict(string $roundId, UserId $ownerId, string $screenshotId, ?string $verdict, \DateTimeImmutable $at): void
    {
        throw new \LogicException('set_verdict saves a verdict with its summary, in one command.');
    }

    public function setDraftPinVerdict(string $roundId, UserId $ownerId, string $pinId, ?string $verdict, \DateTimeImmutable $at): void
    {
        throw new \LogicException('No tool marks a pin of an earlier try.');
    }

    public function editDraftScreenshot(string $roundId, UserId $ownerId, string $screenshotId, ?string $title, ?string $summary, \DateTimeImmutable $at): void
    {
        throw new \LogicException('set_verdict saves a summary with its verdict, in one command.');
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
