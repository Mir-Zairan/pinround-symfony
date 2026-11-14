<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Domain;

use App\Media\Domain\UploadIntentId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UploadIntentIdTest extends TestCase
{
    #[Test]
    public function anIdIsKeptInLowercaseAndEqualsItsOtherCase(): void
    {
        $id = new UploadIntentId('0197A3C0-1234-7ABC-BDEF-0123456789AB');

        self::assertSame('0197a3c0-1234-7abc-bdef-0123456789ab', $id->toString());
        self::assertTrue($id->equals(new UploadIntentId('0197a3c0-1234-7abc-bdef-0123456789ab')));
        self::assertFalse($id->equals(new UploadIntentId('0197a3c0-1234-7abc-bdef-0123456789ac')));
    }

    #[Test]
    public function anIdThatIsNotACanonicalUuidV7IsRefused(): void
    {
        foreach (['', '../etc/passwd', '0197a3c0-1234-4abc-8def-0123456789ab', '0197a3c0-1234-7abc-cdef-0123456789ab', "0197a3c0-1234-7abc-8def-0123456789ab\n", '0197a3c0-1234-7abc-8def-0123456789ab/x'] as $value) {
            try {
                new UploadIntentId($value);
                self::fail(\sprintf('Not refused: %s', json_encode($value)));
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('An upload intent id must be a canonical UUIDv7.', $exception->getMessage());
            }
        }
    }
}
