<?php

declare(strict_types=1);

namespace App\Media\UI\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Where a person picks, drops or pastes a screenshot: the markup of the presigned-upload controller, with the form
 * token of the media_upload calls. The returned media id lands in a hidden input called `fieldName`, for the form it
 * sits in.
 */
#[AsTwigComponent(name: 'UploadField', template: '@Media/components/UploadField.html.twig')]
final class UploadField
{
    /** What the screenshot is added to, as the begin request names it: a kind a MediaTargetAuthorizer decides and an id. */
    public string $targetKind;
    public string $targetId;

    /** The name of the hidden input that holds the media id. */
    public string $fieldName = 'media_id';

    /** Gives the field and its label a unique id when a page holds more than one. */
    public string $fieldId = 'upload';
}
