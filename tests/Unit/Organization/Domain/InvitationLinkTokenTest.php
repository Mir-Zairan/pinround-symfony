<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Domain;

use App\Organization\Domain\InvitationLinkToken;
use PHPUnit\Framework\Attributes\DataProvider;
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
    public function aTokenFromALinkHashesLikeTheGeneratedOne(): void
    {
        $generated = InvitationLinkToken::generate();

        self::assertSame($generated->hash(), InvitationLinkToken::fromLink($generated->reveal())?->hash());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function linksThatCannotCarryAToken(): iterable
    {
        yield 'empty' => [''];
        yield 'one character short' => [str_repeat('a', 42)];
        yield 'one character long' => [str_repeat('a', 44)];
        yield 'base64 with plus' => [str_repeat('a', 42).'+'];
        yield 'trailing newline' => [str_repeat('a', 43)."\n"];
    }

    #[Test]
    #[DataProvider('linksThatCannotCarryAToken')]
    public function aLinkThatCannotCarryATokenGivesNone(string $fromLink): void
    {
        self::assertNull(InvitationLinkToken::fromLink($fromLink));
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
