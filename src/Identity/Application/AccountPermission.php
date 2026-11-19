<?php

declare(strict_types=1);

namespace App\Identity\Application;

/**
 * The voter attributes for a person's own account (ADR-0013). OwnAccountVoter decides EDIT_OWN, CurrentApiTokenVoter
 * REVOKE_CURRENT_TOKEN.
 */
final class AccountPermission
{
    /** Change one's own password, sessions or profile. Its subject is null: the account is always the logged-in one. */
    public const string EDIT_OWN = 'ACCOUNT_EDIT_OWN';

    /**
     * Revoke the personal access token the request came with, as the browser extension's sign-out does (P10.T8). Its
     * subject is null: the token is always the request's own. CurrentApiTokenVoter decides it.
     */
    public const string REVOKE_CURRENT_TOKEN = 'ACCOUNT_REVOKE_CURRENT_TOKEN';
}
