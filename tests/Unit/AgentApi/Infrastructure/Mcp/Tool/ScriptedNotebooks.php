<?php

declare(strict_types=1);

namespace App\Tests\Unit\AgentApi\Infrastructure\Mcp\Tool;

use App\Review\Api\NotebookCursor;
use App\Review\Api\NotebookRefused;
use App\Review\Api\Notebooks;
use App\Review\Api\NotebooksPage;
use App\Review\Api\NotebookView;
use App\Review\Api\ScreenshotWithPins;

/**
 * Notebooks as save_scenarios uses them: get() finds only the one notebook it holds, by its id exactly, as the real one
 * finds only a lowercase uuid of the person's; replaceScenarios() records what it was given, or throws $refusal and
 * records nothing, as the real one saves nothing when it refuses.
 */
final class ScriptedNotebooks implements Notebooks
{
    /** @var list<array{string, mixed, \DateTimeImmutable}> notebook id, scenarios, time */
    public array $replaced = [];

    public function __construct(private readonly ?NotebookView $notebook, private readonly ?NotebookRefused $refusal = null)
    {
    }

    public function get(string $notebookId): ?NotebookView
    {
        return $this->notebook?->id === $notebookId ? $this->notebook : null;
    }

    public function replaceScenarios(string $notebookId, mixed $decodedScenarios, \DateTimeImmutable $at): void
    {
        if (null !== $this->refusal) {
            throw $this->refusal;
        }
        $this->replaced[] = [$notebookId, $decodedScenarios, $at];
    }

    public function create(string $title, \DateTimeImmutable $at): string
    {
        throw new \LogicException('save_scenarios makes no notebook.');
    }

    public function addScreenshot(string $notebookId, ScreenshotWithPins $screenshot, \DateTimeImmutable $at): void
    {
        throw new \LogicException('save_scenarios adds no picture.');
    }

    public function rename(string $notebookId, string $title, \DateTimeImmutable $at): void
    {
        throw new \LogicException('save_scenarios renames nothing.');
    }

    public function delete(string $notebookId): void
    {
        throw new \LogicException('save_scenarios deletes nothing.');
    }

    public function forOwner(?NotebookCursor $after = null): NotebooksPage
    {
        throw new \LogicException('save_scenarios lists nothing.');
    }
}
