<?php

declare(strict_types=1);

namespace App\Identity\Application;

/**
 * The organization posted on the consent page is malformed, unknown, another one's, or one the person left: the same
 * refusal for each, so the answer does not tell which organizations exist (ADR-0050). No code was issued.
 */
final class OAuthConsentOrganizationNotFound extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('You are not a member of this organization.');
    }
}
