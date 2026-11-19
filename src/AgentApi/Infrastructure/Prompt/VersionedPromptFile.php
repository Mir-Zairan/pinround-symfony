<?php

declare(strict_types=1);

namespace App\AgentApi\Infrastructure\Prompt;

/**
 * Reads a prompt's versioned file, "<version>.md": a change of wording is a new file and a new version, never an edit.
 */
final class VersionedPromptFile
{
    /**
     * @return string the file's text, byte for byte
     *
     * @throws \RuntimeException when the file is missing, unreadable or empty
     */
    public static function read(string $promptDirectory, string $version): string
    {
        $path = $promptDirectory.'/'.$version.'.md';
        $text = is_readable($path) ? file_get_contents($path) : false;
        if (false === $text || '' === $text) {
            throw new \RuntimeException(\sprintf('The prompt file %s.md is missing or empty.', $version));
        }

        return $text;
    }
}
