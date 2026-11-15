<?php

declare(strict_types=1);

namespace App\Review\Api;

use App\SharedKernel\Domain\UserId;

/**
 * One round of a ticket for its Rounds section, with its screenshots and their pins.
 */
final readonly class RoundView
{
    public const string DRAFT = 'draft';

    /**
     * @param int|null                  $number      null exactly while the round is an unshared draft
     * @param string                    $kind        report, checklist, review or draft
     * @param string                    $state       open or closed
     * @param list<RoundScreenshotView> $screenshots by position; none for a checklist or review round, which is not loaded with them
     */
    public function __construct(
        public string $id,
        public ?int $number,
        public string $kind,
        public UserId $ownerId,
        public ?\DateTimeImmutable $sharedAt,
        public string $state,
        public array $screenshots,
    ) {
    }

    /**
     * Only its owner is ever shown an unshared draft (ADR-0017).
     */
    public function isUnsharedDraft(): bool
    {
        return self::DRAFT === $this->kind && null === $this->sharedAt;
    }
}
