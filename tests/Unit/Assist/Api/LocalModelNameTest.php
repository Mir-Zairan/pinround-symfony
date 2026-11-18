<?php

declare(strict_types=1);

namespace App\Tests\Unit\Assist\Api;

use App\Assist\Api\LocalModelName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LocalModelNameTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function namesOllamaReports(): iterable
    {
        yield 'a name and a size tag' => ['qwen3:4b'];
        yield 'a dash and the latest tag' => ['phi4-mini:latest'];
        yield 'a short tag' => ['gemma3:1b'];
        yield 'a dot and an underscore' => ['llama3.2_q4'];
        yield 'one character' => ['m'];
        yield 'exactly 64 characters' => [str_repeat('a', 61).':4b'];
    }

    #[Test]
    #[DataProvider('namesOllamaReports')]
    public function aNameOfTheAllowedCharactersIsKeptAsItIs(string $name): void
    {
        self::assertTrue(LocalModelName::isValid($name));
        self::assertSame($name, new LocalModelName($name)->value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function namesThatBreakTheRule(): iterable
    {
        yield 'markup after the name' => ['qwen3:4b<script>'];
        yield 'an uppercase letter' => ['Qwen3:4b'];
        yield '65 characters' => [str_repeat('a', 62).':4b'];
        yield 'empty' => [''];
        yield 'a trailing line feed' => ["qwen3:4b\n"];
        yield 'a trailing CRLF' => ["qwen3:4b\r\n"];
        yield 'a leading byte order mark' => ["\u{FEFF}qwen3:4b"];
        yield 'a space' => ['qwen3 4b'];
        yield 'a multibyte letter' => ['qwén3:4b'];
        yield 'a slash' => ['library/qwen3:4b'];
    }

    #[Test]
    #[DataProvider('namesThatBreakTheRule')]
    public function aNameThatBreaksTheRuleIsRefusedWithTheRuleAndNeverTheName(string $name): void
    {
        self::assertFalse(LocalModelName::isValid($name));
        try {
            new LocalModelName($name);
            self::fail('The name was accepted.');
        } catch (\InvalidArgumentException $refusal) {
            self::assertSame('A local model name is 1 to 64 characters from a-z, 0-9 and ":._-".', $refusal->getMessage());
        }
    }
}
