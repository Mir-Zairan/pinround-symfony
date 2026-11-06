<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Routing\Loader\YamlFileLoader;
use Symfony\Component\Routing\RouterInterface;

/**
 * The gallery is a design-review page: its route exists in dev and test and nowhere else. The route files
 * are loaded per environment, so no prod kernel (and no prod cache) is booted.
 */
final class ComponentGalleryRouteTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function routeNamesPerEnvironment(): iterable
    {
        yield 'prod' => ['prod', []];
        yield 'dev' => ['dev', ['web_component_gallery']];
        yield 'test' => ['test', ['web_component_gallery']];
    }

    /**
     * @param list<string> $expectedRouteNames
     */
    #[Test]
    #[DataProvider('routeNamesPerEnvironment')]
    public function theGalleryRouteFileDeclaresTheRouteOnlyInDevAndTest(string $environment, array $expectedRouteNames): void
    {
        $loader = new YamlFileLoader(new FileLocator(self::getContainer()->getParameter('kernel.project_dir').'/config/routes'), $environment);

        self::assertEqualsCanonicalizing($expectedRouteNames, array_keys($loader->load('component_gallery.yaml')->all()));
    }

    #[Test]
    public function theTestKernelRoutesTheGallery(): void
    {
        $match = $this->router()->match('/_gallery');

        self::assertSame('web_component_gallery', $match['_route']);
    }

    #[Test]
    public function theAttributeImportOfAllControllersDoesNotPickTheGalleryUp(): void
    {
        $loader = self::getContainer()->get('routing.loader');

        $routes = $loader->load(self::getContainer()->getParameter('kernel.project_dir').'/config/routes.yaml', 'yaml');

        self::assertNotNull($routes->get('web_home'), 'the import must find the other controllers');
        self::assertNull($routes->get('web_component_gallery'));
        foreach ($routes->all() as $route) {
            self::assertNotSame('/_gallery', $route->getPath());
        }
    }

    private function router(): RouterInterface
    {
        $router = self::getContainer()->get('router');

        return $router;
    }
}
