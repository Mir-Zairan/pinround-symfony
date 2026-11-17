<?php

declare(strict_types=1);

namespace App\Ticketing\Domain\Intake;

/**
 * The word lists the intake checks match a draft report against (FR-INT-08, FR-INT-09), as versioned constants: a
 * change to a list is a new VERSION, so a stored check can say which list decided it. Each entry is a regex fragment
 * matched case-insensitively on Unicode word boundaries; inflections are listed, as a whole-word match would miss them.
 */
final class WordLists
{
    public const int VERSION = 1;

    /**
     * FR-INT-09: a match makes the report team-only and keeps it from cloud AI. "unauth" is a prefix
     * (unauthorized, unauthenticated); "other tenant" and "another tenant" match across any whitespace, line ends too.
     */
    public const array SECURITY = [
        'security',
        'leak', 'leaks', 'leaked', 'leaking',
        'password', 'passwords',
        'unauth\p{L}*',
        'hack', 'hacks', 'hacked', 'hacker', 'hackers', 'hacking',
        'privacy',
        'gdpr',
        'token', 'tokens',
        'permission', 'permissions',
        'other\s+tenant', 'another\s+tenant',
    ];
}
