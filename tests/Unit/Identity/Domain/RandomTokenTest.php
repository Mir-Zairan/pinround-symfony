<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Domain;

use App\Identity\Domain\RandomToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RandomTokenTest extends TestCase
{
    /**
     * @return iterable<string, array{int<1, max>, int}>
     */
    public static function lengths(): iterable
    {
        yield 'a whole number of 3-byte groups, no padding to cut' => [24, 32];
        yield 'one byte past a group, two "=" cut' => [16, 22];
        yield 'two bytes past a group, one "=" cut' => [32, 43];
        yield 'one byte' => [1, 2];
    }

    /**
     * @param int<1, max> $bytes
     */
    #[Test]
    #[DataProvider('lengths')]
    public function theTokenIsBase64UrlWithoutPaddingOfTheGivenBytes(int $bytes, int $expectedLength): void
    {
        $token = RandomToken::base64Url($bytes);

        self::assertMatchesRegularExpression(\sprintf('/\A[A-Za-z0-9_-]{%d}\z/', $expectedLength), $token);
        $decoded = base64_decode(strtr($token, '-_', '+/'), true);
        self::assertIsString($decoded);
        self::assertSame($bytes, \strlen($decoded));
    }

    /**
     * 256 tokens of 32 bytes: base64 of that many bytes uses "+" and "/" almost surely, so a token that keeps them
     * fails, and two equal tokens would mean the bytes are not random.
     */
    #[Test]
    public function tokensUseOnlyUrlSafeCharactersAndDiffer(): void
    {
        $tokens = array_map(static fn (): string => RandomToken::base64Url(32), range(1, 256));

        self::assertCount(256, array_unique($tokens));
        self::assertSame([], array_values(array_filter($tokens, static fn (string $token): bool => 1 !== preg_match('/\A[A-Za-z0-9_-]{43}\z/', $token))));
        self::assertStringContainsString('-', implode('', $tokens));
        self::assertStringContainsString('_', implode('', $tokens));
    }
}
