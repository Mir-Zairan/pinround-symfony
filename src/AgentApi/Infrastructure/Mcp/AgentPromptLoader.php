<?php

declare(strict_types=1);

namespace App\AgentApi\Infrastructure\Mcp;

use App\AgentApi\Infrastructure\Prompt\AgentPrompt;
use Mcp\Capability\Registry\Loader\LoaderInterface;
use Mcp\Capability\RegistryInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Puts each AgentPrompt in the MCP server's registry, for prompts/list and the server's prompts capability. The bundle
 * tags it mcp.loader. The registry's handler only refuses: GatedPromptGetHandler answers every prompts/get before the
 * SDK's own handler could run it. Holds no state (ADR-0008).
 */
final readonly class AgentPromptLoader implements LoaderInterface
{
    /**
     * @param iterable<AgentPrompt> $prompts
     */
    public function __construct(
        #[AutowireIterator(AgentPrompt::TAG)]
        private iterable $prompts,
    ) {
    }

    /**
     * @throws \LogicException when two prompts share a name, as prompts/get could then reach only the first
     */
    public function load(RegistryInterface $registry): void
    {
        $loadedNames = [];
        foreach ($this->prompts as $prompt) {
            $definition = $prompt->definition();
            if (isset($loadedNames[$definition->name])) {
                throw new \LogicException(\sprintf('Two MCP prompts are named "%s".', $definition->name));
            }
            $loadedNames[$definition->name] = true;
            $registry->registerPrompt($definition, self::refuseUngatedGet(...));
        }
    }

    /**
     * @throws \LogicException always
     */
    public static function refuseUngatedGet(): never
    {
        throw new \LogicException('An MCP prompt was read without GatedPromptGetHandler, which must answer every prompts/get.');
    }
}
