<?php

declare(strict_types=1);

namespace App\Media\Api;

/**
 * The organization has no upload intent with that id that this person began, or the id is malformed; the caller
 * answers it as not found (404).
 */
final class UploadIntentNotFound extends \DomainException
{
}
