<?php

declare(strict_types=1);

namespace App\Media\Api;

/**
 * The voter attribute for direct uploads (ADR-0013). Completing an upload has none: Uploads::complete() takes only the
 * intent's creator and asks the MediaTargetAuthorizer of the target stored at begin() again.
 */
final class MediaPermission
{
    /** Begin an upload for a target; the subject is an UploadTarget, the MediaTargetAuthorizer of its kind decides. */
    public const string UPLOAD = 'MEDIA_UPLOAD';
}
