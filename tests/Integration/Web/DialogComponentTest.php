<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Web\UI\Component\Dialog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;
use Twig\Error\RuntimeError;

final class DialogComponentTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    #[Test]
    public function aDialogIsANativeDialogLabelledByItsHeading(): void
    {
        $crawler = $this->render(['id' => 'share-round', 'title' => 'Share this round?'], ['content' => '<p>Body text.</p>']);

        $dialog = $crawler->filter('dialog#share-round');
        self::assertCount(1, $dialog);
        self::assertSame('share-round-title', $dialog->attr('aria-labelledby'));
        self::assertSame('Share this round?', $crawler->filter('h2#share-round-title')->text());
        self::assertSame('Body text.', $dialog->filter('.dialog__body')->text());
    }

    #[Test]
    public function theCloseButtonHasAnAccessibleNameAndClosesThroughTheController(): void
    {
        $crawler = $this->render(['id' => 'share-round', 'title' => 'Share this round?']);

        $close = $crawler->filter('dialog button[aria-label="Close"]');
        self::assertCount(1, $close);
        self::assertSame('button', $close->attr('type'));
        self::assertSame('dialog#close', $close->attr('data-action'));
    }

    #[Test]
    public function theDialogIsWiredToTheDialogController(): void
    {
        $crawler = $this->render(['id' => 'share-round', 'title' => 'Share this round?']);

        self::assertCount(1, $crawler->filter('[data-controller="dialog"]'));
        self::assertCount(1, $crawler->filter('[data-controller="dialog"] > dialog[data-dialog-target="dialog"]'));
        self::assertSame('pointerdown->dialog#rememberPress pointerup->dialog#rememberRelease click->dialog#closeOnBackdrop', $crawler->filter('dialog')->attr('data-action'));
    }

    #[Test]
    public function theActionsAreRenderedOnlyWhenGiven(): void
    {
        $without = $this->render(['id' => 'a', 'title' => 'T']);
        $with = $this->render(['id' => 'a', 'title' => 'T'], ['actions' => '<button type="button">Confirm</button>']);

        self::assertCount(0, $without->filter('.dialog__actions'));
        self::assertSame('Confirm', $with->filter('.dialog__actions button')->text());
    }

    #[Test]
    public function theTriggerSitsInsideTheControllerBesideTheDialog(): void
    {
        $crawler = $this->render(['id' => 'a', 'title' => 'T'], ['trigger' => '<button type="button" data-action="dialog#open">Open</button>']);

        self::assertSame('Open', $crawler->filter('[data-controller="dialog"] > button[data-action="dialog#open"]')->text());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeIds(): iterable
    {
        yield 'empty' => [''];
        yield 'quote' => ['a"b'];
        yield 'space' => ['a b'];
        yield 'tag' => ['a><script'];
        yield 'starts with a digit' => ['1a'];
    }

    #[Test]
    #[DataProvider('unsafeIds')]
    public function anUnsafeIdFailsAtMount(string $id): void
    {
        $this->assertFailsAtMount(['id' => $id, 'title' => 'T'], 'needs an id');
    }

    #[Test]
    public function aMissingIdFailsAtMount(): void
    {
        $this->assertFailsAtMount(['title' => 'T'], 'needs an id');
    }

    #[Test]
    public function aBlankTitleFailsAtMount(): void
    {
        $this->assertFailsAtMount(['id' => 'a', 'title' => '  '], 'needs a non-empty title');
        $this->assertFailsAtMount(['id' => 'a'], 'needs a non-empty title');
    }

    /**
     * @param array<string, mixed>  $props
     * @param array<string, string> $blocks
     */
    private function render(array $props, array $blocks = []): Crawler
    {
        $content = $blocks['content'] ?? '';
        unset($blocks['content']);

        return new Crawler((string) $this->renderTwigComponent(Dialog::class, $props, content: $content, blocks: $blocks));
    }

    /**
     * @param array<string, mixed> $props
     */
    private function assertFailsAtMount(array $props, string $messagePart): void
    {
        try {
            $this->renderTwigComponent(Dialog::class, $props);
            self::fail('The component must not render.');
        } catch (RuntimeError $error) {
            self::assertInstanceOf(\InvalidArgumentException::class, $error->getPrevious());
            self::assertStringContainsString($messagePart, $error->getMessage());
        }
    }
}
