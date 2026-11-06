<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\RouterInterface;

final class ComponentGalleryRouteTest extends KernelTestCase
{
    #[Test]
    public function theGalleryRouteExistsInTestEnv(): void
    {
        self::bootKernel(['environment' => 'test']);

        $match = $this->router()->match('/_gallery');

        self::assertSame('web_component_gallery', $match['_route']);
    }

    #[Test]
    public function theGalleryRouteDoesNotExistInProd(): void
    {
        // A fresh prod kernel: the test container is never reused for this check.
        self::bootKernel(['environment' => 'prod', 'debug' => false]);

        $router = $this->router();

        self::assertNull($router->getRouteCollection()->get('web_component_gallery'));
        self::assertNotNull($router->getRouteCollection()->get('web_home'));
        $this->expectException(ResourceNotFoundException::class);
        $router->match('/_gallery');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        self::ensureKernelShutdown();
    }

    private function router(): RouterInterface
    {
        // The prod kernel has no test.service_container, so read the public router from the kernel.
        $router = self::$kernel?->getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        return $router;
    }
}
