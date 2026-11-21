<?php

declare(strict_types=1);

namespace App\AgentApi\Infrastructure\Prompt;

/**
 * Fills the placeholders of a prompt file that takes arguments: "{{name}}", a name in lowercase letters and
 * underscores.
 */
final class PromptTemplate
{
    private const string PLACEHOLDER = '/\{\{([a-z_]+)\}\}/';

    /**
     * Each placeholder is replaced in one pass, so a value that holds "{{name}}" stays as it is.
     *
     * @param array<string, string> $values by placeholder name
     *
     * @throws \RuntimeException when the template holds a placeholder that $values does not fill, or lacks one that it
     *                           does, so a prompt is never sent half filled
     */
    public static function fill(string $template, array $values): string
    {
        preg_match_all(self::PLACEHOLDER, $template, $matches);
        $placeholderNames = array_values(array_unique($matches[1]));
        $valueNames = array_map(strval(...), array_keys($values));
        sort($placeholderNames);
        sort($valueNames);
        if ($placeholderNames !== $valueNames) {
            throw new \RuntimeException(\sprintf('The prompt template has the placeholders [%s], not [%s].', implode(', ', $placeholderNames), implode(', ', $valueNames)));
        }

        $replacements = [];
        foreach ($values as $name => $value) {
            $replacements['{{'.$name.'}}'] = $value;
        }

        return strtr($template, $replacements);
    }
}
