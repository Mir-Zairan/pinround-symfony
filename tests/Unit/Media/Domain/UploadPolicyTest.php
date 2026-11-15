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
        $this->expectExceptionMessage('A storage quota and the bytes used of it are 0 or more.');

        UploadPolicy::fitsQuota($quotaBytes, $usedBytes, 1);
    }
}
