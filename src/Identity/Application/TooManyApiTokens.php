<?php

declare(strict_types=1);

namespace App\Identity\Application;

/**
 * IssueApiToken refused a new token: the user already has the most live tokens one person may hold in the organization.
 * Nothing was added.
 */
final class TooManyApiTokens extends \RuntimeException
{
    public function __construct(public readonly int $maxLiveTokens)
    {
        parent::__construct(\sprintf('You have %d tokens that still work. Revoke one before you make another.', $maxLiveTokens));
    }
}
