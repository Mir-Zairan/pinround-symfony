<?php

declare(strict_types=1);

namespace App\AgentApi\Infrastructure\Mcp;

/**
 * An expected refusal of an MCP tool call, from ToolCallGate or from the tool itself, or of a prompt read, from
 * GatedPromptGetHandler or from the prompt. Its message goes to the client, so it never says whether a refused target
 * exists: a target the caller may not see is "not found" (ADR-0013).
 */
final class ToolRefusal extends \RuntimeException
{
    public const string NOT_FOUND = 'Not found. It does not exist, or you may not see it.';
    public const string READ_ONLY = 'This is read-only now, so it cannot be changed.';
    public const string MISSING_SCOPE = 'This token does not have the "%s" scope. Make a token with it in Settings.';
    public const string INVALID_INPUT = 'The arguments do not match the input schema of this tool: %s';
    public const string INVALID_PROMPT_ARGUMENTS = 'The arguments do not match this prompt: %s';

    private function __construct(string $message, public readonly ToolCallOutcome $outcome)
    {
        parent::__construct($message);
    }

    public static function notFound(): self
    {
        return new self(self::NOT_FOUND, ToolCallOutcome::NotFound);
    }

    public static function readOnly(): self
    {
        return new self(self::READ_ONLY, ToolCallOutcome::ReadOnly);
    }

    public static function missingScope(string $scope): self
    {
        return new self(\sprintf(self::MISSING_SCOPE, $scope), ToolCallOutcome::MissingScope);
    }

    /**
     * @param string $problems what the schema check found, for the client only: it may name the caller's own input
     */
    public static function invalidInput(string $problems): self
    {
        return new self(\sprintf(self::INVALID_INPUT, $problems), ToolCallOutcome::InvalidInput);
    }

    /**
     * @param string $problems what is wrong with a prompt's arguments, for the client only; it names no argument the
     *                         prompt does not list
     */
    public static function invalidPromptArguments(string $problems): self
    {
        return new self(\sprintf(self::INVALID_PROMPT_ARGUMENTS, $problems), ToolCallOutcome::InvalidInput);
    }

    /**
     * A plain refusal of the domain that none of the others names, such as a picture processing rejected.
     *
     * @param string $reason the plain text the client is shown as it is; it must not tell whether a target the caller
     *                       may not see exists
     */
    public static function refused(string $reason): self
    {
        return new self($reason, ToolCallOutcome::Refused);
    }
}
