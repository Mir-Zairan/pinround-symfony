<?php

declare(strict_types=1);

namespace App\Tests\Unit\AgentApi\Infrastructure\Mcp\Tool;

use App\Review\Api\AddedNotebookScreenshot;
use App\Review\Api\NotebookCursor;
use App\Review\Api\NotebookRefused;
use App\Review\Api\Notebooks;
use App\Review\Api\NotebooksPage;
use App\Review\Api\NotebookView;
use App\Review\Api\PlacedPin;
use App\Review\Api\ScreenshotWithPins;
use App\Review\Domain\Notebook\NotebookPin;
use App\Review\Domain\Notebook\NotebookRefused as NotebookRule;
use App\Review\Domain\Notebook\NotebookScreenshot;
use App\Review\Domain\Scenario\ScenarioRefused;
use App\Review\Domain\Scenario\ScenarioRules;

/**
 * Notebooks as save_scenarios and add_notebook_screenshot use them: get() finds only the one notebook it holds, by its
 * id exactly, as the real one finds only a lowercase uuid of the person's. Each write refuses as UseCaseNotebooks does,
 * in its order: an id get() does not find as not found, then the first rule the given scenarios or picture breaks, by
 * the domain's own rules; $refusal, when one is given, only where the real one would save, as its picture and
 * NOTEBOOK_CHANGED refusals come there. It records what it was given only when it refuses nothing.
 * addScreenshot() reads the pictures again as the real one does before it saves over the version it read:
 * $mediaIdsAtAdd, the pictures another request may have saved since get(), or the ones get() shows when null. A
 * picture among them is there already, and the call changes nothing and saves nothing, even on a full notebook; else a
 * full notebook is refused.
 */
final class ScriptedNotebooks implements Notebooks
{
    /** @var list<array{string, mixed, \DateTimeImmutable}> notebook id, scenarios, time */
    public array $replaced = [];

    /** @var list<array{string, ScreenshotWithPins, \DateTimeImmutable}> notebook id, picture, time */
    public array $added = [];

    /** @var list<string> */
    private array $mediaIdsAtAdd;

    /**
     * @param list<string>|null $mediaIdsAtAdd in lowercase, as the real one keeps them
     */
    public function __construct(private readonly ?NotebookView $notebook, private readonly ?NotebookRefused $refusal = null, ?array $mediaIdsAtAdd = null)
    {
        $this->mediaIdsAtAdd = $mediaIdsAtAdd ?? array_map(static fn (ScreenshotWithPins $screenshot): string => $screenshot->mediaId, $notebook->screenshots ?? []);
    }

    public function get(string $notebookId): ?NotebookView
    {
        return $this->notebook?->id === $notebookId ? $this->notebook : null;
    }

    public function replaceScenarios(string $notebookId, mixed $decodedScenarios, \DateTimeImmutable $at): void
    {
        $this->refuseUnlessFound($notebookId);
        try {
            ScenarioRules::parse($decodedScenarios);
        } catch (ScenarioRefused $refused) {
            throw NotebookRefused::because($refused->reason, $refused);
        }
        $this->refuseWhenScripted();
        $this->replaced[] = [$notebookId, $decodedScenarios, $at];
    }

    public function addScreenshot(string $notebookId, ScreenshotWithPins $screenshot, \DateTimeImmutable $at): AddedNotebookScreenshot
    {
        $this->refuseUnlessFound($notebookId);
        $mediaId = self::pictureObeyingTheRules($screenshot)->mediaId();
        if (\in_array($mediaId, $this->mediaIdsAtAdd, true)) {
            return new AddedNotebookScreenshot(true, \count($this->mediaIdsAtAdd));
        }
        if (\count($this->mediaIdsAtAdd) >= self::MAX_SCREENSHOTS) {
            throw NotebookRefused::because(NotebookRule::TOO_MANY_SCREENSHOTS);
        }
        $this->refuseWhenScripted();
        $this->mediaIdsAtAdd[] = $mediaId;
        $this->added[] = [$notebookId, $screenshot, $at];

        return new AddedNotebookScreenshot(false, \count($this->mediaIdsAtAdd));
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
     * @throws NotebookRefused not found for an id get() does not find
     */
    private function refuseUnlessFound(string $notebookId): void
    {
        if (null === $this->get($notebookId)) {
            throw NotebookRefused::notFound();
        }
    }

    /**
     * @throws NotebookRefused $refusal when one is given
     */
    private function refuseWhenScripted(): void
    {
        if (null !== $this->refusal) {
            throw $this->refusal;
        }
    }

    /**
     * @throws NotebookRefused with the text of the first picture or pin rule $screenshot breaks
     */
    private static function pictureObeyingTheRules(ScreenshotWithPins $screenshot): NotebookScreenshot
    {
        try {
            return NotebookScreenshot::of(
                $screenshot->mediaId,
                $screenshot->title,
                $screenshot->summary,
                array_map(static fn (PlacedPin $pin): NotebookPin => NotebookPin::placed($pin->xPercent, $pin->yPercent, $pin->note), $screenshot->pins),
            );
        } catch (NotebookRule $broken) {
            throw NotebookRefused::because($broken->getMessage(), $broken);
        }
    }
}
