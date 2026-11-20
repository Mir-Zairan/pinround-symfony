<?php

declare(strict_types=1);

namespace App\Review\Domain;

/**
 * A draft round with no screenshot cannot be shared (ADR-0048, no empty share); it stays unshared and unnumbered.
 */
final class DraftHasNoScreenshots extends \DomainException
{
    public const string REASON = 'Add a screenshot before you share.';

    public function __construct()
    {
        parent::__construct(self::REASON);
    }
}
