<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Domain;

use App\Media\Domain\UploadPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UploadPolicyTest extends TestCase
{
    private const int QUOTA = 10_000;

    /**
     * @return iterable<string, array{int, int, int, bool}>
     */
    public static function quotaCases(): iterable
    {
        yield 'used and declared exactly at the quota' => [self::QUOTA, 7_000, 3_000, true];
        yield 'one byte over the quota' => [self::QUOTA, 7_000, 3_001, false];
        yield 'nothing used yet, the whole quota declared' => [self::QUOTA, 0, self::QUOTA, true];
        yield 'the quota used up, one byte declared' => [self::QUOTA, self::QUOTA, 1, false];
        yield 'more used than the quota, as after the quota was lowered' => [self::QUOTA, 12_000, 1, false];
        yield 'a quota of 0, which is none, with far more used than any quota' => [0, \PHP_INT_MAX, UploadPolicy::MAX_BYTES, true];
        yield 'a used total that would overflow when added to' => [self::QUOTA, \PHP_INT_MAX, 1, false];
    }

    #[Test]
    #[DataProvider('quotaCases')]
    public function fitsQuotaTakesTheUploadUpToTheQuotaExactly(int $quotaBytes, int $usedBytes, int $declaredBytes, bool $fits): void
    {
        self::assertSame($fits, UploadPolicy::fitsQuota($quotaBytes, $usedBytes, $declaredBytes));
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function openUploadCounts(): iterable
    {
        yield 'none' => [0, true];
        yield 'one less than the most' => [19, true];
        yield 'the most' => [20, false];
        yield 'more than the most, as after the limit was lowered' => [21, false];
    }

    #[Test]
    #[DataProvider('openUploadCounts')]
    public function aPersonMayHoldTwentyUploadsOpenAtMost(int $openUploads, bool $allowsAnother): void
    {
        self::assertSame($allowsAnother, UploadPolicy::allowsAnotherOpenUpload($openUploads));
    }

    /**
     * @return iterable<string, array{int, int, bool}>
     */
    public static function dimensions(): iterable
    {
        yield 'exactly 40,000,000 px' => [8_000, 5_000, true];
        yield '40,000,005 px, the fewest above 40 megapixels with both sides allowed' => [3_015, 13_267, false];
        yield 'a width of exactly 16,384 px' => [16_384, 2_441, true];
        yield 'a height of exactly 16,384 px' => [2_441, 16_384, true];
        yield 'a width of 16,385 px and a height of 1' => [16_385, 1, false];
        yield 'a height of 16,385 px and a width of 1' => [1, 16_385, false];
        yield 'one pixel' => [1, 1, true];
        yield 'a width of 0' => [0, 10, false];
        yield 'a height of 0' => [10, 0, false];
        yield 'sides whose product would overflow' => [\PHP_INT_MAX, \PHP_INT_MAX, false];
    }

    #[Test]
    #[DataProvider('dimensions')]
    public function aPictureMayBeDecodedUpTo40MegapixelsWithNoSideAbove16384(int $width, int $height, bool $isAllowed): void
    {
        self::assertSame($isAllowed, UploadPolicy::allowsDimensions($width, $height));
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function negativeAmounts(): iterable
    {
        yield 'a negative quota' => [-1, 0];
        yield 'a negative used total' => [self::QUOTA, -1];
    }

    #[Test]
    #[DataProvider('negativeAmounts')]
    public function aNegativeQuotaOrUsedTotalIsRefusedRatherThanReadAsRoom(int $quotaBytes, int $usedBytes): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A storage quota and the bytes used of it are 0 or more.');

        UploadPolicy::fitsQuota($quotaBytes, $usedBytes, 1);
    }
}
