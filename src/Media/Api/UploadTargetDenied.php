<?php

declare(strict_types=1);

namespace App\Media\Api;

/**
 * The MediaTargetAuthorizer of the upload's target refused this person, or no authorizer decides its kind; the caller
 * answers it as forbidden (403).
 */
final class UploadTargetDenied extends \DomainException
{
}
