<?php

declare(strict_types=1);

namespace App\Review\Domain;

/**
 * A screenshot holds Screenshot::MAX_TRIES tries already, so a retake of it is refused (ADR-0048); nothing is added.
 */
final class ScreenshotTriesFull extends \DomainException
{
    // Screenshot::MAX_TRIES; a constant expression cannot format it in.
    public const string REASON = 'This screenshot already has 5 tries. Remove one before you add another.';

    public function __construct()
    {
        parent::__construct(self::REASON);
    }
}
