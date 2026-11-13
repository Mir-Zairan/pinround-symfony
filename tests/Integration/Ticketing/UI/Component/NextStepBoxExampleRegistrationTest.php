<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ticketing\UI\Component;

use App\Ticketing\UI\Component\NextStepBox;
use App\Ticketing\UI\Component\NextStepBoxExample;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/**
 * The gallery's next step box example is design-review data: it is a service in dev and test and not in prod. The
 * service file is loaded into a new container per environment, so no prod kernel (and no prod cache) is booted.
 */
final class NextStepBoxExampleRegistrationTest extends KernelTestCase
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

        self::assertTrue(self::isRegistered($container, NextStepBox::class), 'the load must register the other components');
        self::assertSame($isRegistered, self::isRegistered($container, NextStepBoxExample::class));
    }

    #[Test]
    public function theTestKernelRendersTheExampleAsATwigComponent(): void
    {
        $factory = self::getContainer()->get('ux.twig_component.component_factory');

        self::assertSame(NextStepBoxExample::class, $factory->metadataFor('NextStepBoxExample')->getClass());
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
