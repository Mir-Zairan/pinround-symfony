<?php

declare(strict_types=1);

namespace App\Media\Api;

/**
 * The voter attributes for direct uploads (ADR-0013); the subject of both is an UploadTarget.
 */
final class MediaPermission
{
    /** Begin an upload for a target; the MediaTargetAuthorizer of the target's kind decides. */
    public const string UPLOAD = 'MEDIA_UPLOAD';

    /** Complete an upload begun for a target; the same decision again, as access may have ended meanwhile. */
    public const string COMPLETE_UPLOAD = 'MEDIA_COMPLETE_UPLOAD';
}
