<?php

declare(strict_types=1);

namespace App\Tests\Unit\AgentApi\Infrastructure\Mcp\Tool;

use App\Review\Api\AddedDraftScreenshot;
use App\Review\Api\DraftScreenshotCommands;
use App\Review\Api\PlacedPin;
use App\Review\Api\RoundRefused;
use App\Review\Api\ScreenshotWithPins;
use App\Review\Domain\Screenshot;
use App\Review\Domain\ScreenshotTriesFull;
use App\SharedKernel\Domain\UserId;

/**
 * Records the screenshots add_screenshot asks Review to add, with their title, summary and pins, and answers a media id
 * set in $heldBefore as the real command does a picture the draft holds already, in any case, without recording it. A
 * refusal set in $refusal is thrown instead and nothing is recorded, as the real command saves nothing when it
 * refuses; the content rules are Review's, and its own tests prove them.
 *
 * A retake is refused as UseCaseRoundCommands refuses it: SCREENSHOT_NOT_FOUND for a retake_of that is malformed or no
 * screenshot of that round (held()), in any case; the cap's reason once its chain holds Screenshot::MAX_TRIES tries.
 * Each added screenshot gets the next id from NEW_SCREENSHOT up and joins its round, so it can be retaken in turn.
 */
final class RecordingDraftScreenshots implements DraftScreenshotCommands
{
    public const string NEW_SCREENSHOT = '019a5f00-0000-7000-8000-00000000e7a1';

    /** Version nibble 7 and variant bits 10xx, as ScreenshotId accepts them; \z, as $ also takes a trailing newline. */
    private const string CANONICAL_UUID_V7 = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

    /** @var list<array{string, string, string, string|null, string|null, list<array{float, float, string}>, string}> round, owner, media, title, summary, pins and time */
    public array $added = [];

    /** @var list<array{string, string}> the id of each added retake and the first try of its chain */
    public array $addedRetakes = [];

    /** @var array<string, AddedDraftScreenshot> the answer for a media id in lowercase */
    public array $heldBefore = [];

    public ?RoundRefused $refusal = null;

    /** @var array<string, array<string, string>> by round id, the first try of each screenshot's chain by its id */
    private array $chainsByRound = [];

    /**
     * The round holds the screenshot already, as a first try or as a try of $firstTryId's chain.
     */
    public function held(string $roundId, string $screenshotId, ?string $firstTryId = null): void
    {
        $this->chainsByRound[$roundId][$screenshotId] = $firstTryId ?? $screenshotId;
    }

    public function addDraftScreenshotOnce(string $roundId, UserId $ownerId, ScreenshotWithPins $screenshot, \DateTimeImmutable $addedAt, ?string $retakeOf = null): AddedDraftScreenshot
    {
        if (null !== $this->refusal) {
            throw $this->refusal;
        }
        $heldBefore = $this->heldBefore[strtolower($screenshot->mediaId)] ?? null;
        if (null !== $heldBefore) {
            return $heldBefore;
        }
        $firstTryId = null === $retakeOf ? null : $this->firstTryOfChainWith($roundId, $retakeOf);
        $screenshotId = \sprintf('019a5f00-0000-7000-8000-%012x', 0xE7A1 + \count($this->added));
        $this->added[] = [
            $roundId,
            $ownerId->toString(),
            $screenshot->mediaId,
            $screenshot->title,
            $screenshot->summary,
            array_map(static fn (PlacedPin $pin): array => [$pin->xPercent, $pin->yPercent, $pin->note], $screenshot->pins),
            $addedAt->format('Y-m-d H:i:s'),
        ];
        $this->held($roundId, $screenshotId, $firstTryId);
        if (null !== $firstTryId) {
            $this->addedRetakes[] = [$screenshotId, $firstTryId];
        }

        return new AddedDraftScreenshot($screenshotId, false, \count($screenshot->pins));
    }

    /**
     * @throws RoundRefused SCREENSHOT_NOT_FOUND or the cap's reason, as the real command refuses
     */
    private function firstTryOfChainWith(string $roundId, string $retakeOf): string
    {
        $wantedId = strtolower($retakeOf);
        $chains = $this->chainsByRound[$roundId] ?? [];
        if (1 !== preg_match(self::CANONICAL_UUID_V7, $wantedId) || !isset($chains[$wantedId])) {
            throw new RoundRefused(RoundRefused::SCREENSHOT_NOT_FOUND);
        }
        $firstTryId = $chains[$wantedId];
        if (\count(array_keys($chains, $firstTryId, true)) >= Screenshot::MAX_TRIES) {
            throw new RoundRefused(ScreenshotTriesFull::REASON);
        }

        return $firstTryId;
    }
}
