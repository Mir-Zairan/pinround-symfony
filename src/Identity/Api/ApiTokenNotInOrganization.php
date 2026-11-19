<?php

declare(strict_types=1);

namespace App\Identity\Api;

/**
 * OrganizationApiTokens::revokeAsAdmin() found no token of the organization with the id: the id is malformed, unknown
 * or another organization's. The three are refused alike, so a refusal never tells whether a token exists elsewhere.
 * Nothing was changed.
 */
final class ApiTokenNotInOrganization extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('No token of this organization has this id.');
    }
}
