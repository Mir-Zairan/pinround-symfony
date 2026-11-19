<?php

declare(strict_types=1);

namespace App\Ticketing\Domain\Intake;

/**
 * The word lists the intake checks match a draft report against (FR-INT-08, FR-INT-09), as versioned constants: a
 * change to a list is a new VERSION, so a stored check can say which list decided it. Each entry is a regex fragment
 * matched case-insensitively between Unicode letter-or-digit boundaries.
 */
final class WordLists
{
    public const int VERSION = 3;

    /**
     * FR-INT-09: a match makes the report team-only and keeps it from cloud AI. The spec's stems are prefixes
     * (\p{L}*), with the negated and compound forms that a left boundary would miss (insecure, unsecured,
     * cybersecurity). "hack" and "token" list their inflections instead, as a prefix would catch "hackathon" and
     * "tokenizer". "other tenant(s)" and "another tenant" match across any whitespace, line ends too.
     */
    public const array SECURITY = [
        'secur\p{L}*', 'insecur\p{L}*', 'unsecur\p{L}*', 'cybersecur\p{L}*',
        'leak\p{L}*',
        'password\p{L}*',
        'unauth\p{L}*',
        'hack', 'hacks', 'hacked', 'hacker', 'hackers', 'hacking',
        'privacy',
        'gdpr',
        'token', 'tokens',
        'permission\p{L}*',
        'other\s+tenants?', 'another\s+tenant',
    ];

    /**
     * FR-INT-08: a match adds the check reason "risk" and nothing else; it never changes who may see the ticket. Every
     * security word counts, and the prototype's risk words besides: tenant, data loss and lost data, deleted, crash,
     * the whole word "down", cannot or can't log in (any apostrophe, "login" too), all customers and everyone.
     */
    public const array RISK = [
        ...self::SECURITY,
        'tenants?',
        'data\s+loss', 'lost\s+data',
        'deleted',
        'crash\p{L}*',
        'down',
        'can(?:not|\s+not|[\'’]t)\s+log\s*in',
        'all\s+customers',
        'everyone',
    ];
}
