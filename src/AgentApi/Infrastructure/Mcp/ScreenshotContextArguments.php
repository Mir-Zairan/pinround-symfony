<?php

declare(strict_types=1);

namespace App\AgentApi\Infrastructure\Mcp;

/**
 * A picture's title and summary, which an MCP call must give (P14.T11) while the web form, /api/v1 and the browser
 * extension leave both optional: a picture schema with both required, and the refusal of one that is missing or blank.
 */
final class ScreenshotContextArguments
{
    public const string RULE = 'Every picture needs a title, what it is of, and a summary, what it shows in plain words.';
    /** %s is the JSON pointer of the title or summary, such as /screenshots/0/summary. */
    public const string MISSING_OR_BLANK = '%s is missing or blank. '.self::RULE;

    private const array NAMES = ['title', 'summary'];
    /** Only white space, control and format characters, such as CR LF, a BOM or a zero-width space: nothing to read. */
    private const string BLANK = '/\A[\p{Z}\p{Cc}\p{Cf}]*\z/u';

    /**
     * @param array<string, mixed> $pictureSchema an object schema with title and summary among its properties
     *
     * @return array<string, mixed> the same schema with both required, at least one character each
     *
     * @throws \LogicException when the schema has no title or summary property
     */
    public static function requiredIn(array $pictureSchema): array
    {
        $properties = $pictureSchema['properties'] ?? null;
        $required = $pictureSchema['required'] ?? [];
        if (!\is_array($properties) || !\is_array($required)) {
            throw new \LogicException('A picture schema needs its properties and required as lists.');
        }
        foreach (self::NAMES as $name) {
            $property = $properties[$name] ?? null;
            if (!\is_array($property)) {
                throw new \LogicException(\sprintf('A picture schema without "%s" cannot require it.', $name));
            }
            $properties[$name] = [...$property, 'minLength' => 1];
            if (!\in_array($name, $required, true)) {
                $required[] = $name;
            }
        }

        return [...$pictureSchema, 'properties' => $properties, 'required' => $required];
    }

    /**
     * Reads the picture as the client sent it, before the input schema is checked: a picture that is no object, or a
     * title or summary that is no string, is left to the schema. A null one is missing.
     *
     * @param string $pointer the picture's JSON pointer: '' for the arguments themselves
     *
     * @throws ToolRefusal invalid input naming the first of the title and the summary that is missing, empty or blank
     */
    public static function refuseMissingIn(mixed $picture, string $pointer): void
    {
        if (!\is_array($picture)) {
            return;
        }
        foreach (self::NAMES as $name) {
            $text = $picture[$name] ?? null;
            if (null === $text || (\is_string($text) && 1 === preg_match(self::BLANK, $text))) {
                throw ToolRefusal::invalidInput(\sprintf(self::MISSING_OR_BLANK, $pointer.'/'.$name));
            }
        }
    }
}
