<?php

declare(strict_types=1);

namespace App\Organization\Domain;

/**
 * The one form of an organization's slug and a team's slug: lowercase letters and digits in groups joined by single
 * hyphens, 63 characters at most (a DNS label).
 */
final class SlugFormat
{
    /** The groups without the anchors and the length, as a route requirement needs them. */
    public const string GROUPS = '[a-z0-9]+(?:-[a-z0-9]+)*';

    public const string PATTERN = '/^(?=.{1,63}\z)'.self::GROUPS.'\z/';

    /** The end of the message that refuses a slug of another form. */
    public const string RULE = 'lowercase letters and digits joined by single hyphens, at most 63 characters';

    private function __construct()
    {
    }
}
