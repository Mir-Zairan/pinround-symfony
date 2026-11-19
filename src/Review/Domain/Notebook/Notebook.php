<?php

declare(strict_types=1);

namespace App\Review\Domain\Notebook;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * A person's private notebook (ADR-0046): pinned screenshots and the scenarios Claude Code wrote for them. Only its
 * owner may see it; it belongs to no ticket. Every change returns a new notebook at the same version, which
 * Notebooks::save writes only over that version.
 */
final readonly class Notebook
{
    public const int TITLE_MAX_LENGTH = 120;
    public const int MAX_SCREENSHOTS = 10;
    public const int MAX_SCENARIOS = 20;

    /**
     * @param list<NotebookScreenshot>   $screenshots
     * @param list<array<string, mixed>> $scenarios
     */
    private function __construct(
        private NotebookId $id,
        private OrganizationId $organizationId,
        private UserId $ownerId,
        private string $title,
        private array $screenshots,
        private array $scenarios,
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
     * @throws NotebookRefused when the title is blank or longer than TITLE_MAX_LENGTH
     */
    public static function start(NotebookId $id, OrganizationId $organizationId, UserId $ownerId, string $title, \DateTimeImmutable $at): self
    {
        return new self($id, $organizationId, $ownerId, self::checkedTitle($title), [], [], 1, $at, $at, $at);
    }

    /**
     * A stored notebook as Notebooks reads it back, under the rules of start() and of each change.
     *
     * @param list<NotebookScreenshot>   $screenshots
     * @param list<array<string, mixed>> $scenarios
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
        array $scenarios,
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

        return new self($id, $organizationId, $ownerId, self::checkedTitle($title), self::checkedScreenshots($screenshots), self::checkedScenarios($scenarios), $version, $createdAt, $updatedAt, $lastUsedAt);
    }

    /**
     * @throws NotebookRefused when the title is blank or longer than TITLE_MAX_LENGTH
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
     * This notebook with $scenarios in place of its scenarios. Only their number is checked here; what a scenario
     * holds is checked before it gets here (P13.T7).
     *
     * @param list<array<string, mixed>> $scenarios
     *
     * @throws NotebookRefused when there are more than MAX_SCENARIOS
     */
    public function withScenarios(array $scenarios, \DateTimeImmutable $at): self
    {
        return $this->changed($this->title, $this->screenshots, self::checkedScenarios($scenarios), $at);
    }

    /**
     * This notebook last used at $at, as when its owner picks it as a target; nothing else changes.
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

    /**
     * @return list<array<string, mixed>>
     */
    public function scenarios(): array
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
     * @param list<NotebookScreenshot>   $screenshots
     * @param list<array<string, mixed>> $scenarios
     */
    private function changed(string $title, array $screenshots, array $scenarios, \DateTimeImmutable $at): self
    {
        $changedAt = max($this->createdAt, $at);

        return new self($this->id, $this->organizationId, $this->ownerId, $title, $screenshots, $scenarios, $this->version, $this->createdAt, $changedAt, $changedAt);
    }

    /**
     * @throws NotebookRefused
     */
    private static function checkedTitle(string $title): string
    {
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

    /**
     * @param list<array<string, mixed>> $scenarios
     *
     * @return list<array<string, mixed>>
     *
     * @throws NotebookRefused
     */
    private static function checkedScenarios(array $scenarios): array
    {
        if (\count($scenarios) > self::MAX_SCENARIOS) {
            throw NotebookRefused::because(NotebookRefused::TOO_MANY_SCENARIOS);
        }

        return $scenarios;
    }
}
