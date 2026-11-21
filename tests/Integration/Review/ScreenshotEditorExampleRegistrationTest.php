<?php

declare(strict_types=1);

namespace App\Tests\Integration\Review;

use App\Review\UI\Component\ScreenshotEditor;
use App\Review\UI\Component\ScreenshotEditorExample;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * The gallery's screenshot editor example is design-review data: it is a service in dev and test and not in prod. The
 * service file is loaded into a new container per environment, so no prod kernel (and no prod cache) is booted.
 */
final class ScreenshotEditorExampleRegistrationTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function registrationPerEnvironment(): iterable
    {
        yield 'prod' => ['prod', false];
        yield 'dev' => ['dev', true];
        yield 'test' => ['test', true];
    }

    #[Test]
    #[DataProvider('registrationPerEnvironment')]
    public function theServiceFileRegistersTheExampleOnlyInDevAndTest(string $environment, bool $isRegistered): void
    {
        $container = new ContainerBuilder();
        $loader = new YamlFileLoader($container, new FileLocator(self::getContainer()->getParameter('kernel.project_dir').'/config'), $environment);

        $loader->load('services.yaml');

        self::assertTrue(self::isRegistered($container, ScreenshotEditor::class), 'the load must register the real components');
        self::assertSame($isRegistered, self::isRegistered($container, ScreenshotEditorExample::class));
    }

    #[Test]
    public function theTestKernelRendersTheExampleAsATwigComponent(): void
    {
        $factory = self::getContainer()->get('ux.twig_component.component_factory');

        self::assertSame(ScreenshotEditorExample::class, $factory->metadataFor('ScreenshotEditorExample')->getClass());
    }

    #[Test]
    public function theExampleShowsThreeDraftScreenshotsWithTheirPinsInsideAForm(): void
    {
        $page = new Crawler((string) $this->renderTwigComponent('ScreenshotEditorExample'));

        $editor = $page->filter('form#gallery-screenshot-editor-form > #gallery-screenshot-editor[data-controller="screenshot-editor"]');
        self::assertCount(1, $editor);
        self::assertSame(['Screenshot 1 of 3', 'Screenshot 2 of 3', 'Screenshot 3 of 3'], $editor->filter('button[role="tab"]')->each(static fn (Crawler $tab): string => $tab->text()));
        self::assertSame(['2', '1', '0'], $editor->filter('[role="tabpanel"]')->each(static fn (Crawler $panel): string => (string) $panel->filter('textarea.pin-list__note')->count()));
        self::assertSame(['screenshots[0][remove]', 'screenshots[1][remove]', 'screenshots[2][remove]'], $editor->filter('input[type="hidden"][disabled]')->each(static fn (Crawler $field): string => (string) $field->attr('name')));
        self::assertSame('Checkout page', $editor->filter('input[name="screenshots[0][title]"]')->attr('value'));
        self::assertStringStartsWith('data:image/svg+xml;base64,', (string) $editor->filter('button[role="tab"] img')->eq(2)->attr('src'));
    }

    /**
     * A class that #[WhenNot] leaves out still gets a definition, abstract and tagged container.excluded, which the
     * container removes when it compiles.
     */
    private static function isRegistered(ContainerBuilder $container, string $class): bool
    {
        return $container->hasDefinition($class) && !$container->getDefinition($class)->hasTag('container.excluded');
    }
}
