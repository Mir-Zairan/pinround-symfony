<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Web\UI\Component\Button;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;
use Twig\Error\RuntimeError;

final class ButtonComponentTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    /**
     * @return iterable<string, array{string}>
     */
    public static function variants(): iterable
    {
        foreach (['filled', 'tonal', 'outline', 'text'] as $variant) {
            yield $variant => [$variant];
        }
    }

    #[Test]
    public function byDefaultItIsAFilledMediumButtonOfTypeButton(): void
    {
        $html = (string) $this->renderTwigComponent(Button::class, [], content: 'Start');

        self::assertMatchesRegularExpression('#^\s*<button class="btn btn-filled" type="button">Start</button>\s*$#', $html);
    }

    #[Test]
    #[DataProvider('variants')]
    public function eachVariantRendersItsClass(string $variant): void
    {
        $html = (string) $this->renderTwigComponent(Button::class, ['variant' => $variant], content: 'Start');

        self::assertStringContainsString('class="btn btn-'.$variant.'"', $html);
        self::assertStringNotContainsString('btn-sm', $html);
    }

    #[Test]
    public function aSmallButtonAddsTheSmallClass(): void
    {
        $html = (string) $this->renderTwigComponent(Button::class, ['variant' => 'outline', 'size' => 'sm'], content: 'Start');

        self::assertStringContainsString('class="btn btn-outline btn-sm"', $html);
    }

    #[Test]
    public function aMediumSizeStringAddsNoSmallClass(): void
    {
        $html = (string) $this->renderTwigComponent(Button::class, ['size' => 'md'], content: 'Start');

        self::assertStringNotContainsString('btn-sm', $html);
    }

    #[Test]
    public function anHrefRendersALink(): void
    {
        $html = (string) $this->renderTwigComponent(Button::class, ['href' => '/start'], content: 'Start');

        self::assertStringContainsString('<a class="btn btn-filled" href="/start">Start</a>', $html);
        self::assertStringNotContainsString('<button', $html);
    }

    #[Test]
    public function aDisabledButtonRendersDisabled(): void
    {
        $html = (string) $this->renderTwigComponent(Button::class, ['disabled' => true], content: 'Save');

        self::assertStringContainsString(' disabled>', $html);
    }

    #[Test]
    public function anEnabledButtonDoesNotRenderDisabled(): void
    {
        $html = (string) $this->renderTwigComponent(Button::class, [], content: 'Save');

        self::assertStringNotContainsString('disabled', $html);
    }

    #[Test]
    public function aDisabledLinkIsMarkedAndRemovedFromTheTabOrder(): void
    {
        $html = (string) $this->renderTwigComponent(Button::class, ['href' => '/x', 'disabled' => true], content: 'Save');

        self::assertStringContainsString('aria-disabled="true" tabindex="-1"', $html);
    }

    #[Test]
    public function theSubmitTypeIsRendered(): void
    {
        $html = (string) $this->renderTwigComponent(Button::class, ['type' => 'submit'], content: 'Save');

        self::assertStringContainsString('type="submit"', $html);
    }

    #[Test]
    public function extraAttributesPassThrough(): void
    {
        $html = (string) $this->renderTwigComponent(Button::class, ['aria-label' => 'Close', 'data-x' => 'y'], content: 'x');

        self::assertStringContainsString('aria-label="Close"', $html);
        self::assertStringContainsString('data-x="y"', $html);
    }

    #[Test]
    public function anUnknownVariantFailsAtMount(): void
    {
        $this->expectException(RuntimeError::class);

        $this->renderTwigComponent(Button::class, ['variant' => 'neon'], content: 'x');
    }

    #[Test]
    public function anUnknownSizeFailsAtMount(): void
    {
        $this->expectException(RuntimeError::class);

        $this->renderTwigComponent(Button::class, ['size' => 'xl'], content: 'x');
    }
}
