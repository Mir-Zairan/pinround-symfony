<?php

declare(strict_types=1);

namespace App\AgentApi\Infrastructure\Mcp;

use Mcp\Schema\Tool;

/**
 * A tool whose MCP calls must give each picture a title and a summary (P14.T11), while /api/v1, which the browser
 * extension calls, keeps both optional as the web form does. AgentToolLoader lists mcpDefinition() in tools/list, and
 * ToolCallGate::callOverMcp() runs refuseMissingScreenshotContext() and then checks mcpDefinition()'s input schema;
 * ToolCallGate::call(), for /api/v1, checks definition()'s.
 */
interface ScreenshotContextRequiredOverMcp extends AgentTool
{
    /**
     * definition() with each picture's title and summary required, at least one character each, and a description
     * that says so.
     */
    public function mcpDefinition(): Tool;

    /**
     * @param array<string, mixed> $arguments what the client sent, before the input schema is checked
     *
     * @throws ToolRefusal invalid input naming the first picture title or summary that is missing, empty or blank
     */
    public function refuseMissingScreenshotContext(array $arguments): void;
}
