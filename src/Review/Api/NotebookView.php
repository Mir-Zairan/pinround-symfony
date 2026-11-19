<?php

declare(strict_types=1);

namespace App\Review\Api;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * One notebook as its owner sees it (ADR-0046), and the subject NotebookVoter decides on. Titles, summaries, pin notes
 * and scenarios are the person's or their agent's text: untrusted, to be escaped or put inside delimiters.
 */
final readonly class NotebookView
{
    /**
     * @param string                                                                                                                              $id          the notebook's uuid, in lowercase
     * @param list<ScreenshotWithPins>                                                                                                            $screenshots at most Notebooks::MAX_SCREENSHOTS, in the order they were added; media ids in lowercase
     * @param list<array{title: string, why: string, setup: string, start_url: string, steps: list<array<string, string|array<string, string>>>}> $scenarios   at most Notebooks::MAX_SCENARIOS, in scenario.v1.json's format, in their order
     * @param int                                                                                                                                 $version     from 1; each change raises it by one
     */
    public function __construct(
        public string $id,
        public OrganizationId $organizationId,
        public UserId $ownerId,
        public string $title,
        public array $screenshots,
        public array $scenarios,
        public int $version,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
        public \DateTimeImmutable $lastUsedAt,
    ) {
    }
}
