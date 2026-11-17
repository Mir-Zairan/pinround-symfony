<?php

declare(strict_types=1);

namespace App\Review\Api;

/**
 * A verdict was not saved. The message is the reason to show the person (422), except the three not-found ones, which
 * the caller answers as a missing page (404).
 */
final class VerdictRefused extends \DomainException
{
    /** An unknown or malformed round id, or another organization's round. */
    public const string NOT_FOUND = 'That round does not exist.';
    /** An unknown or malformed area id, or an area of another round. */
    public const string AREA_NOT_FOUND = 'This round has no such area.';
    /** An unknown or malformed pin id, or a pin on no "before" picture of the round's areas. */
    public const string PIN_NOT_FOUND = 'This round has no such pin to check.';
    /** The same text as Area::VERDICT_MISSING, the prototype's toast. */
    public const string PICK_A_VERDICT = 'Pick Pass, Needs fix or Question first.';
    public const string UNKNOWN_PIN_VERDICT = 'Choose Fixed or Still wrong.';
    /** Another request changed the pin's verdict between this one's read and its save. */
    public const string PIN_CHANGED = 'This pin changed in another tab. Reload and try again.';

    public function isNotFound(): bool
    {
        return \in_array($this->getMessage(), [self::NOT_FOUND, self::AREA_NOT_FOUND, self::PIN_NOT_FOUND], true);
    }
}
