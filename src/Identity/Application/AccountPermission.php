<?php

declare(strict_types=1);

namespace App\Identity\Application;

/**
 * The voter attributes for a person's own account (ADR-0013). OwnAccountVoter decides them.
 */
final class AccountPermission
{
    /** Change one's own password, sessions or profile. Its subject is null: the account is always the logged-in one. */
    public const string EDIT_OWN = 'ACCOUNT_EDIT_OWN';
}
