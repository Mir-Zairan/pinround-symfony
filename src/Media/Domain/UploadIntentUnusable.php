<?php

declare(strict_types=1);

namespace App\Media\Domain;

/**
 * An upload intent was used already or has expired, so it cannot be used (again).
 */
final class UploadIntentUnusable extends \DomainException
{
    private function __construct(string $message, public readonly bool $hasExpired)
    {
        parent::__construct($message);
    }

    public static function usedAlready(): self
    {
        return new self('The upload intent is used already.', false);
    }

    public static function expired(): self
    {
        return new self('The upload intent has expired.', true);
    }
}
