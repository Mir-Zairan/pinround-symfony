<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Domain;

use App\Identity\Domain\PasswordResetToken;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PasswordResetTokenTest extends TestCase
{
    #[Test]
    public function aNewTokenIs32RandomBytesInBase64UrlWithoutPadding(): void
    {
        $token = PasswordResetToken::generate();

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $token->reveal());
        self::assertSame(32, \strlen((string) base64_decode(strtr($token->reveal(), '-_', '+/'), true)));
        self::assertNotSame($token->reveal(), PasswordResetToken::generate()->reveal());
    }

    #[Test]
    public function theHashIsTheRawSha256OfTheTokenAsTheLinkCarriesIt(): void
    {
        $token = PasswordResetToken::generate();

        self::assertSame(hash('sha256', $token->reveal(), true), $token->hash());
        self::assertSame($token->hash(), PasswordResetToken::fromLink($token->reveal())->hash());
    }

    #[Test]
    public function aTokenIsNeverSerializedOrDumped(): void
    {
        $token = PasswordResetToken::generate();

        self::assertStringNotContainsString($token->reveal(), print_r($token, true));
        $this->expectException(\LogicException::class);
        serialize($token);
    }
}
