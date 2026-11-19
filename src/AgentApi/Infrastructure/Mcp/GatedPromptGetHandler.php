<?php

declare(strict_types=1);

namespace App\AgentApi\Infrastructure\Mcp;

use App\AgentApi\Infrastructure\Prompt\AgentPrompt;
use App\Identity\Api\CurrentApiToken;
use App\Identity\Api\CurrentUser;
use Mcp\Schema\Content\PromptMessage;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\Role;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\GetPromptRequest;
use Mcp\Schema\Result\GetPromptResult;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Answers every prompts/get of the MCP server, as GatedToolCallHandler answers every tools/call: the bundle tags it
 * mcp.request_handler, and the SDK asks the tagged handlers before its own GetPromptHandler, which so never runs. A
 * request reaches it only past the api_token firewall, ToolCallLimiter and McpSessionBinding, like a tool call; any
 * valid token may read any AgentPrompt, which holds no data of the organization.
 *
 * The answer is one user message whose text is the named prompt's file, unchanged. An unknown name gets an
 * invalid-params error and no audit row; every other request one row with the prompt's name and the outcome, never its
 * text. The prompt is answered only once its "ok" row is written. Holds no state (ADR-0008).
 *
 * @implements RequestHandlerInterface<GetPromptResult>
 */
final readonly class GatedPromptGetHandler implements RequestHandlerInterface
{
    public const string UNKNOWN_PROMPT = 'There is no prompt of that name. Call prompts/list to see the prompts.';
    public const string TAKES_NO_ARGUMENTS = 'This prompt takes no arguments.';
    public const string PROMPT_FAILED = 'The prompt could not be read. Try again later.';

    /**
     * @param iterable<AgentPrompt> $prompts AgentPromptLoader refuses two of one name, so a name finds one prompt
     */
    public function __construct(
        #[AutowireIterator(AgentPrompt::TAG)]
        private iterable $prompts,
        private CurrentApiToken $currentApiToken,
        private CurrentUser $currentUser,
        private ToolCallAuditor $toolCallAuditor,
        private LoggerInterface $logger,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request instanceof GetPromptRequest;
    }

    /**
     * @return Response<GetPromptResult>|Error
     *
     * @throws \LogicException when given a request supports() turned down, or one without a token or a user, which the
     *                         api_token firewall never lets through; nothing is read and no row is written
     * @throws \Throwable      what ToolCallAuditor throws; the prompt is then not answered
     */
    public function handle(Request $request, SessionInterface $session): Response|Error
    {
        if (!$request instanceof GetPromptRequest) {
            throw new \LogicException(\sprintf('%s answers prompts/get only.', self::class));
        }
        $prompt = $this->promptNamed($request->name);
        if (null === $prompt) {
            return Error::forInvalidParams(self::UNKNOWN_PROMPT, $request->getId());
        }
        $access = $this->currentApiToken->access()
            ?? throw new \LogicException('An MCP prompt request passed the api_token firewall without a personal access token.');
        $userId = $this->currentUser->id()
            ?? throw new \LogicException('An MCP prompt request passed the api_token firewall without a user.');
        $definition = $prompt->definition();
        $promptName = $definition->name;

        if ([] !== ($request->arguments ?? [])) {
            $this->toolCallAuditor->recordPromptRead($access, $userId, $promptName, ToolCallOutcome::InvalidInput);

            return Error::forInvalidParams(self::TAKES_NO_ARGUMENTS, $request->getId());
        }

        try {
            $text = $prompt->text();
        } catch (\Throwable $failure) {
            $this->logger->error('An MCP prompt could not be read.', ['exception_class' => $failure::class]);
            $this->toolCallAuditor->recordPromptRead($access, $userId, $promptName, ToolCallOutcome::Error);

            return Error::forInternalError(self::PROMPT_FAILED, $request->getId());
        }
        $this->toolCallAuditor->recordPromptRead($access, $userId, $promptName, ToolCallOutcome::Ok);

        return new Response($request->getId(), new GetPromptResult(
            [new PromptMessage(Role::User, new TextContent($text))],
            $definition->description,
        ));
    }

    private function promptNamed(string $name): ?AgentPrompt
    {
        foreach ($this->prompts as $prompt) {
            if ($prompt->definition()->name === $name) {
                return $prompt;
            }
        }

        return null;
    }
}
