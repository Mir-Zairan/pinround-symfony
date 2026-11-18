<?php

declare(strict_types=1);

namespace App\Tests\Unit\Assist\UI\Component;

use App\Assist\UI\Component\TheAsk;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The ask's 700-character cut.
 */
final class TheAskTest extends TestCase
{
    private const string BOM = "\u{FEFF}";

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function asks(): iterable
    {
        $words = str_repeat('pay now ', 87);
        yield 'empty' => ['', null];
        yield 'exactly 700 characters' => [$words.'pays', null];
        yield '700 multibyte characters are not cut by their bytes' => [str_repeat('ü', 700), null];
        yield 'the cut falls inside a word' => [$words.'checkout total', rtrim($words).' …'];
        yield 'the cut falls on a space' => [$words.'pays more', $words.'pays …'];
        yield 'one word longer than the limit' => [str_repeat('a', 701), str_repeat('a', 700).' …'];
        yield 'multibyte words' => [str_repeat('größe ', 116).'zahlung', rtrim(str_repeat('größe ', 116)).' …'];
        yield 'a CR LF before the cut' => [str_repeat('x', 690)."\r\n".str_repeat('y', 20), str_repeat('x', 690).' …'];
        yield 'the cut falls between CR and LF' => [str_repeat('x', 699)."\r\n".'tail', str_repeat('x', 699).' …'];
        yield 'CR LF line ends all through' => ['x'.str_repeat("pay now\r\n", 78), 'x'.str_repeat("pay now\r\n", 77).'pay …'];
        yield 'a BOM counts as one character of the first word' => [self::BOM.str_repeat('a', 699).' b', self::BOM.str_repeat('a', 699).' …'];
        yield 'a BOM and 699 characters fit' => [self::BOM.str_repeat('a', 699), null];
        yield 'no final newline after a last word that is cut' => [$words.'payment', rtrim($words).' …'];
        yield 'a final newline past the cut' => [$words.'pays'."\n", $words.'pays …'];
    }

    #[Test]
    #[DataProvider('asks')]
    public function anAskLongerThan700CharactersIsCutAtTheLastWordEnd(string $description, ?string $expected): void
    {
        self::assertSame($expected, TheAsk::shortAskOf($description));
    }

    #[Test]
    public function aCutAskKeepsAtMost700CharactersOfTheAsk(): void
    {
        $shortAsk = TheAsk::shortAskOf(str_repeat('word ', 300));

        self::assertNotNull($shortAsk);
        self::assertSame(699, mb_strlen($shortAsk) - mb_strlen(TheAsk::ASK_CUT_MARK));
    }
}
