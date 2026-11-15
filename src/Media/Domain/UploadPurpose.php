<?php

declare(strict_types=1);

namespace App\Media\Domain;

/**
 * What an upload is for; med_upload_intent_purpose_check lists the same values.
 */
enum UploadPurpose: string
{
    case Screenshot = 'screenshot';
}
