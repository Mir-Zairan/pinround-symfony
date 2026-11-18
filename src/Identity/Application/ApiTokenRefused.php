<?php

declare(strict_types=1);

namespace App\Identity\Application;

/**
 * IssueApiToken::issueWithScopeNames() refused the name, the scopes or the lifetime a person chose. Nothing was added;
 * the message says what to change, in words the page shows.
 */
final class ApiTokenRefused extends \RuntimeException
{
}
