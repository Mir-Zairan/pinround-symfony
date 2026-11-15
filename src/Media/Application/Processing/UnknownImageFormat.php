<?php

declare(strict_types=1);

namespace App\Media\Application\Processing;

/**
 * The bytes start with no PNG, JPEG or WebP signature.
 */
final class UnknownImageFormat extends \DomainException
{
    public function __construct()
    {
        parent::__construct('The bytes start with no PNG, JPEG or WebP signature.');
    }
}
