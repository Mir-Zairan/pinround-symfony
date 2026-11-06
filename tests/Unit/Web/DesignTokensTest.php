<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Keeps assets/styles/tokens.css in step with the token table in the prototype guide.
 */
final class DesignTokensTest extends TestCase
{
    private const string GUIDE = __DIR__.'/../../../docs/04-prototype/prototype-guide.md';
    private const string TOKENS = __DIR__.'/../../../assets/styles/tokens.css';

    #[Test]
    public function guideTableHasThirtyFourColourTokens(): void
    {
        self::assertCount(34, $this->guideTokens());
    }

    #[Test]
    public function lightValuesMatchTheGuide(): void
    {
        $light = $this->cssBlock('/^:root \{(.*?)^\}/ms');

        self::assertSame($this->guideColumn('light'), $this->colourDeclarations($light));
    }

    #[Test]
    public function darkValuesUnderTheMediaQueryMatchTheGuide(): void
    {
        $dark = $this->cssBlock('/^@media \(prefers-color-scheme: dark\) \{\s*:root:not\(\[data-theme="light"\]\) \{(.*?)^  \}/ms');

        self::assertSame($this->guideColumn('dark'), $this->colourDeclarations($dark));
    }

    #[Test]
    public function darkValuesUnderTheThemeOverrideMatchTheGuide(): void
    {
        $dark = $this->cssBlock('/^:root\[data-theme="dark"\] \{(.*?)^\}/ms');

        self::assertSame($this->guideColumn('dark'), $this->colourDeclarations($dark));
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
     * @param 'light'|'dark' $column
     *
     * @return array<string, string>
     */
    private function guideColumn(string $column): array
    {
        return array_map(static fn (array $values): string => $values[$column], $this->guideTokens());
    }

    private function cssBlock(string $pattern): string
    {
        $css = file_get_contents(self::TOKENS);
        self::assertIsString($css);
        self::assertSame(1, preg_match($pattern, $css, $match), 'Block not found in tokens.css: '.$pattern);

        return $match[1];
    }

    /**
     * @return array<string, string>
     */
    private function colourDeclarations(string $block): array
    {
        preg_match_all('/^\s*(--[a-z-]+):\s*([^;]+);/m', $block, $rows, \PREG_SET_ORDER);

        $declarations = [];
        foreach ($rows as $row) {
            if (str_starts_with($row[1], '--group-') || str_starts_with($row[1], '--radius-') || str_starts_with($row[1], '--space-') || str_starts_with($row[1], '--font-')) {
                continue;
            }
            $declarations[$row[1]] = trim($row[2]);
        }

        return $declarations;
    }
}
