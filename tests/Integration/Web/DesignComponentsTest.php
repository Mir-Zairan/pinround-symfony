<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Web\UI\Component\AiChip;
use App\Web\UI\Component\Chip;
use App\Web\UI\Component\Group;
use App\Web\UI\Component\SegmentedControl;
use App\Web\UI\Component\StatusPill;
use App\Web\UI\Component\SwitchControl;
use App\Web\UI\Component\Tag;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DesignComponentsTest extends KernelTestCase
{
    use RendersTwigComponents;

    private const array OPTIONS = ['list' => 'List', 'turn' => 'Whose turn?', '3' => 'Three'];

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function pillGroups(): iterable
    {
        foreach (Group::cases() as $group) {
            yield $group->value => [$group->value, 'pill pill-'.$group->value];
        }
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function groupTokens(): iterable
    {
        yield 'todo' => ['pill-todo', '--s-highest', '--on-surface'];
        yield 'doing' => ['pill-doing', '--secondary-container', '--on-secondary-container'];
        yield 'checking' => ['pill-checking', '--ping-container', '--on-ping-container'];
        yield 'waiting' => ['pill-waiting', '--waiting-container', '--on-waiting-container'];
        yield 'done' => ['pill-done', '--passed-container', '--on-passed-container'];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function dotTokens(): iterable
    {
        yield 'todo' => ['pill-todo', '--group-todo'];
        yield 'doing' => ['pill-doing', '--group-doing'];
        yield 'checking' => ['pill-checking', '--group-check'];
        yield 'waiting' => ['pill-waiting', '--group-wait'];
        yield 'done' => ['pill-done', '--group-done'];
    }

    #[Test]
    public function aChipIsUnpressedByDefault(): void
    {
        $html = (string) $this->renderTwigComponent(Chip::class, [], content: 'Acme Foods');

        self::assertMatchesRegularExpression('#^\s*<button class="chip" type="button" aria-pressed="false">Acme Foods</button>\s*$#', $html);
    }

    #[Test]
    public function aPressedChipSaysSo(): void
    {
        $html = (string) $this->renderTwigComponent(Chip::class, ['pressed' => true], content: 'Acme Foods');

        self::assertStringContainsString('aria-pressed="true"', $html);
    }

    #[Test]
    public function aChipPassesExtraAttributesThrough(): void
    {
        $html = (string) $this->renderTwigComponent(Chip::class, ['data-filter' => 'mine'], content: 'x');

        self::assertStringContainsString('data-filter="mine"', $html);
    }

    #[Test]
    public function aSegmentedControlIsALabelledGroupWithOnePressedOption(): void
    {
        $html = (string) $this->renderTwigComponent(SegmentedControl::class, ['label' => 'View', 'options' => self::OPTIONS, 'selected' => 'turn']);

        self::assertStringContainsString('role="group" aria-label="View"', $html);
        self::assertSame(3, substr_count($html, 'class="seg"'));
        self::assertSame(1, substr_count($html, 'aria-pressed="true"'));
        self::assertStringContainsString('<button type="button" class="seg" value="turn" aria-pressed="true">Whose turn?</button>', $html);
        self::assertStringContainsString('<button type="button" class="seg" value="list" aria-pressed="false">List</button>', $html);
    }

    #[Test]
    public function aSegmentedControlSelectsANumericValue(): void
    {
        $html = (string) $this->renderTwigComponent(SegmentedControl::class, ['label' => 'View', 'options' => self::OPTIONS, 'selected' => '3']);

        self::assertStringContainsString('value="3" aria-pressed="true">Three<', $html);
        self::assertSame(1, substr_count($html, 'aria-pressed="true"'));
    }

    #[Test]
    public function aSegmentedControlWithAnUnknownSelectionFailsAtMount(): void
    {
        $this->assertFailsAtMount(SegmentedControl::class, ['label' => 'View', 'options' => self::OPTIONS, 'selected' => 'nope'], 'not one of');
    }

    #[Test]
    public function aSegmentedControlWithoutALabelFailsAtMount(): void
    {
        $this->assertFailsAtMount(SegmentedControl::class, ['options' => self::OPTIONS, 'selected' => 'list'], 'needs a non-empty label');
    }

    #[Test]
    public function aTagRendersItsClassAndEscapesContent(): void
    {
        $html = $this->renderTemplate('<twig:Tag>{{ text }}</twig:Tag>', ['text' => '<b>PRIVATE</b>']);

        self::assertStringContainsString('<span class="tag">&lt;b&gt;PRIVATE&lt;/b&gt;</span>', $html);
    }

    #[Test]
    public function anAiChipRendersItsClassAndEscapesContent(): void
    {
        $html = $this->renderTemplate('<twig:AiChip>{{ text }}</twig:AiChip>', ['text' => '<b>AI SUMMARY</b>']);

        self::assertStringContainsString('<span class="aichip">&lt;b&gt;AI SUMMARY&lt;/b&gt;</span>', $html);
    }

    #[Test]
    public function tagAndAiChipRenderPlainContent(): void
    {
        self::assertStringContainsString('<span class="tag">EXAMPLE</span>', (string) $this->renderTwigComponent(Tag::class, [], content: 'EXAMPLE'));
        self::assertStringContainsString('<span class="aichip">AI ORDER</span>', (string) $this->renderTwigComponent(AiChip::class, [], content: 'AI ORDER'));
    }

    #[Test]
    #[DataProvider('pillGroups')]
    public function aStatusPillCarriesItsGroupClassAndTheStepName(string $group, string $classes): void
    {
        $html = (string) $this->renderTwigComponent(StatusPill::class, ['group' => $group], content: 'Code review');

        self::assertStringContainsString('<span class="'.$classes.'"><span class="pill__dot" aria-hidden="true"></span>Code review</span>', $html);
    }

    #[Test]
    #[DataProvider('dotTokens')]
    public function theStylesheetColoursEachPillDotWithItsGroupToken(string $selector, string $dotToken): void
    {
        $css = file_get_contents(__DIR__.'/../../../assets/styles/components.css');
        self::assertIsString($css);

        self::assertSame(1, preg_match('/^\.'.preg_quote($selector, '/').' \{(.*?)^\}/ms', $css, $rule), $selector.' rule not found');
        self::assertSame(1, preg_match('/^\s*--pill-dot:\s*var\(([^)]+)\);$/m', $rule[1], $dotMatch));
        self::assertSame($dotToken, $dotMatch[1]);
        self::assertMatchesRegularExpression('/^\.pill__dot \{[^}]*background:\s*var\(--pill-dot\);/ms', $css, 'the dot must use --pill-dot');
    }

    #[Test]
    public function aStatusPillFailsAtMountForAnUnknownGroup(): void
    {
        $this->assertFailsAtMount(StatusPill::class, ['group' => 'blocked'], 'Group', \ValueError::class);
    }

    #[Test]
    #[DataProvider('groupTokens')]
    public function theStylesheetGivesEachGroupItsFixedColours(string $selector, string $background, string $text): void
    {
        $css = file_get_contents(__DIR__.'/../../../assets/styles/components.css');
        self::assertIsString($css);

        self::assertSame(1, preg_match('/^\.'.preg_quote($selector, '/').' \{(.*?)^\}/ms', $css, $rule), $selector.' rule not found');
        self::assertSame(1, preg_match('/^\s*background:\s*var\(([^)]+)\);$/m', $rule[1], $backgroundMatch));
        self::assertSame(1, preg_match('/^\s*color:\s*var\(([^)]+)\);$/m', $rule[1], $colorMatch));
        self::assertSame($background, $backgroundMatch[1]);
        self::assertSame($text, $colorMatch[1]);
    }

    #[Test]
    public function theStylesheetHasExactlyTheFivePillRules(): void
    {
        $css = file_get_contents(__DIR__.'/../../../assets/styles/components.css');
        self::assertIsString($css);

        preg_match_all('/^\.(pill-[a-z]+) \{/m', $css, $matches);

        self::assertEqualsCanonicalizing(['pill-todo', 'pill-doing', 'pill-checking', 'pill-waiting', 'pill-done'], $matches[1]);
    }

    #[Test]
    public function aSwitchIsOffByDefaultAndNamed(): void
    {
        $html = (string) $this->renderTwigComponent(SwitchControl::class, ['label' => 'AI help']);

        self::assertMatchesRegularExpression('#^\s*<button class="sw" type="button" role="switch" aria-checked="false" aria-label="AI help"></button>\s*$#', $html);
    }

    #[Test]
    public function anOnSwitchSaysSo(): void
    {
        $html = (string) $this->renderTwigComponent(SwitchControl::class, ['label' => 'AI help', 'checked' => true]);

        self::assertStringContainsString('aria-checked="true"', $html);
    }

    #[Test]
    public function aSwitchWithoutALabelFailsAtMount(): void
    {
        $this->assertFailsAtMount(SwitchControl::class, [], 'needs a non-empty label');
    }

    #[Test]
    public function aSwitchWithABlankLabelFailsAtMount(): void
    {
        $this->assertFailsAtMount(SwitchControl::class, ['label' => '  '], 'needs a non-empty label');
    }

    #[Test]
    public function theSwitchIsUsedAsTwigSwitch(): void
    {
        $html = $this->renderTemplate('<twig:Switch label="Quiet hours" checked />', []);

        self::assertStringContainsString('role="switch" aria-checked="true" aria-label="Quiet hours"', $html);
    }
}
