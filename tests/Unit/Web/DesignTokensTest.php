<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Keeps assets/styles/tokens.css in step with the token tables in the prototype guide.
 */
final class DesignTokensTest extends TestCase
{
    private const string GUIDE = __DIR__.'/../../../docs/04-prototype/prototype-guide.md';
    private const string TOKENS = __DIR__.'/../../../assets/styles/tokens.css';

    /**
     * Tokens added on purpose beyond the guide's table: the prototype has no text colour for
     * content on --error, and white on the dark --error fails contrast.
     */
    private const array EXTRA_TOKENS = [
        '--on-error' => ['light' => '#ffffff', 'dark' => '#690005'],
    ];

    #[Test]
    public function guideTableHasThirtyFourColourTokens(): void
    {
        self::assertCount(34, $this->guideTokens());
    }

    #[Test]
    public function lightValuesMatchTheGuide(): void
    {
        $light = $this->cssBlock('/^:root \{(.*?)^\}/ms');

        self::assertSame($this->expectedColumn('light'), $this->declarations($light));
    }

    #[Test]
    public function darkValuesUnderTheMediaQueryMatchTheGuide(): void
    {
        $dark = $this->cssBlock('/^@media \(prefers-color-scheme: dark\) \{\s*:root:not\(\[data-theme="light"\]\) \{(.*?)^  \}/ms');

        self::assertSame($this->expectedColumn('dark'), $this->declarations($dark));
    }

    #[Test]
    public function darkValuesUnderTheThemeOverrideMatchTheGuide(): void
    {
        $dark = $this->cssBlock('/^:root\[data-theme="dark"\] \{(.*?)^\}/ms');

        self::assertSame($this->expectedColumn('dark'), $this->declarations($dark));
    }

    #[Test]
    public function groupColoursMatchTheGuide(): void
    {
        $guide = file_get_contents(self::GUIDE);
        self::assertIsString($guide);
        preg_match_all('/^\| `(todo|doing|check|wait|done)` \| [^|]+ \| `(#[0-9a-f]{6})` \|/m', $guide, $rows, \PREG_SET_ORDER);

        $expected = [];
        foreach ($rows as $row) {
            $expected['--group-'.$row[1]] = $row[2];
        }

        ksort($expected);
        $declared = $this->declarations($this->fixedBlock());
        $declaredGroups = array_filter($declared, static fn (string $name): bool => str_starts_with($name, '--group-'), \ARRAY_FILTER_USE_KEY);

        self::assertCount(5, $expected);
        self::assertSame($expected, $declaredGroups);
    }

    #[Test]
    public function fixedBlockDoesNotRedeclareAColourToken(): void
    {
        $declared = array_keys($this->declarations($this->fixedBlock()));
        $colourTokens = [...array_keys($this->guideTokens()), ...array_keys(self::EXTRA_TOKENS)];

        self::assertSame([], array_values(array_intersect($declared, $colourTokens)));
    }

    /**
     * @return array<string, array{light: string, dark: string}>
     */
    private function guideTokens(): array
    {
        $guide = file_get_contents(self::GUIDE);
        self::assertIsString($guide);

        preg_match_all('/^\| `(--[a-z-]+)` \| `([^`]+)` \| `([^`]+)` \|$/m', $guide, $rows, \PREG_SET_ORDER);

        $tokens = [];
        foreach ($rows as $row) {
            $tokens[$row[1]] = ['light' => $row[2], 'dark' => $row[3]];
        }

        return $tokens;
    }

    /**
     * Guide values plus the named extras, sorted by name to match {@see declarations()}.
     *
     * @param 'light'|'dark' $column
     *
     * @return array<string, string>
     */
    private function expectedColumn(string $column): array
    {
        $expected = array_map(static fn (array $values): string => $values[$column], [...$this->guideTokens(), ...self::EXTRA_TOKENS]);
        ksort($expected);

        return $expected;
    }

    private function cssBlock(string $pattern): string
    {
        $css = file_get_contents(self::TOKENS);
        self::assertIsString($css);
        self::assertSame(1, preg_match($pattern, $css, $match), 'Block not found in tokens.css: '.$pattern);

        return $match[1];
    }

    /** The second top-level :root block: groups, radii, spacing and fonts. */
    private function fixedBlock(): string
    {
        $css = file_get_contents(self::TOKENS);
        self::assertIsString($css);
        self::assertSame(2, preg_match_all('/^:root \{(.*?)^\}/ms', $css, $matches), 'Expected a colour :root block and a fixed :root block.');

        return $matches[1][1];
    }

    /**
     * @return array<string, string> declarations sorted by name
     */
    private function declarations(string $block): array
    {
        preg_match_all('/^\s*(--[a-z-]+):\s*([^;]+);/m', $block, $rows, \PREG_SET_ORDER);

        $declarations = [];
        foreach ($rows as $row) {
            $declarations[$row[1]] = trim($row[2]);
        }
        ksort($declarations);

        return $declarations;
    }
}
