<?php

declare(strict_types=1);

namespace App\Tests\Unit\AgentApi\Infrastructure\Mcp\Tool;

use App\Review\Api\PlacedPin;
use App\Review\Api\RoundCommands;
use App\Review\Api\RoundRefused;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * Records the screenshots add_screenshot adds and the title, summary and pins it gives them. A refusal set in
 * $refusals under a command's name is thrown by that command instead, which then records nothing, as the real command
 * saves nothing when it refuses; the content rules are Review's, and the functional test proves them through the real
 * commands. The commands add_screenshot never uses throw.
 */
final class RecordingDraftScreenshots implements RoundCommands
{
    public const string NEW_SCREENSHOT = '019a5f00-0000-7000-8000-00000000e7a1';

    /** @var list<array{string, string, string, string}> round, owner, media and time of each added screenshot */
    public array $added = [];

    /** @var list<array{string, string, string, string|null, string|null}> round, owner, screenshot, title and summary */
    public array $edited = [];

    /** @var list<array{string, string, string, list<array{float, float, string}>}> round, owner, screenshot and pins */
    public array $pinsSet = [];

    /** @var array<string, RoundRefused> by the name of the command that throws it */
    public array $refusals = [];

    public function addDraftScreenshot(string $roundId, UserId $ownerId, string $mediaId, \DateTimeImmutable $addedAt): string
    {
        $this->refuseIfScripted(__FUNCTION__);
        $this->added[] = [$roundId, $ownerId->toString(), $mediaId, $addedAt->format('Y-m-d H:i:s')];

        return self::NEW_SCREENSHOT;
    }

    public function editDraftScreenshot(string $roundId, UserId $ownerId, string $screenshotId, ?string $title, ?string $summary, \DateTimeImmutable $at): void
    {
        $this->refuseIfScripted(__FUNCTION__);
        $this->edited[] = [$roundId, $ownerId->toString(), $screenshotId, $title, $summary];
    }

    public function setDraftPins(string $roundId, UserId $ownerId, string $screenshotId, array $pins, \DateTimeImmutable $at): void
    {
        $this->refuseIfScripted(__FUNCTION__);
        $this->pinsSet[] = [$roundId, $ownerId->toString(), $screenshotId, array_map(static fn (PlacedPin $pin): array => [$pin->xPercent, $pin->yPercent, $pin->note], $pins)];
    }

    public function createReport(TicketId $ticketId, array $screenshots, UserId $reporterId, \DateTimeImmutable $reportedAt): string
    {
        throw new \LogicException('add_screenshot never creates a report.');
    }

    public function startDraft(TicketId $ticketId, UserId $ownerId, \DateTimeImmutable $startedAt): string
    {
        throw new \LogicException('add_screenshot never starts a draft; create_upload does.');
    }

    public function removeDraftScreenshot(string $roundId, UserId $ownerId, string $screenshotId, \DateTimeImmutable $at): void
    {
        throw new \LogicException('add_screenshot never removes a screenshot.');
    }

    public function setDraftVerdict(string $roundId, UserId $ownerId, string $screenshotId, ?string $verdict, \DateTimeImmutable $at): void
    {
        throw new \LogicException('add_screenshot never sets a verdict.');
    }

    public function setDraftVerdictWithSummary(string $roundId, UserId $ownerId, string $screenshotId, string $verdict, ?string $summary, \DateTimeImmutable $at): void
    {
        throw new \LogicException('add_screenshot never sets a verdict.');
    }

    public function shareDraft(string $roundId, UserId $ownerId, \DateTimeImmutable $sharedAt): int
    {
        throw new \LogicException('add_screenshot never shares a draft.');
    }

    public function shareDraftAtHandOff(string $roundId, UserId $ownerId, \DateTimeImmutable $sharedAt): int
    {
        throw new \LogicException('add_screenshot never shares a draft.');
    }

    public function deleteDraft(string $roundId, UserId $ownerId, \DateTimeImmutable $deletedAt): void
    {
        throw new \LogicException('add_screenshot never deletes a draft.');
    }

    private function refuseIfScripted(string $command): void
    {
        if (isset($this->refusals[$command])) {
            throw $this->refusals[$command];
        }
    }
}
