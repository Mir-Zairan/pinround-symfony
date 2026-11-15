<?php

declare(strict_types=1);

namespace App\Media\Domain;

/**
 * Why the processing worker refused an upload, in the words the person sees. Fixed texts only, so no file name or
 * other user input can reach a stored reason; each fits MediaObject::MAX_REJECT_REASON_LENGTH.
 */
enum MediaRejectReason: string
{
    case Missing = 'The file did not arrive. Upload it again.';
    case TooLarge = 'This file is bigger than 15 MB. Pick a screenshot of up to 15 MB.';
    case WrongSize = 'The file that arrived is not the size that was announced. Upload it again.';
    case NotAnImage = 'This file is not a PNG, JPEG or WebP screenshot.';
    case WrongType = 'This file is not the type it was announced as. Upload it again.';
    case Animated = 'Animated images cannot be added. Pick a still screenshot.';
    case TooManyPixels = 'This image is too big. Pick a screenshot of up to 40 megapixels and 16,384 pixels a side.';
    case Unreadable = 'This image could not be read. Pick another screenshot.';
    case ChangedWhileChecked = 'The file changed while it was checked. Upload it again.';
}
