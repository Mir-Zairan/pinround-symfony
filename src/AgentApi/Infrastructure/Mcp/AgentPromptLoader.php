<?php

declare(strict_types=1);

namespace App\AgentApi\Infrastructure\Mcp;

use App\AgentApi\Infrastructure\Prompt\ReportWhileInvestigatingPrompt;
use Mcp\Capability\Registry\Loader\LoaderInterface;
use Mcp\Capability\RegistryInterface;

/**
 * Puts the report_while_investigating prompt in the MCP server's registry, for prompts/list and the server's prompts
 * capability. The bundle tags it mcp.loader. The registry's handler only refuses: GatedPromptGetHandler answers every
 * prompts/get before the SDK's own handler could run it. Holds no state (ADR-0008).
 */
final readonly class AgentPromptLoader implements LoaderInterface
{
    public function __construct(private ReportWhileInvestigatingPrompt $reportWhileInvestigating)
    {
    }

    public function load(RegistryInterface $registry): void
    {
        $registry->registerPrompt($this->reportWhileInvestigating->definition(), self::refuseUngatedGet(...));
    }

    /**
     * @throws \LogicException always
     */
    public static function refuseUngatedGet(): never
    {
        throw new \LogicException('An MCP prompt was read without GatedPromptGetHandler, which must answer every prompts/get.');
    }
}
