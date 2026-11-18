<?php

declare(strict_types=1);

namespace App\Tests\Integration\Assist\Platform;

use App\Assist\Application\AiProvider;
use App\Assist\Infrastructure\Platform\FakeProvider;
use App\Assist\Infrastructure\Platform\SymfonyAiProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/**
 * Which adapter each environment binds to the AiProvider port. The service file is loaded into a new container per
 * environment, so no prod kernel (and no prod cache) is booted, and the real provider is never called.
 */
final class AiProviderWiringTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string, class-string<AiProvider>}>
     */
    public static function adapterPerEnvironment(): iterable
    {
        yield 'prod' => ['prod', SymfonyAiProvider::class];
        yield 'dev' => ['dev', SymfonyAiProvider::class];
        yield 'test' => ['test', FakeProvider::class];
    }

    /**
     * @param class-string<AiProvider> $adapter
     */
    #[Test]
    #[DataProvider('adapterPerEnvironment')]
    public function theServiceFileBindsTheRealProviderOutsideTheTestEnv(string $environment, string $adapter): void
    {
        $container = self::serviceFileFor($environment);

        self::assertTrue($container->hasDefinition(SymfonyAiProvider::class), 'the load must register the real provider');
        self::assertTrue($container->hasAlias(AiProvider::class));
        self::assertSame($adapter, (string) $container->getAlias(AiProvider::class));
    }

    #[Test]
    public function aProviderCallWaitsThirtySecondsWhenAiProviderTimeoutSecondsIsUnsetOrEmpty(): void
    {
        self::assertSame(30, self::serviceFileFor('prod')->getParameter('assist.ai_provider_timeout_default_seconds'));

        $autowired = new \ReflectionParameter([SymfonyAiProvider::class, '__construct'], 'timeoutSeconds')->getAttributes(Autowire::class);
        self::assertCount(1, $autowired);
        self::assertSame(
            '%env(int:default:assist.ai_provider_timeout_default_seconds:AI_PROVIDER_TIMEOUT_SECONDS)%',
            $autowired[0]->newInstance()->value,
        );
    }

    #[Test]
    public function theTestContainerBuildsTheRealProviderFromItsEnvWiring(): void
    {
        self::assertInstanceOf(SymfonyAiProvider::class, self::getContainer()->get('test.assist_symfony_ai_provider'));
    }

    private static function serviceFileFor(string $environment): ContainerBuilder
    {
        $container = new ContainerBuilder();
        new YamlFileLoader($container, new FileLocator(self::getContainer()->getParameter('kernel.project_dir').'/config'), $environment)->load('services.yaml');

        return $container;
    }
}
