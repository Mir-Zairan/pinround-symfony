<?php

declare(strict_types=1);

namespace App\Identity\Application;

/**
 * RevokeApiToken found no token with the id that the person owns in the organization: the id is malformed, unknown,
 * another organization's or another person's. The four are refused alike, so a refusal never tells whether a token
 * exists. Nothing was changed.
 */
final class ApiTokenNotFound extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('No token of yours has this id.');
    }
}
