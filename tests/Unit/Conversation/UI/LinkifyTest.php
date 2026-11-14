<?php

declare(strict_types=1);

namespace App\Tests\Unit\Conversation\UI;

use App\Conversation\UI\Linkify;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LinkifyTest extends TestCase
{
    #[Test]
    public function anHttpsAddressInsideTextBecomesALinkThatOpensWithNoOpener(): void
    {
        self::assertSame(
            'See <a href="https://acme.test/shop?item=1&amp;size=2" rel="noopener noreferrer" target="_blank">https://acme.test/shop?item=1&amp;size=2</a> for the build',
            new Linkify()->linkify('See https://acme.test/shop?item=1&size=2 for the build'),
        );
    }

    #[Test]
    public function anHttpAddressBecomesALinkWhateverTheSchemeCase(): void
    {
        self::assertSame(
            '<a href="HTTP://acme.test" rel="noopener noreferrer" target="_blank">HTTP://acme.test</a>',
            new Linkify()->linkify('HTTP://acme.test'),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function textThatIsNotLinked(): iterable
    {
        yield 'javascript' => ['javascript:alert(1)', 'javascript:alert(1)'];
        yield 'javascript with slashes' => ['javascript://acme.test/%0Aalert(1)', 'javascript://acme.test/%0Aalert(1)'];
        yield 'data' => ['data:text/html,<b>hi</b>', 'data:text/html,&lt;b&gt;hi&lt;/b&gt;'];
        yield 'ftp' => ['ftp://acme.test/file', 'ftp://acme.test/file'];
        yield 'scheme glued to a word' => ['xhttps://acme.test', 'xhttps://acme.test'];
        yield 'scheme with no host' => ['https://', 'https://'];
    }

    #[Test]
    #[DataProvider('textThatIsNotLinked')]
    public function onlyHttpAndHttpsAddressesAreLinked(string $text, string $html): void
    {
        self::assertSame($html, new Linkify()->linkify($text));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function addressesFollowedByPunctuation(): iterable
    {
        yield 'full stop' => ['Look at https://acme.test/a.', 'https://acme.test/a', '.'];
        yield 'comma' => ['https://acme.test/a, then', 'https://acme.test/a', ', then'];
        yield 'question and exclamation' => ['https://acme.test/a?!', 'https://acme.test/a', '?!'];
        yield 'closing bracket of the sentence' => ['(see https://acme.test/a)', 'https://acme.test/a', ')'];
        yield 'bracket the address opens' => ['https://acme.test/wiki/Shop_(app).', 'https://acme.test/wiki/Shop_(app)', '.'];
    }

    #[Test]
    #[DataProvider('addressesFollowedByPunctuation')]
    public function trailingPunctuationStaysOutsideTheLink(string $text, string $linked, string $after): void
    {
        $html = new Linkify()->linkify($text);

        self::assertStringContainsString(\sprintf('<a href="%1$s" rel="noopener noreferrer" target="_blank">%1$s</a>%2$s', $linked, $after), $html);
    }

    #[Test]
    public function markupIsEscapedAndNeverRuns(): void
    {
        self::assertSame(
            '&lt;script&gt;alert(&apos;x&apos;)&lt;/script&gt; &amp; &quot;quotes&quot;',
            new Linkify()->linkify('<script>alert(\'x\')</script> & "quotes"'),
        );
    }

    #[Test]
    public function anAddressCannotBreakOutOfItsAttribute(): void
    {
        self::assertSame(
            '<a href="https://acme.test/" rel="noopener noreferrer" target="_blank">https://acme.test/</a>&quot;onmouseover=&quot;alert(1)',
            new Linkify()->linkify('https://acme.test/"onmouseover="alert(1)'),
        );
        self::assertSame(
            '<a href="https://acme.test/&apos;onmouseover=&apos;alert(1)" rel="noopener noreferrer" target="_blank">https://acme.test/&apos;onmouseover=&apos;alert(1)</a>',
            new Linkify()->linkify('https://acme.test/\'onmouseover=\'alert(1)'),
        );
    }

    #[Test]
    public function crlfLoneCrAndLfLineBreaksEachBecomeOneBreak(): void
    {
        self::assertSame(
            "One<br>\nTwo<br>\nThree<br>\nFour",
            new Linkify()->linkify("One\r\nTwo\rThree\nFour"),
        );
    }

    #[Test]
    public function anAddressAtTheEndOfALineEndsBeforeTheLineBreak(): void
    {
        self::assertSame(
            "<a href=\"https://acme.test/a\" rel=\"noopener noreferrer\" target=\"_blank\">https://acme.test/a</a><br>\nNext",
            new Linkify()->linkify("https://acme.test/a\r\nNext"),
        );
    }

    #[Test]
    public function textWithNoFinalNewlineEndsWithNoBreak(): void
    {
        self::assertSame(
            'Fixed in <a href="https://acme.test/b" rel="noopener noreferrer" target="_blank">https://acme.test/b</a>',
            new Linkify()->linkify('Fixed in https://acme.test/b'),
        );
    }

    #[Test]
    public function multibyteTextAroundAndInsideAnAddressStaysWhole(): void
    {
        self::assertSame(
            'Grüße 😀 <a href="https://bäckerei.test/straße" rel="noopener noreferrer" target="_blank">https://bäckerei.test/straße</a>.',
            new Linkify()->linkify('Grüße 😀 https://bäckerei.test/straße.'),
        );
    }

    #[Test]
    public function invalidUtf8IsSubstitutedAndNothingIsLinked(): void
    {
        self::assertSame("Bad \u{FFFD}( https://acme.test", new Linkify()->linkify("Bad \xC3\x28 https://acme.test"));
    }

    #[Test]
    public function emptyAndMissingTextGiveNothing(): void
    {
        self::assertSame('', new Linkify()->linkify(''));
        self::assertSame('', new Linkify()->linkify(null));
    }
}
