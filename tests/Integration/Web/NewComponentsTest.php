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
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;
use Twig\Error\RuntimeError;

final class NewComponentsTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private const array PEOPLE = ['Lena', 'Omar', 'Mia', 'Raj', 'Sara', 'Noor', 'Tom'];

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function initials(): iterable
    {
        yield 'one word' => ['Lena', 'L'];
        yield 'two words' => ['Omar Haddad', 'OH'];
        yield 'three words use the first two' => ['Mia Anne Lee', 'MA'];
        yield 'lowercase' => ['lena ross', 'LR'];
        yield 'multibyte' => ['Élodie Ünal', 'ÉÜ'];
        yield 'extra spaces' => ['  Omar   Haddad ', 'OH'];
    }

    #[Test]
    #[DataProvider('initials')]
    public function anAvatarShowsInitialsAndNamesThePerson(string $name, string $expected): void
    {
        $html = (string) $this->renderTwigComponent(Avatar::class, ['name' => $name]);

        self::assertMatchesRegularExpression('#^\s*<span class="av" role="img" aria-label="'.preg_quote($name, '#').'">'.$expected.'</span>\s*$#u', $html);
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
        self::assertStringContainsString('aria-label="3 more: Sara, Noor, Tom"', $html);
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
    public function aCardCanBeASection(): void
    {
        $html = (string) $this->renderTwigComponent(Card::class, ['tag' => 'section'], content: 'Inside');

        self::assertMatchesRegularExpression('#^\s*<section class="card">Inside</section>\s*$#', $html);
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

    /**
     * @param array<string, mixed> $variables
     */
    private function renderTemplate(string $source, array $variables = []): string
    {
        return self::getContainer()->get('twig')->createTemplate($source)->render($variables);
    }

    /**
     * @param class-string             $component
     * @param array<string, mixed>     $props
     * @param class-string<\Throwable> $cause
     */
    private function assertFailsAtMount(string $component, array $props, string $messagePart, string $cause = \InvalidArgumentException::class): void
    {
        try {
            $this->renderTwigComponent($component, $props, content: 'x');
            self::fail('The component must not render.');
        } catch (RuntimeError $error) {
            self::assertInstanceOf($cause, $error->getPrevious());
            self::assertStringContainsString($messagePart, $error->getMessage());
        }
    }
}
