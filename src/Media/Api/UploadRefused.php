<?php

declare(strict_types=1);

namespace App\Media\Api;

/**
 * An upload was refused for a reason the person can act on; the message is plain text to show them as it is.
 */
final class UploadRefused extends \DomainException
{
}
