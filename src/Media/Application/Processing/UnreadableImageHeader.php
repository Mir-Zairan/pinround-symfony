<?php

declare(strict_types=1);

namespace App\Media\Application\Processing;

/**
 * The bytes start with a known signature, but the header after it is broken, states no size or runs past the bytes
 * read. The message names the format and the fault only, never a byte of the file.
 */
final class UnreadableImageHeader extends \DomainException
{
}
