<?php

declare(strict_types=1);

namespace App\Review\Api;

/**
 * The round's content breaks a rule, such as a title over 120 characters, or the ticket has that round already, so
 * nothing was saved. The message is the reason to show the person (422).
 */
final class RoundRefused extends \DomainException
{
    /** A picture the person did not upload: another person's, another organization's, an unknown or a malformed id. */
    public const string MEDIA_NOT_UPLOADED_BY_YOU = 'Add a screenshot you uploaded yourself.';
    public const string MEDIA_STILL_PROCESSING = 'Still processing your screenshot. Try again in a moment.';
    /** The text the Screenshot component shows on a rejected picture. */
    public const string MEDIA_REJECTED = 'We could not process this image. Try again.';
}
