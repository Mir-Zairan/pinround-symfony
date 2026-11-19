<?php

declare(strict_types=1);

namespace App\Tests\Unit\AgentApi\Infrastructure\Mcp\Tool;

use App\Review\Api\AddedDraftScreenshot;
use App\Review\Api\DraftScreenshotCommands;
use App\Review\Api\PlacedPin;
use App\Review\Api\RoundRefused;
use App\Review\Api\ScreenshotWithPins;
use App\SharedKernel\Domain\UserId;

/**
 * Records the screenshots add_screenshot asks Review to add, with their title, summary and pins, and answers a media id
 * set in $heldBefore as the real command does a picture the draft holds already, in any case, without recording it. A
 * refusal set in $refusal is thrown instead and nothing is recorded, as the real command saves nothing when it
 * refuses; the content rules are Review's, and its own tests prove them.
 */
final class RecordingDraftScreenshots implements DraftScreenshotCommands
{
    public const string NEW_SCREENSHOT = '019a5f00-0000-7000-8000-00000000e7a1';

    /** @var list<array{string, string, string, string|null, string|null, list<array{float, float, string}>, string}> round, owner, media, title, summary, pins and time */
    public array $added = [];

    /** @var array<string, AddedDraftScreenshot> the answer for a media id in lowercase */
    public array $heldBefore = [];

    public ?RoundRefused $refusal = null;

    public function addDraftScreenshotOnce(string $roundId, UserId $ownerId, ScreenshotWithPins $screenshot, \DateTimeImmutable $addedAt): AddedDraftScreenshot
    {
        if (null !== $this->refusal) {
            throw $this->refusal;
        }
        $heldBefore = $this->heldBefore[strtolower($screenshot->mediaId)] ?? null;
        if (null !== $heldBefore) {
            return $heldBefore;
        }
        $this->added[] = [
            $roundId,
            $ownerId->toString(),
            $screenshot->mediaId,
            $screenshot->title,
            $screenshot->summary,
            array_map(static fn (PlacedPin $pin): array => [$pin->xPercent, $pin->yPercent, $pin->note], $screenshot->pins),
            $addedAt->format('Y-m-d H:i:s'),
        ];

        return new AddedDraftScreenshot(self::NEW_SCREENSHOT, false, \count($screenshot->pins));
    }
}
