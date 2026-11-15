<?php

declare(strict_types=1);

namespace App\Media\Application\Processing;

/**
 * The encoder could not decode the original fully, or refused its size. The message never quotes the file.
 */
final class UndecodableImage extends \DomainException
{
}
