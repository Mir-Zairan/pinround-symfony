<?php

declare(strict_types=1);

namespace App\Review\Domain;

/**
 * The screenshot a retake names is not in the draft it is added to: unknown, or in another round, another person's
 * draft or another organization. Callers answer it as not found, so the refusal tells none of these apart.
 */
final class RetakenScreenshotNotFound extends \DomainException
{
    public const string REASON = 'The screenshot to retake is not in this draft round.';

    public function __construct()
    {
        parent::__construct(self::REASON);
    }
}
