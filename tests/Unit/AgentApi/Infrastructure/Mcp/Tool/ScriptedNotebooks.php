<?php

declare(strict_types=1);

namespace App\Tests\Unit\AgentApi\Infrastructure\Mcp\Tool;

use App\Review\Api\NotebookCursor;
use App\Review\Api\NotebookRefused;
use App\Review\Api\Notebooks;
use App\Review\Api\NotebooksPage;
use App\Review\Api\NotebookView;
use App\Review\Api\ScreenshotWithPins;
use App\Review\Domain\Notebook\NotebookRefused as NotebookRule;
use App\Review\Domain\Scenario\ScenarioRefused;
use App\Review\Domain\Scenario\ScenarioRules;

/**
 * Notebooks as save_scenarios and add_notebook_screenshot use them: get() finds only the one notebook it holds, by its
 * id exactly, as the real one finds only a lowercase uuid of the person's. Each write refuses as the real one does, in
 * its order: an id get() does not find as not found, then $refusal when one is given, then the first rule broken (for a
 * picture: a full notebook before the same picture twice); it
 * records what it was given only when it refuses nothing, as the real one saves nothing when it refuses.
 * addScreenshot() reads the pictures again as the real one does under the row's lock: $mediaIdsUnderLock, the pictures
 * another request may have saved since get(), or the ones get() shows when null.
 */
final class ScriptedNotebooks implements Notebooks
{
    /** @var list<array{string, mixed, \DateTimeImmutable}> notebook id, scenarios, time */
    public array $replaced = [];

    /** @var list<array{string, ScreenshotWithPins, \DateTimeImmutable}> notebook id, picture, time */
    public array $added = [];

    /** @var list<string> */
    private array $mediaIdsUnderLock;

    /**
     * @param list<string>|null $mediaIdsUnderLock in lowercase, as the real one keeps them
     */
    public function __construct(private readonly ?NotebookView $notebook, private readonly ?NotebookRefused $refusal = null, ?array $mediaIdsUnderLock = null)
    {
        $this->mediaIdsUnderLock = $mediaIdsUnderLock ?? array_map(static fn (ScreenshotWithPins $screenshot): string => $screenshot->mediaId, $notebook->screenshots ?? []);
    }

    public function get(string $notebookId): ?NotebookView
    {
        return $this->notebook?->id === $notebookId ? $this->notebook : null;
    }

    public function replaceScenarios(string $notebookId, mixed $decodedScenarios, \DateTimeImmutable $at): void
    {
        $this->refuseUnlessWritable($notebookId);
        try {
            ScenarioRules::parse($decodedScenarios);
        } catch (ScenarioRefused $refused) {
            throw NotebookRefused::because($refused->reason, $refused);
        }
        $this->replaced[] = [$notebookId, $decodedScenarios, $at];
    }

    public function addScreenshot(string $notebookId, ScreenshotWithPins $screenshot, \DateTimeImmutable $at): int
    {
        $this->refuseUnlessWritable($notebookId);
        $mediaId = strtolower($screenshot->mediaId);
        if (\count($this->mediaIdsUnderLock) >= self::MAX_SCREENSHOTS) {
            throw NotebookRefused::because(NotebookRule::TOO_MANY_SCREENSHOTS);
        }
        if (\in_array($mediaId, $this->mediaIdsUnderLock, true)) {
            throw NotebookRefused::because(NotebookRule::SAME_PICTURE_TWICE);
        }
        $this->mediaIdsUnderLock[] = $mediaId;
        $this->added[] = [$notebookId, $screenshot, $at];

        return \count($this->mediaIdsUnderLock);
    }

    public function create(string $title, \DateTimeImmutable $at): string
    {
        throw new \LogicException('No tool that uses this double makes a notebook.');
    }

    public function rename(string $notebookId, string $title, \DateTimeImmutable $at): void
    {
        throw new \LogicException('No tool that uses this double renames a notebook.');
    }

    public function delete(string $notebookId): void
    {
        throw new \LogicException('No tool that uses this double deletes a notebook.');
    }

    public function forOwner(?NotebookCursor $after = null): NotebooksPage
    {
        throw new \LogicException('No tool that uses this double lists notebooks.');
    }

    /**
     * @throws NotebookRefused not found for an id get() does not find, else $refusal when one is given
     */
    private function refuseUnlessWritable(string $notebookId): void
    {
        if (null === $this->get($notebookId)) {
            throw NotebookRefused::notFound();
        }
        if (null !== $this->refusal) {
            throw $this->refusal;
        }
    }
}
