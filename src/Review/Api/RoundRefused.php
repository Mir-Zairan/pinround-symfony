<?php

declare(strict_types=1);

namespace App\Review\Api;

/**
 * The round's content breaks a rule, such as a title over 120 characters, or the ticket has that round already, so
 * nothing was saved. The message is the reason to show the person (422), except NOT_FOUND, which the caller answers as
 * a missing page (404).
 */
final class RoundRefused extends \DomainException
{
    /** A picture the person did not upload: another person's, another organization's, an unknown or a malformed id. */
    public const string MEDIA_NOT_UPLOADED_BY_YOU = 'Add a screenshot you uploaded yourself.';
    public const string MEDIA_STILL_PROCESSING = 'Still processing your screenshot. Try again in a moment.';
    /** The text the Screenshot component shows on a rejected picture. */
    public const string MEDIA_REJECTED = 'We could not process this image. Try again.';

    /**
     * No draft round of the person with that id: an unknown or malformed id, another organization's round, another
     * person's draft, a round that is not a draft, or a deleted draft. One answer for all, so nobody learns that
     * another person's draft exists (ADR-0017).
     */
    public const string NOT_FOUND = 'That draft round does not exist.';
    public const string DRAFT_EXISTS = 'You have a draft round on this ticket already.';
    /** Another request saved the draft between this one's read and its save. */
    public const string DRAFT_CHANGED = 'This draft changed in another tab. Reload and try again.';
    /** An unknown or malformed screenshot id, or one of another round; the same text as Round's refusal. */
    public const string SCREENSHOT_NOT_FOUND = 'This draft round has no such screenshot.';
    public const string UNKNOWN_VERDICT = 'Choose "Looks right" or "Not right yet".';
    /** A round saved without RoundCommands took the number while the draft was being shared. */
    public const string NUMBER_TAKEN = 'Another round took this number just now. Try again.';
}
