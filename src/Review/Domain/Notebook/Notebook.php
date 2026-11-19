<?php

declare(strict_types=1);

namespace App\Review\Domain\Notebook;

use App\Review\Domain\Scenario\Scenarios;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * A person's private notebook (ADR-0046): pinned screenshots and the scenarios Claude Code wrote for them. Only its
 * owner may see it; it belongs to no ticket. Every change returns a new notebook at the same version, which
 * NotebookRows::save writes only over that version.
 */
final readonly class Notebook
{
    public const int TITLE_MAX_LENGTH = 120;
    public const int MAX_SCREENSHOTS = 10;

    /**
     * @param list<NotebookScreenshot> $screenshots
     */
    private function __construct(
        private NotebookId $id,
        private OrganizationId $organizationId,
        private UserId $ownerId,
        private string $title,
        private array $screenshots,
        private Scenarios $scenarios,
        private int $version,
        private \DateTimeImmutable $createdAt,
        private \DateTimeImmutable $updatedAt,
        private \DateTimeImmutable $lastUsedAt,
    ) {
    }

    /**
     * A new, empty notebook at version 1. The title is trimmed; lengths count characters, as PostgreSQL's char_length
     * does.
     *
     * @throws NotebookRefused when the title is blank, longer than TITLE_MAX_LENGTH, not valid UTF-8 or holds a NUL
     *                         character
     */
    public static function start(NotebookId $id, OrganizationId $organizationId, UserId $ownerId, string $title, \DateTimeImmutable $at): self
    {
        return new self($id, $organizationId, $ownerId, self::checkedTitle($title), [], new Scenarios([]), 1, $at, $at, $at);
    }

    /**
     * A stored notebook as NotebookRows reads it back, under the rules of start() and of each change.
     *
     * @param list<NotebookScreenshot> $screenshots
     *
     * @throws NotebookRefused           when a rule of start() or of a change would refuse it
     * @throws \InvalidArgumentException when the version is below 1, or a time is before $createdAt
     */
    public static function restored(
        NotebookId $id,
        OrganizationId $organizationId,
        UserId $ownerId,
        string $title,
        array $screenshots,
        Scenarios $scenarios,
        int $version,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $updatedAt,
        \DateTimeImmutable $lastUsedAt,
    ): self {
        if ($version < 1) {
            throw new \InvalidArgumentException('A notebook version starts at 1.');
        }
        if ($updatedAt < $createdAt || $lastUsedAt < $createdAt) {
            throw new \InvalidArgumentException('A notebook is changed and used no earlier than it was created.');
        }

        return new self($id, $organizationId, $ownerId, self::checkedTitle($title), self::checkedScreenshots($screenshots), $scenarios, $version, $createdAt, $updatedAt, $lastUsedAt);
    }

    /**
     * @throws NotebookRefused when the title is blank, longer than TITLE_MAX_LENGTH, not valid UTF-8 or holds a NUL
     *                         character
     */
    public function renamed(string $title, \DateTimeImmutable $at): self
    {
        return $this->changed(self::checkedTitle($title), $this->screenshots, $this->scenarios, $at);
    }

    /**
     * This notebook with $screenshot after its others.
     *
     * @throws NotebookRefused when it holds MAX_SCREENSHOTS already, or holds the same picture
     */
    public function withScreenshotAdded(NotebookScreenshot $screenshot, \DateTimeImmutable $at): self
    {
        return $this->changed($this->title, self::checkedScreenshots([...$this->screenshots, $screenshot]), $this->scenarios, $at);
    }

    /**
     * This notebook with $scenarios in place of its scenarios; ScenarioRules checked them, their number too.
     */
    public function withScenarios(Scenarios $scenarios, \DateTimeImmutable $at): self
    {
        return $this->changed($this->title, $this->screenshots, $scenarios, $at);
    }

    /**
     * This notebook last used at $at, as when its owner picks it as a target; nothing else changes. NotebookRows::markUsed
     * stores the same without a save.
     */
    public function markedUsed(\DateTimeImmutable $at): self
    {
        return new self($this->id, $this->organizationId, $this->ownerId, $this->title, $this->screenshots, $this->scenarios, $this->version, $this->createdAt, $this->updatedAt, max($this->createdAt, $at));
    }

    public function id(): NotebookId
    {
        return $this->id;
    }

    public function organizationId(): OrganizationId
    {
        return $this->organizationId;
    }

    public function ownerId(): UserId
    {
        return $this->ownerId;
    }

    public function title(): string
    {
        return $this->title;
    }

    /**
     * @return list<NotebookScreenshot> in the order they were added
     */
    public function screenshots(): array
    {
        return $this->screenshots;
    }

    public function scenarios(): Scenarios
    {
        return $this->scenarios;
    }

    /**
     * The version this notebook was read or started at; a change keeps it.
     */
    public function version(): int
    {
        return $this->version;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function lastUsedAt(): \DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    /**
     * A change is a use too. A clock behind the creation time is held at it, as rvw_notebook's CHECKs require.
     *
     * @param list<NotebookScreenshot> $screenshots
     */
    private function changed(string $title, array $screenshots, Scenarios $scenarios, \DateTimeImmutable $at): self
    {
        $changedAt = max($this->createdAt, $at);

        return new self($this->id, $this->organizationId, $this->ownerId, $title, $screenshots, $scenarios, $this->version, $this->createdAt, $changedAt, $changedAt);
    }

    /**
     * @throws NotebookRefused
     */
    private static function checkedTitle(string $title): string
    {
        NotebookText::refuseUnlessSavable($title);
        $trimmed = trim($title);
        if ('' === $trimmed) {
            throw NotebookRefused::because(NotebookRefused::TITLE_BLANK);
        }
        if (mb_strlen($trimmed) > self::TITLE_MAX_LENGTH) {
            throw NotebookRefused::because(NotebookRefused::TITLE_TOO_LONG);
        }

        return $trimmed;
    }

    /**
     * @param list<NotebookScreenshot> $screenshots
     *
     * @return list<NotebookScreenshot>
     *
     * @throws NotebookRefused
     */
    private static function checkedScreenshots(array $screenshots): array
    {
        if (\count($screenshots) > self::MAX_SCREENSHOTS) {
            throw NotebookRefused::because(NotebookRefused::TOO_MANY_SCREENSHOTS);
        }
        $mediaIds = [];
        foreach ($screenshots as $screenshot) {
            if (isset($mediaIds[$screenshot->mediaId()])) {
                throw NotebookRefused::because(NotebookRefused::SAME_PICTURE_TWICE);
            }
            $mediaIds[$screenshot->mediaId()] = true;
        }

        return $screenshots;
    }
}
