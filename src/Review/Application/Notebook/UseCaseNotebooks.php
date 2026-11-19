<?php

declare(strict_types=1);

namespace App\Review\Application\Notebook;

use App\Identity\Api\CurrentUser;
use App\Review\Api\NotebookCursor;
use App\Review\Api\NotebookPermission;
use App\Review\Api\NotebookRefused;
use App\Review\Api\Notebooks;
use App\Review\Api\NotebooksPage;
use App\Review\Api\NotebookSummary;
use App\Review\Api\NotebookView;
use App\Review\Api\PlacedPin;
use App\Review\Api\ScreenshotWithPins;
use App\Review\Application\PicturesToKeep;
use App\Review\Domain\Notebook\Notebook;
use App\Review\Domain\Notebook\NotebookCursor as RowsCursor;
use App\Review\Domain\Notebook\NotebookId;
use App\Review\Domain\Notebook\NotebookPin;
use App\Review\Domain\Notebook\NotebookRefused as NotebookRuleBroken;
use App\Review\Domain\Notebook\NotebookRows;
use App\Review\Domain\Notebook\NotebookScreenshot;
use App\Review\Domain\Notebook\NotebookSummary as RowsSummary;
use App\Review\Domain\Scenario\ScenarioRefused;
use App\Review\Domain\Scenario\ScenarioRules;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\UserId;

/**
 * Notebooks on NotebookRows (ADR-0046). The owner is always the request's logged-in person, so every read names them
 * and another person's notebook is never read at all; NotebookAccess then asks NotebookVoter on what was read.
 * Holds no state (ADR-0008).
 */
final readonly class UseCaseNotebooks implements Notebooks
{
    public function __construct(
        private NotebookRows $rows,
        private NotebookAccess $access,
        private CurrentUser $currentUser,
        private PicturesToKeep $picturesToKeep,
        private TenantContext $tenantContext,
    ) {
    }

    public function create(string $title, \DateTimeImmutable $at): string
    {
        $owner = $this->loggedInPerson();
        $notebook = self::obeyingTheRules(fn (): Notebook => Notebook::start($this->rows->nextId(), $this->tenantContext->require(), $owner, $title, $at));
        if (!$this->access->isGranted(NotebookPermission::EDIT, self::viewOf($notebook))) {
            throw NotebookRefused::because(NotebookRefused::NOT_YOURS_TO_MAKE);
        }
        $this->rows->insert($notebook);

        return $notebook->id()->toString();
    }

    public function addScreenshot(string $notebookId, ScreenshotWithPins $screenshot, \DateTimeImmutable $at): void
    {
        $notebook = $this->editableNotebook($notebookId);
        $changed = self::obeyingTheRules(static fn (): Notebook => $notebook->withScreenshotAdded(NotebookScreenshot::of(
            $screenshot->mediaId,
            $screenshot->title,
            $screenshot->summary,
            array_map(static fn (PlacedPin $pin): NotebookPin => NotebookPin::placed($pin->xPercent, $pin->yPercent, $pin->note), $screenshot->pins),
        ), $at));
        $this->refuseUnlessOwnUsablePicture($notebook, $screenshot->mediaId);
        $this->save($changed);
    }

    public function rename(string $notebookId, string $title, \DateTimeImmutable $at): void
    {
        $notebook = $this->editableNotebook($notebookId);
        $this->save(self::obeyingTheRules(static fn (): Notebook => $notebook->renamed($title, $at)));
    }

    public function delete(string $notebookId): void
    {
        $notebook = $this->editableNotebook($notebookId);
        if (!$this->rows->delete($notebook->id(), $notebook->ownerId(), $notebook->version())) {
            throw NotebookRefused::because(NotebookRefused::NOTEBOOK_CHANGED);
        }
    }

    public function forOwner(?NotebookCursor $after = null): NotebooksPage
    {
        $page = $this->rows->forOwner(
            $this->loggedInPerson(),
            null === $after ? null : new RowsCursor($after->lastUsedAt, new NotebookId($after->notebookId)),
            self::NOTEBOOKS_PER_PAGE,
        );
        $next = $page->nextCursor;

        return new NotebooksPage(
            array_map(static fn (RowsSummary $summary): NotebookSummary => new NotebookSummary($summary->id->toString(), $summary->title, $summary->screenshotCount, $summary->scenarioCount, $summary->lastUsedAt), $page->notebooks),
            null === $next ? null : new NotebookCursor($next->lastUsedAt, $next->notebookId->toString()),
        );
    }

    public function get(string $notebookId): ?NotebookView
    {
        $notebook = $this->ownNotebook($notebookId);
        if (null === $notebook) {
            return null;
        }
        $view = self::viewOf($notebook);

        return $this->access->isGranted(NotebookPermission::VIEW, $view) ? $view : null;
    }

    public function replaceScenarios(string $notebookId, mixed $decodedScenarios, \DateTimeImmutable $at): void
    {
        $notebook = $this->editableNotebook($notebookId);
        try {
            $scenarios = ScenarioRules::parse($decodedScenarios);
        } catch (ScenarioRefused $refused) {
            throw NotebookRefused::because($refused->reason, $refused);
        }
        $this->save($notebook->withScenarios($scenarios, $at));
    }

    /**
     * @throws NotebookRefused NOT_FOUND unless the person's notebook exists and NotebookVoter grants EDIT on it
     */
    private function editableNotebook(string $notebookId): Notebook
    {
        $notebook = $this->ownNotebook($notebookId);
        if (null === $notebook || !$this->access->isGranted(NotebookPermission::EDIT, self::viewOf($notebook))) {
            throw NotebookRefused::notFound();
        }

        return $notebook;
    }

    /**
     * @return Notebook|null null with nobody logged in, for a malformed id, and when the tenant has no such notebook of
     *                       the person
     */
    private function ownNotebook(string $notebookId): ?Notebook
    {
        $owner = $this->currentUser->id();
        if (null === $owner) {
            return null;
        }
        try {
            $id = new NotebookId($notebookId);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $this->rows->ofOwner($id, $owner);
    }

    /**
     * @throws NotebookRefused NOTEBOOK_CHANGED when the stored version is no longer the one read
     */
    private function save(Notebook $notebook): void
    {
        if (!$this->rows->save($notebook)) {
            throw NotebookRefused::because(NotebookRefused::NOTEBOOK_CHANGED);
        }
    }

    /**
     * @throws NotebookRefused with RoundRefused's MEDIA_NOT_UPLOADED_BY_YOU or MEDIA_REJECTED
     */
    private function refuseUnlessOwnUsablePicture(Notebook $notebook, string $mediaId): void
    {
        $refusal = $this->picturesToKeep->refusalOf($notebook->organizationId(), $notebook->ownerId(), [$mediaId]);
        if (null !== $refusal) {
            throw NotebookRefused::because($refusal);
        }
    }

    /**
     * @throws \LogicException when nobody is logged in, which no page or token path lets through
     */
    private function loggedInPerson(): UserId
    {
        return $this->currentUser->id() ?? throw new \LogicException('Notebooks are a logged-in person\'s own; nobody is logged in.');
    }

    /**
     * @template T
     *
     * @param \Closure(): T $change
     *
     * @return T
     *
     * @throws NotebookRefused with the text of the notebook rule the change broke
     */
    private static function obeyingTheRules(\Closure $change): mixed
    {
        try {
            return $change();
        } catch (NotebookRuleBroken $broken) {
            throw NotebookRefused::because($broken->getMessage(), $broken);
        }
    }

    private static function viewOf(Notebook $notebook): NotebookView
    {
        return new NotebookView(
            $notebook->id()->toString(),
            $notebook->organizationId(),
            $notebook->ownerId(),
            $notebook->title(),
            array_map(static fn (NotebookScreenshot $screenshot): ScreenshotWithPins => new ScreenshotWithPins(
                $screenshot->mediaId(),
                $screenshot->title(),
                $screenshot->summary(),
                array_map(static fn (NotebookPin $pin): PlacedPin => new PlacedPin($pin->xPercent(), $pin->yPercent(), $pin->note()), $screenshot->pins()),
            ), $notebook->screenshots()),
            $notebook->scenarios()->toArray(),
            $notebook->version(),
            $notebook->createdAt(),
            $notebook->updatedAt(),
            $notebook->lastUsedAt(),
        );
    }
}
