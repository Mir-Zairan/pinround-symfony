<?php

declare(strict_types=1);

namespace App\AgentApi\Infrastructure\Mcp;

use App\Identity\Api\ApiTokenAccess;
use App\Identity\Api\CurrentApiToken;
use App\Identity\Api\CurrentUser;
use App\SharedKernel\Domain\UserId;
use Mcp\Capability\Discovery\SchemaValidator;
use Mcp\Schema\Result\CallToolResult;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * The one way an MCP tool runs (ADR-0021): the token's scope, the input schema, the tool's target, the voter the web UI
 * runs for the same action (a denial reads as not found, ADR-0013), the tool, and one audit row for every call that
 * got here, refused or not. Holds no state, so it is safe in worker mode (ADR-0008).
 */
final readonly class ToolCallGate
{
    private const int MAX_LISTED_INPUT_PROBLEMS = 3;

    public function __construct(
        private CurrentApiToken $currentApiToken,
        private CurrentUser $currentUser,
        private AuthorizationCheckerInterface $authorizationChecker,
        private ToolCallAuditor $toolCallAuditor,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $arguments what the client sent, unchecked
     *
     * @throws ToolRefusal     after its audit row is written
     * @throws \LogicException when the request has no token or no user, which the api_token firewall never lets
     *                         through; no row is written
     * @throws \Throwable      any other failure, after an "error" row was tried
     */
    public function call(AgentTool $tool, array $arguments): CallToolResult
    {
        $access = $this->currentApiToken->access()
            ?? throw new \LogicException('An MCP tool call passed the api_token firewall without a personal access token.');
        $userId = $this->currentUser->id()
            ?? throw new \LogicException('An MCP tool call passed the api_token firewall without a user.');
        $definition = $tool->definition();
        $target = null;

        try {
            if (!$access->hasScope($tool->requiredScope())) {
                throw ToolRefusal::missingScope($tool->requiredScope());
            }
            $this->refuseInvalidInput($arguments, $definition->inputSchema);
            $target = $tool->targetOf($arguments);
            if (null !== $target && !$this->authorizationChecker->isGranted($target->voterAttribute, $target->subject)) {
                throw ToolRefusal::notFound();
            }
            $toolAnswer = $tool->call($arguments, $target);
        } catch (ToolRefusal $refusal) {
            $this->toolCallAuditor->record($access, $userId, $definition->name, $target, $refusal->outcome);

            throw $refusal;
        } catch (\Throwable $failure) {
            $this->recordFailure($access, $userId, $definition->name, $target);

            throw $failure;
        }

        $this->toolCallAuditor->record($access, $userId, $definition->name, $target, ToolCallOutcome::Ok);

        return $toolAnswer;
    }

    /**
     * A new validator per call: the SDK's keeps every schema it has seen. It logs nothing here, as its own logging of
     * a failed check would write the tool's input.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $inputSchema
     *
     * @throws ToolRefusal invalid input, naming at most MAX_LISTED_INPUT_PROBLEMS problems
     */
    private function refuseInvalidInput(array $arguments, array $inputSchema): void
    {
        // An empty JSON object decodes to [], which the validator would take for an array.
        $problems = new SchemaValidator()->validateAgainstJsonSchema([] === $arguments ? new \stdClass() : $arguments, $inputSchema);
        if ([] === $problems) {
            return;
        }

        $descriptions = array_map(
            static fn (array $problem): string => ('' === $problem['pointer'] || '/' === $problem['pointer'] ? '' : $problem['pointer'].': ').$problem['message'],
            \array_slice($problems, 0, self::MAX_LISTED_INPUT_PROBLEMS),
        );

        throw ToolRefusal::invalidInput(implode('; ', $descriptions));
    }

    /**
     * The failure itself goes on to the caller; a row that cannot be written, as when the failure broke the
     * transaction, is logged by its class alone.
     */
    private function recordFailure(ApiTokenAccess $access, UserId $userId, string $toolName, ?ToolTarget $target): void
    {
        try {
            $this->toolCallAuditor->record($access, $userId, $toolName, $target, ToolCallOutcome::Error);
        } catch (\Throwable $auditFailure) {
            $this->logger->warning('The audit row of a failed MCP tool call could not be written.', ['tool' => $toolName, 'exception_class' => $auditFailure::class]);
        }
    }
}
