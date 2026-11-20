<?php

declare(strict_types=1);

namespace App\AgentApi\Infrastructure\Mcp;

use Mcp\Schema\Content\TextContent;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Answers every tools/call of the MCP server. The bundle tags it mcp.request_handler, and the SDK asks the tagged
 * handlers before its own CallToolHandler, which so never runs: no tool runs without ToolCallGate. An unknown tool
 * name gets an invalid-params error and no audit row; a refusal gets its message, and any other failure a generic one
 * while only its class is logged. Never logs a call's input or output. Holds no state (ADR-0008).
 *
 * @implements RequestHandlerInterface<CallToolResult>
 */
final readonly class GatedToolCallHandler implements RequestHandlerInterface
{
    public const string UNKNOWN_TOOL = 'There is no tool of that name. Call tools/list to see the tools.';
    public const string TOOL_FAILED = 'The tool failed. Try again later.';

    /**
     * @param iterable<AgentTool> $tools
     */
    public function __construct(
        #[AutowireIterator(AgentTool::TAG)]
        private iterable $tools,
        private ToolCallGate $toolCallGate,
        private LoggerInterface $logger,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request instanceof CallToolRequest;
    }

    /**
     * @return Response<CallToolResult>|Error
     *
     * @throws \LogicException when given a request supports() turned down
     */
    public function handle(Request $request, SessionInterface $session): Response|Error
    {
        if (!$request instanceof CallToolRequest) {
            throw new \LogicException(\sprintf('%s answers tools/call only.', self::class));
        }
        $tool = $this->toolNamed($request->name);
        if (null === $tool) {
            return Error::forInvalidParams(self::UNKNOWN_TOOL, $request->getId());
        }

        try {
            return new Response($request->getId(), $this->toolCallGate->callOverMcp($tool, $request->arguments));
        } catch (ToolRefusal $refusal) {
            if (ToolCallOutcome::InvalidInput === $refusal->outcome) {
                return Error::forInvalidParams($refusal->getMessage(), $request->getId());
            }

            return new Response($request->getId(), CallToolResult::error([new TextContent($refusal->getMessage())]));
        } catch (\Throwable $failure) {
            $this->logger->error('An MCP tool call failed.', ['mcp_tool' => $request->name, 'exception_class' => $failure::class]);

            return new Response($request->getId(), CallToolResult::error([new TextContent(self::TOOL_FAILED)]));
        }
    }

    private function toolNamed(string $name): ?AgentTool
    {
        foreach ($this->tools as $tool) {
            if ($tool->definition()->name === $name) {
                return $tool;
            }
        }

        return null;
    }
}
