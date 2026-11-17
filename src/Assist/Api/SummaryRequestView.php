<?php

declare(strict_types=1);

namespace App\Assist\Api;

use App\SharedKernel\Domain\UserId;

/**
 * The latest "Summarize with AI" request of a ticket that has not ended in a stored summary, for the page that polls
 * it: queued while the AI writes, or failed with the line to show. It never carries ticket text or the AI's answer.
 */
final readonly class SummaryRequestView
{
    public const string QUEUED = 'queued';
    public const string FAILED = 'failed';

    /**
     * @param string      $status   QUEUED or FAILED
     * @param string|null $sentence the line to show when FAILED; null when QUEUED
     */
    private function __construct(
        public string $status,
        public UserId $requesterId,
        public \DateTimeImmutable $at,
        public ?string $sentence,
    ) {
    }

    /**
     * @param \DateTimeImmutable $requestedAt when the person pressed the button
     */
    public static function queued(UserId $requesterId, \DateTimeImmutable $requestedAt): self
    {
        return new self(self::QUEUED, $requesterId, $requestedAt, null);
    }

    /**
     * @param \DateTimeImmutable $failedAt when the job gave up
     * @param string             $sentence the line to show, never the reason the provider or the check gave
     *
     * @throws \InvalidArgumentException when the sentence is blank
     */
    public static function failed(UserId $requesterId, \DateTimeImmutable $failedAt, string $sentence): self
    {
        if ('' === trim($sentence)) {
            throw new \InvalidArgumentException('A failed summary request says why in a sentence.');
        }

        return new self(self::FAILED, $requesterId, $failedAt, $sentence);
    }

    public function isQueued(): bool
    {
        return self::QUEUED === $this->status;
    }
}
