<?php

declare(strict_types=1);

namespace App\AgentApi\Infrastructure\Mcp;

/**
 * What the tools share about their input: the one cap on an id or key argument, and reading a string argument the
 * input schema has checked already.
 */
final class ToolArguments
{
    /**
     * The longest id (a uuid is 36 characters) or ticket key a tool's input schema takes; the cap only keeps a lookup
     * from reading megabytes. RestToolController reads it from the schema to answer a longer id in the path as not
     * found.
     */
    public const int MAX_ID_LENGTH = 64;

    /**
     * @param array<string, mixed> $arguments valid against the tool's input schema
     * @param string               $toolName  for the message of a call the schema should have refused
     *
     * @throws \LogicException when the argument is missing or not a string, which the schema never lets through
     */
    public static function stringAt(array $arguments, string $name, string $toolName): string
    {
        return self::optionalStringAt($arguments, $name, $toolName) ?? throw new \LogicException(\sprintf('The input schema lets %s run only with "%s".', $toolName, $name));
    }

    /**
     * @param array<string, mixed> $arguments valid against the tool's input schema
     * @param string               $toolName  for the message of a call the schema should have refused
     *
     * @return string|null null when the argument was left out
     *
     * @throws \LogicException when the argument is not a string, which the schema never lets through
     */
    public static function optionalStringAt(array $arguments, string $name, string $toolName): ?string
    {
        $value = $arguments[$name] ?? null;
        if (null !== $value && !\is_string($value)) {
            throw new \LogicException(\sprintf('The input schema lets %s run only with "%s" as a string.', $toolName, $name));
        }

        return $value;
    }
}
