<?php

declare(strict_types=1);

namespace App\Tests\Integration\Review;

use App\Review\UI\Component\EditableScreenshot;
use App\Review\UI\Component\ScreenshotExample;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/**
 * The gallery's screenshot example is design-review data: it is a service in dev and test and not in prod. The
 * service file is loaded into a new container per environment, so no prod kernel (and no prod cache) is booted.
 */
final class ScreenshotExampleRegistrationTest extends KernelTestCase
{
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

        self::assertTrue(self::isRegistered($container, EditableScreenshot::class), 'the load must register the real components');
        self::assertSame($isRegistered, self::isRegistered($container, ScreenshotExample::class));
    }

    #[Test]
    public function theTestKernelRendersTheExampleAsATwigComponent(): void
    {
        $factory = self::getContainer()->get('ux.twig_component.component_factory');

        self::assertSame(ScreenshotExample::class, $factory->metadataFor('ScreenshotExample')->getClass());
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
