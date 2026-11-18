<?php

declare(strict_types=1);

namespace App\Tests\Integration\AgentApi;

use App\AgentApi\Infrastructure\Mcp\AgentTool;
use App\AgentApi\Infrastructure\Mcp\Tool\WhoAmI;
use App\Kernel;
use App\Tests\Fixtures\AgentApi\WriteProbeTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Which services each environment tags as MCP tools, read from a container compiled here from the config files, never
 * from a cached one. WriteProbeTool writes and then fails on request, so it must be a tool in the test env only: in
 * dev or prod any token with the read scope could call it.
 */
final class AgentToolServicesTest extends TestCase
{
    /**
     * Each environment with the debug mode it runs in, and the tool services it must have.
     *
     * @return iterable<string, array{string, bool, list<string>}>
     */
    public static function environments(): iterable
    {
        yield 'dev' => ['dev', true, [WhoAmI::class]];
        yield 'prod' => ['prod', false, [WhoAmI::class]];
        yield 'test' => ['test', true, [WhoAmI::class, WriteProbeTool::class]];
    }

    /**
     * @param list<string> $expectedToolServices
     */
    #[Test]
    #[DataProvider('environments')]
    public function onlyTheTestEnvHasTheWriteProbeToolBesideTheRealTools(string $environment, bool $isDebug, array $expectedToolServices): void
    {
        $toolServices = new class implements CompilerPassInterface {
            /** @var list<string>|null */
            public ?array $taggedIds = null;
            public ?bool $hasWriteProbeDefinition = null;

            public function process(ContainerBuilder $container): void
            {
                $this->taggedIds = array_keys($container->findTaggedServiceIds(AgentTool::TAG));
                $this->hasWriteProbeDefinition = $container->hasDefinition(WriteProbeTool::class);
            }
        };

        $container = self::freshContainerBuilder($environment, $isDebug);
        // Before the removing passes: by then every definition and tag of the environment's config is in place.
        $container->addCompilerPass($toolServices, PassConfig::TYPE_BEFORE_REMOVING);
        $container->compile();

        self::assertNotNull($toolServices->taggedIds, 'The recording pass never ran.');
        self::assertEqualsCanonicalizing($expectedToolServices, $toolServices->taggedIds);
        self::assertSame(\in_array(WriteProbeTool::class, $expectedToolServices, true), $toolServices->hasWriteProbeDefinition);
    }

    /**
     * The kernel's own container builder with the environment's bundles and config loaded, not yet compiled. The
     * kernel builds it this way before it dumps a container; no file under var/cache is read.
     */
    private static function freshContainerBuilder(string $environment, bool $isDebug): ContainerBuilder
    {
        $kernel = new Kernel($environment, $isDebug);
        new \ReflectionMethod($kernel, 'initializeBundles')->invoke($kernel);
        $container = new \ReflectionMethod($kernel, 'buildContainer')->invoke($kernel);
        self::assertInstanceOf(ContainerBuilder::class, $container);

        return $container;
    }
}
