<?php

declare(strict_types=1);

namespace App\Tests\Unit\PHPUnit;

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * The second test runs after the first left UuidV7 at a future time; only the extension in phpunit.dist.xml can have
 * moved it back in between.
 */
final class RestoreUuidV7TimeExtensionTest extends TestCase
{
    #[Test]
    public function aUuidV7GeneratedAtAFrozenFutureTimeCarriesThatTime(): void
    {
        $future = new UuidV7(UuidV7::generate(new \DateTimeImmutable('2031-06-15 10:00:00 UTC')));

        self::assertSame('2031-06-15 10:00:00', $future->getDateTime()->format('Y-m-d H:i:s'));
        self::assertSame('2031', Uuid::v7()->getDateTime()->format('Y'), 'UuidV7 no longer keeps its last time; this test proves nothing.');
    }

    #[Test]
    #[Depends('aUuidV7GeneratedAtAFrozenFutureTimeCarriesThatTime')]
    public function theNextTestGeneratesUuidV7AtTheRealTimeAgain(): void
    {
        $before = (int) floor(microtime(true) * 1000);
        $generated = Uuid::v7();
        $after = (int) floor(microtime(true) * 1000);

        $generatedMs = (int) $generated->getDateTime()->format('Uv');
        self::assertGreaterThanOrEqual($before, $generatedMs);
        self::assertLessThanOrEqual($after, $generatedMs);
    }
}
