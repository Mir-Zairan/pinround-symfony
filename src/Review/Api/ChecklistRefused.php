<?php

declare(strict_types=1);

namespace App\Review\Api;

/**
 * A checklist item was not changed. The message is the reason to show the person (422), except NOT_FOUND, which the
 * caller answers as a missing page (404).
 */
final class ChecklistRefused extends \DomainException
{
    /** An unknown or malformed item id, or another organization's item. */
    public const string NOT_FOUND = 'That checklist item does not exist.';

    public function isNotFound(): bool
    {
        return self::NOT_FOUND === $this->getMessage();
    }
}
