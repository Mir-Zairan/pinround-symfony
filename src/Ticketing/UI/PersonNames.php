<?php

declare(strict_types=1);

namespace App\Ticketing\UI;

/**
 * The words the ticket page, its "Your next step" box and the move's answers put in place of a person's name.
 */
final class PersonNames
{
    /** For a reporter, assignee or rule note person whose user row is gone, so a page never fails on a missing name. */
    public const string UNKNOWN_PERSON = 'an unknown person';
}
