<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Web\UI\Component\Avatar;
use App\Web\UI\Component\AvatarStack;
use App\Web\UI\Component\Card;
use App\Web\UI\Component\EmptyState;
use App\Web\UI\Component\Toast;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AvatarCardEmptyStateToastComponentsTest extends KernelTestCase
{
    use RendersTwigComponents;

    private const array PEOPLE = ['Lena', 'Omar', 'Mia', 'Raj', 'Sara', 'Noor', 'Lena Noor'];

    /**
     * @return iterable<string, array{string}>
     */
    public static function cardElements(): iterable
    {
        yield 'div' => ['div'];
        yield 'section' => ['section'];
        yield 'article' => ['article'];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function initials(): iterable
    {
        yield 'one word' => ['Lena', 'L'];
        yield 'two words' => ['Omar Raj', 'OR'];
        yield 'three words use the first two' => ['Mia Anne Lee', 'MA'];
        yield 'lowercase' => ['lena ross', 'LR'];
        yield 'multibyte' => ['Élodie Ünal', 'ÉÜ'];
        yield 'extra spaces' => ['  Omar   Raj ', 'OR'];
    }

    #[Test]
    #[DataProvider('initials')]
    public function anAvatarShowsInitialsAndNamesThePerson(string $name, string $expected): void
    {
        $html = (string) $this->renderTwigComponent(Avatar::class, ['name' => $name]);

        self::assertMatchesRegularExpression('#^\s*<span class="av" role="img" aria-label="'.preg_quote($name, '#').'">'.$expected.'</span>\s*$#u', $html);
    }

    #[Test]
    public function aMediumAvatarHasNoSizeClass(): void
    {
        $html = (string) $this->renderTwigComponent(Avatar::class, ['name' => 'Lena', 'size' => 'md']);

        self::assertStringContainsString('class="av"', $html);
        self::assertStringNotContainsString('av-lg', $html);
    }

    #[Test]
    public function aLargeAvatarAddsItsClass(): void
    {
        $html = (string) $this->renderTwigComponent(Avatar::class, ['name' => 'Lena', 'size' => 'lg']);

        self::assertStringContainsString('class="av av-lg"', $html);
    }

    #[Test]
    public function anAvatarWithAnUnknownSizeFailsAtMount(): void
    {
        $this->assertFailsAtMount(Avatar::class, ['name' => 'Lena', 'size' => 'xl'], 'AvatarSize', \ValueError::class);
    }

    #[Test]
    public function anAvatarWithoutANameFailsAtMount(): void
    {
        $this->assertFailsAtMount(Avatar::class, [], 'needs a non-empty name');
        $this->assertFailsAtMount(Avatar::class, ['name' => '  '], 'needs a non-empty name');
    }

    #[Test]
    public function aStackOfSevenShowsFourAvatarsAndPlusThree(): void
    {
        $html = (string) $this->renderTwigComponent(AvatarStack::class, ['names' => self::PEOPLE]);

        self::assertSame(4, substr_count($html, 'class="av"'));
        self::assertSame(1, substr_count($html, 'av-more'));
        self::assertStringContainsString('>+3</span>', $html);
        self::assertStringContainsString('aria-label="3 more: Sara, Noor, Lena Noor"', $html);
        self::assertStringNotContainsString('aria-label="Sara"', $html);
    }

    #[Test]
    public function aStackOfFourHasNoPlusItem(): void
    {
        $html = (string) $this->renderTwigComponent(AvatarStack::class, ['names' => \array_slice(self::PEOPLE, 0, 4)]);

        self::assertSame(4, substr_count($html, 'class="av"'));
        self::assertStringNotContainsString('av-more', $html);
        self::assertStringNotContainsString('+', $html);
    }

    #[Test]
    public function aStackOfFiveShowsPlusOne(): void
    {
        $html = (string) $this->renderTwigComponent(AvatarStack::class, ['names' => \array_slice(self::PEOPLE, 0, 5)]);

        self::assertSame(4, substr_count($html, 'class="av"'));
        self::assertStringContainsString('>+1</span>', $html);
    }

    #[Test]
    public function aStackOfOneShowsOneAvatar(): void
    {
        $html = (string) $this->renderTwigComponent(AvatarStack::class, ['names' => ['Lena']]);

        self::assertSame(1, substr_count($html, 'class="av"'));
        self::assertStringContainsString('class="stack"', $html);
    }

    #[Test]
    public function anEmptyStackRendersNothing(): void
    {
        $html = (string) $this->renderTwigComponent(AvatarStack::class, ['names' => []]);

        self::assertSame('', trim($html));
    }

    #[Test]
    public function aCardRendersItsClassAndContent(): void
    {
        $html = (string) $this->renderTwigComponent(Card::class, ['data-id' => 'x'], content: 'Inside');

        self::assertMatchesRegularExpression('#^\s*<div class="card" data-id="x">Inside</div>\s*$#', $html);
    }

    #[Test]
    #[DataProvider('cardElements')]
    public function aCardCanBeAnyAllowedElement(string $tag): void
    {
        $html = (string) $this->renderTwigComponent(Card::class, ['tag' => $tag], content: 'Inside');

        self::assertMatchesRegularExpression('#^\s*<'.$tag.' class="card">Inside</'.$tag.'>\s*$#', $html);
    }

    #[Test]
    public function aCardWithAnElementOutsideTheWhitelistFailsAtMount(): void
    {
        $this->assertFailsAtMount(Card::class, ['tag' => 'script'], 'must be one of');
    }

    #[Test]
    public function anEmptyStateEscapesItsMessageAndHasNoActionByDefault(): void
    {
        $html = (string) $this->renderTwigComponent(EmptyState::class, ['message' => '<b>Nothing</b>']);

        self::assertStringContainsString('<p>&lt;b&gt;Nothing&lt;/b&gt;</p>', $html);
        self::assertStringNotContainsString('empty-state__action', $html);
    }

    #[Test]
    public function anEmptyStateRendersItsActionBlock(): void
    {
        $html = $this->renderTemplate('<twig:EmptyState message="No rounds yet."><twig:block name="action"><a href="/x">Start</a></twig:block></twig:EmptyState>');

        self::assertStringContainsString('<div class="empty-state__action"><a href="/x">Start</a></div>', $html);
    }

    #[Test]
    public function anEmptyStateWithoutAMessageFailsAtMount(): void
    {
        $this->assertFailsAtMount(EmptyState::class, [], 'needs a non-empty message');
    }

    #[Test]
    public function aToastRendersEscapedContentWithoutTimerOrLiveRegion(): void
    {
        $html = $this->renderTemplate('<twig:Toast>{{ text }}</twig:Toast>', ['text' => '<b>Saved</b>']);

        self::assertMatchesRegularExpression('#^\s*<div class="toast">&lt;b&gt;Saved&lt;/b&gt;</div>\s*$#', $html);
        self::assertStringNotContainsString('data-', $html);
        self::assertStringNotContainsString('aria-live', $html);
        self::assertStringNotContainsString('role=', $html);
        self::assertStringContainsString('class="toast"', (string) $this->renderTwigComponent(Toast::class, [], content: 'x'));
    }
}
