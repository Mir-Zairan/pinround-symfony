<?php

declare(strict_types=1);

namespace App\AgentApi\Infrastructure\Mcp;

use App\Identity\Api\ApiTokenAccess;
use App\Identity\Api\CurrentApiToken;
use App\Identity\Api\CurrentUser;
use App\SharedKernel\Domain\UserId;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Mcp\Capability\Discovery\SchemaValidator;
use Mcp\Schema\Result\CallToolResult;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * The one way an MCP tool runs (ADR-0021): the token's scope, the input schema, the tool's target, the voter the web UI
 * runs for the same action (a denial reads as not found, ADR-0013), the tool, and one audit row for every call that
 * got here, refused or not.
 *
 * The target, the tool and its "ok" row run in a savepoint of the request transaction. A refusal or failure there,
 * the row's own write included, rolls back to it before the refusal or "error" row is written, so a call that did not
 * end "ok" keeps none of the tool's writes, and no tool write is kept without its row. Holds no state, so it is safe in
 * worker mode (ADR-0008).
 */
final readonly class ToolCallGate
{
    private const int MAX_LISTED_INPUT_PROBLEMS = 3;

    public function __construct(
        private CurrentApiToken $currentApiToken,
        private CurrentUser $currentUser,
        private AuthorizationCheckerInterface $authorizationChecker,
        private ToolCallAuditor $toolCallAuditor,
        private Connection $connection,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $arguments what the client sent, unchecked
     *
     * @throws ToolRefusal     after the tool's writes were rolled back and its audit row was written
     * @throws \LogicException when the request has no token, no user or no open transaction, which the api_token
     *                         firewall and the request transaction never let through; nothing runs and no row is
     *                         written
     * @throws \Throwable      any other failure, after the tool's writes were rolled back and an "error" row was tried;
     *                         when the rollback itself fails, the connection is closed, so nothing of the request is
     *                         committed
     */
    public function call(AgentTool $tool, array $arguments): CallToolResult
    {
        $access = $this->currentApiToken->access()
            ?? throw new \LogicException('An MCP tool call passed the api_token firewall without a personal access token.');
        $userId = $this->currentUser->id()
            ?? throw new \LogicException('An MCP tool call passed the api_token firewall without a user.');
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('An MCP tool call runs in the request transaction; none is open.');
        }
        $definition = $tool->definition();

        try {
            if (!$access->hasScope($tool->requiredScope())) {
                throw ToolRefusal::missingScope($tool->requiredScope());
            }
            $this->refuseInvalidInput($arguments, $definition->inputSchema);
        } catch (ToolRefusal $refusal) {
            $this->toolCallAuditor->record($access, $userId, $definition->name, null, $refusal->outcome);

            throw $refusal;
        }

        $target = null;
        $this->connection->beginTransaction();
        $savepointLevel = $this->connection->getTransactionNestingLevel();
        try {
            $target = $tool->targetOf($arguments);
            $this->refuseUnlessGranted($tool, $target);
            $toolAnswer = $tool->call($arguments, $target);
            $this->toolCallAuditor->record($access, $userId, $definition->name, $target, ToolCallOutcome::Ok);
            $this->releaseSavepoint($savepointLevel);
        } catch (\Throwable $failure) {
            $this->rollBackToSavepoint($savepointLevel, $definition->name);
            if ($failure instanceof ToolRefusal) {
                $this->toolCallAuditor->record($access, $userId, $definition->name, $target, $failure->outcome);
            } else {
                $this->recordFailure($access, $userId, $definition->name, $target);
            }

            throw $failure;
        }

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
     * Deny by default: a tool skips the voter only when it is an OwnIdentityTool and has no target.
     *
     * @throws ToolRefusal     not found, when the voter denies the target
     * @throws \LogicException when a tool that is no OwnIdentityTool has no target
     */
    private function refuseUnlessGranted(AgentTool $tool, ?ToolTarget $target): void
    {
        if (null === $target) {
            if (!$tool instanceof OwnIdentityTool) {
                throw new \LogicException(\sprintf('The MCP tool "%s" has no target, and only an OwnIdentityTool may run without one.', $tool->definition()->name));
            }

            return;
        }
        if (!$this->authorizationChecker->isGranted($target->voterAttribute, $target->subject)) {
            throw ToolRefusal::notFound();
        }
    }

    /**
     * @throws \LogicException when the tool left a transaction of its own open or ended the savepoint itself
     */
    private function releaseSavepoint(int $savepointLevel): void
    {
        if ($this->connection->getTransactionNestingLevel() !== $savepointLevel) {
            throw new \LogicException('An MCP tool did not end the transactions it began, or ended the one it ran in.');
        }

        $this->connection->commit();
    }

    /**
     * Also rolls back what the tool left open inside the savepoint. When the savepoint is gone (the tool ended it, or
     * its release failed) or the rollback fails, closing the connection ends the request transaction on the server,
     * so nothing the tool wrote is committed and the request fails when it commits.
     */
    private function rollBackToSavepoint(int $savepointLevel, string $toolName): void
    {
        try {
            if ($this->connection->getTransactionNestingLevel() < $savepointLevel) {
                throw new \LogicException('The savepoint of an MCP tool call is gone.');
            }
            while ($this->connection->getTransactionNestingLevel() >= $savepointLevel) {
                $this->connection->rollBack();
            }
        } catch (\Throwable $rollBackFailure) {
            // Class and code only: a database message can quote row values.
            $this->logger->warning('Rolling back the writes of a failed MCP tool call failed; closing the connection instead.', [
                'mcp_tool' => $toolName,
                'exception_class' => $rollBackFailure::class,
                'sqlstate' => $rollBackFailure instanceof DriverException ? $rollBackFailure->getSQLState() : null,
                'code' => $rollBackFailure->getCode(),
            ]);
            $this->connection->close();
        }
    }

    /**
     * The failure itself goes on to the caller; a row that cannot be written is logged by its class alone.
     */
    private function recordFailure(ApiTokenAccess $access, UserId $userId, string $toolName, ?ToolTarget $target): void
    {
        try {
            $this->toolCallAuditor->record($access, $userId, $toolName, $target, ToolCallOutcome::Error);
        } catch (\Throwable $auditFailure) {
            $this->logger->warning('The audit row of a failed MCP tool call could not be written.', ['mcp_tool' => $toolName, 'exception_class' => $auditFailure::class]);
        }
    }
}
