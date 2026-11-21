<?php

declare(strict_types=1);

namespace App\Identity\Api;

/**
 * No OAuth connection of the organization has the id: it is malformed, unknown or another organization's. The three
 * are refused alike, so a refusal never tells whether a connection exists elsewhere. Nothing was changed.
 */
final class OAuthConnectionNotFound extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('No connection of this organization has this id.');
    }
}
