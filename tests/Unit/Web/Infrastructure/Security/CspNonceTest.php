<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web\Infrastructure\Security;

use App\Web\Infrastructure\Security\CspNonce;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CspNonceTest extends TestCase
{
    #[Test]
    public function itReturnsTheSameNonceForTheWholeRequest(): void
    {
        $cspNonce = new CspNonce();

        self::assertSame($cspNonce->current(), $cspNonce->current());
    }

    #[Test]
    public function aResetGivesTheNextRequestADifferentNonce(): void
    {
        $cspNonce = new CspNonce();
        $firstRequestNonce = $cspNonce->current();

        $cspNonce->reset();

        self::assertNotSame($firstRequestNonce, $cspNonce->current());
    }

    #[Test]
    public function twoServicesNeverShareANonce(): void
    {
        self::assertNotSame(new CspNonce()->current(), new CspNonce()->current());
    }

    #[Test]
    public function theNonceIsStrictBase64OfAtLeast16RandomBytes(): void
    {
        $nonce = new CspNonce()->current();

        self::assertMatchesRegularExpression('#^[A-Za-z0-9+/]+={0,2}$#', $nonce);
        $randomBytes = base64_decode($nonce, true);
        self::assertIsString($randomBytes);
        self::assertGreaterThanOrEqual(16, \strlen($randomBytes));
    }
}
