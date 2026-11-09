<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Domain;

use App\Organization\Domain\InvitationLinkToken;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class InvitationLinkTokenTest extends TestCase
{
    #[Test]
    public function aNewTokenIs32RandomBytesInBase64UrlWithoutPadding(): void
    {
        $token = InvitationLinkToken::generate();

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}\z/', $token->reveal());
        self::assertSame(32, \strlen((string) base64_decode(strtr($token->reveal(), '-_', '+/'), true)));
        self::assertNotSame($token->reveal(), InvitationLinkToken::generate()->reveal());
    }

    #[Test]
    public function theHashIsTheRawSha256OfTheTokenAsTheLinkCarriesIt(): void
    {
        $token = InvitationLinkToken::generate();

        self::assertSame(hash('sha256', $token->reveal(), true), $token->hash());
    }

    #[Test]
    public function aTokenIsNeverSerializedOrDumped(): void
    {
        $token = InvitationLinkToken::generate();

        self::assertStringNotContainsString($token->reveal(), print_r($token, true));
        $this->expectException(\LogicException::class);
        serialize($token);
    }
}
