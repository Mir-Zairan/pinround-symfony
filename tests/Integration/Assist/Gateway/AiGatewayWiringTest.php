<?php

declare(strict_types=1);

namespace App\Tests\Integration\Assist\Gateway;

use App\Assist\Application\AiAnswerCheck;
use App\Assist\Application\AiGateway;
use App\Assist\Application\AiProvider;
use App\Assist\Application\Gateway\AskedTicketQueries;
use App\Assist\Application\Gateway\GuardedAiGateway;
use App\Assist\Application\Gateway\TicketPageAskedTicketQueries;
use App\Assist\Application\PromptRenderer;
use App\Assist\Infrastructure\Platform\FakeProvider;
use App\Assist\Infrastructure\Platform\SymfonyAiProvider;
use App\Assist\Infrastructure\Prompt\OutputValidator;
use App\Assist\Infrastructure\Prompt\PromptBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/**
 * What each environment binds to the gateway and the ports it takes. The service file is loaded into a new container per
 * environment, so no prod kernel is booted and no real provider is called; the test container shows which provider the
 * gateway it builds was given.
 */
final class AiGatewayWiringTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string, class-string<AiProvider>}>
     */
    public static function environments(): iterable
    {
        yield 'prod' => ['prod', SymfonyAiProvider::class];
        yield 'dev' => ['dev', SymfonyAiProvider::class];
        yield 'test' => ['test', FakeProvider::class];
    }

    /**
     * @param class-string<AiProvider> $provider
     */
    #[Test]
    #[DataProvider('environments')]
    public function theGatewayAndItsPortsResolveToTheRealServicesAndOnlyTheProviderDiffersInTest(string $environment, string $provider): void
    {
        $container = new ContainerBuilder();
        new YamlFileLoader($container, new FileLocator(self::getContainer()->getParameter('kernel.project_dir').'/config'), $environment)->load('services.yaml');

        $boundServices = [];
        foreach ([AiGateway::class, AiAnswerCheck::class, PromptRenderer::class, AskedTicketQueries::class, AiProvider::class] as $port) {
            self::assertTrue($container->hasAlias($port), \sprintf('%s is not bound in %s.', $port, $environment));
            $boundServices[$port] = (string) $container->getAlias($port);
        }

        self::assertSame([
            AiGateway::class => GuardedAiGateway::class,
            AiAnswerCheck::class => OutputValidator::class,
            PromptRenderer::class => PromptBuilder::class,
            AskedTicketQueries::class => TicketPageAskedTicketQueries::class,
            AiProvider::class => $provider,
        ], $boundServices);
    }

    #[Test]
    public function theTestContainersGatewayAsksTheFakeProvider(): void
    {
        $gateway = self::getContainer()->get('test.assist_ai_gateway');
        self::assertInstanceOf(GuardedAiGateway::class, $gateway);

        self::assertSame(self::getContainer()->get(FakeProvider::class), new \ReflectionProperty(GuardedAiGateway::class, 'aiProvider')->getValue($gateway));
    }
}
