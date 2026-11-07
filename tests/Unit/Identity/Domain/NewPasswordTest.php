<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Domain;

use App\Identity\Domain\NewPassword;
use App\Identity\Domain\PasswordTooShort;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NewPasswordTest extends TestCase
{
    #[Test]
    public function itRefusesElevenCharacters(): void
    {
        $this->expectException(PasswordTooShort::class);

        new NewPassword('abcdefghijk');
    }

    #[Test]
    public function itAcceptsTwelveCharactersWithoutCompositionRules(): void
    {
        self::assertSame('abcdefghijkl', new NewPassword('abcdefghijkl')->reveal());
    }

    #[Test]
    public function itCountsCharactersNotBytes(): void
    {
        // 12 characters, 24 bytes: a byte count would accept the 11-character one too.
        $twelve = 'ääääääääääää';
        self::assertSame($twelve, new NewPassword($twelve)->reveal());

        $this->expectException(PasswordTooShort::class);
        new NewPassword('ääääääääääá');
    }

    #[Test]
    public function aDumpShowsNoPlainText(): void
    {
        $dump = print_r(new NewPassword('lena-knows-this-one'), true);

        self::assertStringNotContainsString('lena-knows-this-one', $dump);
    }

    #[Test]
    public function itCannotBeSerialized(): void
    {
        $this->expectException(\LogicException::class);

        serialize(new NewPassword('lena-knows-this-one'));
    }

    #[Test]
    public function aStackTraceShowsNoPlainText(): void
    {
        try {
            new NewPassword('too-short');
            self::fail('A short password was accepted.');
        } catch (PasswordTooShort $refusal) {
            self::assertStringNotContainsString('too-short', $refusal->getTraceAsString());
        }
    }
}
