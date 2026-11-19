<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Api;

use App\Media\Api\MediaImageStatus;
use App\Media\Domain\MediaStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * DirectUploads answers a stored MediaStatus as MediaImageStatus::from() of its value, so a case on one side only would
 * fail at run time.
 */
final class MediaImageStatusTest extends TestCase
{
    #[Test]
    public function everyMediaStatusHasAMediaImageStatusOfTheSameValueAndNoMore(): void
    {
        $mediaStatusValues = array_map(static fn (MediaStatus $status): string => $status->value, MediaStatus::cases());
        $mediaImageStatusValues = array_map(static fn (MediaImageStatus $status): string => $status->value, MediaImageStatus::cases());

        self::assertEqualsCanonicalizing($mediaStatusValues, $mediaImageStatusValues);
    }
}
