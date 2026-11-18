<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\AgentApi;

use App\AgentApi\Infrastructure\Mcp\AgentTool;
use App\AgentApi\Infrastructure\Mcp\ToolTarget;
use App\Identity\Api\CurrentApiToken;
use App\Organization\Api\OrganizationPermission;
use Doctrine\DBAL\Connection;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool;

/**
 * A test-only MCP tool (registered under when@test in config/services.yaml) that writes its label to zz_tenant_probe
 * in the token's organization and then fails when told to, so McpEndpointTest sees what a failed call keeps. Its
 * target is the token's organization, behind the voter's ORG_VIEW.
 */
final readonly class WriteProbeTool implements AgentTool
{
    public const string NAME = 'test_write_probe';

    public function __construct(private CurrentApiToken $currentApiToken, private Connection $connection)
    {
    }

    public function definition(): Tool
    {
        return new Tool(
            name: self::NAME,
            title: null,
            inputSchema: [
                'type' => 'object',
                'properties' => ['label' => ['type' => 'string', 'pattern' => '^[a-z-]{1,40}$'], 'fail' => ['type' => 'boolean']],
                'required' => ['label', 'fail'],
                'additionalProperties' => false,
            ],
            description: 'A tool of the tests: writes a label, then fails when told to.',
            annotations: null,
        );
    }

    public function requiredScope(): string
    {
        return 'read';
    }

    public function targetOf(array $arguments): ToolTarget
    {
        $organizationId = $this->currentApiToken->access()?->organizationId ?? throw new \LogicException('The write probe ran without a token.');

        return new ToolTarget(OrganizationPermission::VIEW, $organizationId, 'organization', $organizationId->toString());
    }

    /**
     * @throws \RuntimeException after the write, when the arguments say "fail"
     */
    public function call(array $arguments, ?ToolTarget $target): CallToolResult
    {
        if (null === $target || !\is_string($arguments['label'] ?? null)) {
            throw new \LogicException('The write probe ran without its target or label.');
        }
        $this->connection->executeStatement('INSERT INTO zz_tenant_probe (organization_id, label) VALUES (?, ?)', [$target->auditId, $arguments['label']]);
        if (true === ($arguments['fail'] ?? null)) {
            throw new \RuntimeException('The write probe failed after its write.');
        }

        return new CallToolResult([new TextContent('written')]);
    }
}
