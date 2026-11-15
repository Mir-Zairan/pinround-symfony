<?php

declare(strict_types=1);

namespace App\Media\Domain;

/**
 * A re-encoded WebP the processing worker stores for a ready media object; the value is its name in the variants of
 * MediaObject::markReady() and in its storage key.
 */
enum MediaVariant: string
{
    /** At most 1920 px wide, for the screenshot in a round or ticket. */
    case View = 'view';

    /** At most 480 px wide, for lists and previews. */
    case Thumb = 'thumb';
}
