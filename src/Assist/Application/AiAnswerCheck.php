<?php

declare(strict_types=1);

namespace App\Assist\Application;

/**
 * The one check on every AI answer, from a server provider or a laptop (ADR-0020). It returns plain text: it never
 * HTML-escapes, because Twig escapes AI text when it is shown and `|raw` is never used on it.
 */
interface AiAnswerCheck
{
    /**
     * The message to add to the prompt for the one second try after a rejection that allows it.
     */
    public function retryInstruction(): string;

    /**
     * Checks a summary or explanation against its size limits. A text answer that fails is not retried.
     *
     * @return string the answer, trimmed, with "\n" line ends and no control or zero-width characters
     *
     * @throws AiAnswerRejected      when the answer is blank, too big, or has too many words or sentences
     * @throws UnknownPromptTemplate when the version has no shipped template
     * @throws \LogicException       when the feature answers with data, not text
     */
    public function checkText(PromptVersion $version, string $answer): string;

    /**
     * Checks a data answer against its version's schema, then keeps only the duplicate keys that were sent as
     * candidates.
     *
     * @param list<string> $candidateKeys the duplicate candidate keys that went into the prompt
     * @param bool         $isRetry       true for the answer to the second try; a rejection then allows no more tries
     *
     * @return array<string, mixed> the answer, every string with no control or zero-width characters
     *
     * @throws AiAnswerRejected      when the answer is blank, too big, not JSON or does not match the schema
     * @throws UnknownPromptTemplate when the version has no schema file
     * @throws \LogicException       when the feature answers with text, not data
     */
    public function checkData(PromptVersion $version, string $answer, array $candidateKeys, bool $isRetry): array;
}
