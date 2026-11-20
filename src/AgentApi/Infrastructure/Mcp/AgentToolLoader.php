<?php

declare(strict_types=1);

namespace App\AgentApi\Infrastructure\Mcp;

use Mcp\Capability\Registry\Loader\LoaderInterface;
use Mcp\Capability\RegistryInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Puts each AgentTool's definition in the MCP server's registry, for tools/list: the mcpDefinition() of a
 * ScreenshotContextRequiredOverMcp tool, the definition() of any other. The bundle tags it mcp.loader. The
 * registry's handler for each tool only refuses: GatedToolCallHandler answers every tools/call before the SDK's own
 * handler could run it. Holds no state (ADR-0008).
 */
final readonly class AgentToolLoader implements LoaderInterface
{
    /**
     * @param iterable<AgentTool> $tools
     */
    public function __construct(
        #[AutowireIterator(AgentTool::TAG)]
        private iterable $tools,
    ) {
    }

    /**
     * @throws \LogicException when two tools share a name, as tools/call could then reach only the first
     */
    public function load(RegistryInterface $registry): void
    {
        $loadedNames = [];
        foreach ($this->tools as $tool) {
            $definition = $tool instanceof ScreenshotContextRequiredOverMcp ? $tool->mcpDefinition() : $tool->definition();
            if (isset($loadedNames[$definition->name])) {
                throw new \LogicException(\sprintf('Two MCP tools are named "%s".', $definition->name));
            }
            $loadedNames[$definition->name] = true;
            $registry->registerTool($definition, self::refuseUngatedCall(...));
        }
    }

    /**
     * @throws \LogicException always
     */
    public static function refuseUngatedCall(): never
    {
        throw new \LogicException('An MCP tool ran without ToolCallGate; GatedToolCallHandler must answer every tools/call.');
    }
}
