<?php

declare(strict_types=1);

namespace App\Media\Domain;

/**
 * An upload intent was used already or has expired, so it cannot be used (again).
 */
final class UploadIntentUnusable extends \DomainException
{
}
