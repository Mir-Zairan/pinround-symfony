<?php

declare(strict_types=1);

namespace App\Assist\Infrastructure\Prompt;

use App\Assist\Application\AiAnswerRejected;
use App\Assist\Application\PromptVersion;
use App\Assist\Application\UnknownPromptTemplate;
use App\Assist\Domain\AiFeature;
use JsonSchema\Constraints\Constraint;
use JsonSchema\Validator;

/**
 * The one check on every AI answer, from a server provider or a laptop (ADR-0020): text answers must fit the size
 * limits of FR-AI-18, data answers must match the template's `{feature}.v{n}.schema.json`. Every string that passes is
 * HTML-escaped, so it can never become markup.
 *
 * A data answer that is exactly one Markdown code fence (```json … ```) is read without the fence, because small
 * models add one even when told not to; anything else around the JSON rejects it.
 */
final readonly class OutputValidator
{
    /** Longer than any answer that fits the limits; a bigger one is refused before it is parsed. */
    public const int MAX_ANSWER_BYTES = 20000;
    public const int SUMMARY_MAX_WORDS = 60;
    public const int SUMMARY_MAX_SENTENCES = 4;
    public const int EXPLANATION_MAX_WORDS = 160;

    private const int JSON_MAX_DEPTH = 8;
    private const string FENCE_PATTERN = '/\A```(?:json)?[ \t]*\R(?<json>.*)\R[ \t]*```\z/si';
    /** Every control character but tab and newline, the Unicode line and paragraph separators, and byte order marks. */
    private const string CONTROL_CHARACTERS_PATTERN = '/[\x00-\x08\x0B-\x1F\x7F\x{2028}\x{2029}\x{FEFF}]/u';

    public function __construct(private string $schemaDirectory = __DIR__.'/templates')
    {
    }

    /**
     * The message to add to the prompt for the one second try after a rejection that allows it.
     */
    public function retryInstruction(): string
    {
        return 'Your last answer was not valid. Return only valid JSON that matches the format above, with no other text.';
    }

    /**
     * Checks a summary or explanation. A text answer that fails is not retried.
     *
     * @return string the answer, trimmed, with "\n" line ends and HTML-escaped
     *
     * @throws AiAnswerRejected when the answer is blank, too big, or has too many words or sentences
     * @throws \LogicException  when the feature answers with data, not text
     */
    public function checkText(PromptVersion $version, string $answer): string
    {
        [$maxWords, $maxSentences] = match ($version->feature) {
            AiFeature::Summary => [self::SUMMARY_MAX_WORDS, self::SUMMARY_MAX_SENTENCES],
            AiFeature::Explain => [self::EXPLANATION_MAX_WORDS, null],
            AiFeature::Intake => throw new \LogicException('The intake feature answers with data; check it with checkData().'),
        };

        $text = $this->normalise($answer, false);
        if ((int) preg_match_all('/\S+/u', $text) > $maxWords) {
            throw AiAnswerRejected::tooManyWords($version, $maxWords);
        }
        if (null !== $maxSentences && self::countSentences($text) > $maxSentences) {
            throw AiAnswerRejected::tooManySentences($version, $maxSentences);
        }

        return self::escape($text);
    }

    /**
     * Checks an intake answer against its schema, then keeps only the duplicate keys that were sent as candidates.
     *
     * @param list<string> $candidateKeys the duplicate candidate keys that went into the prompt
     * @param bool         $isRetry       true for the answer to the second try; a rejection then allows no more tries
     *
     * @return array<string, mixed> the answer with every string HTML-escaped
     *
     * @throws AiAnswerRejected      when the answer is blank, too big, not JSON or does not match the schema
     * @throws UnknownPromptTemplate when the template has no schema file
     * @throws \LogicException       when the feature answers with text, not data
     */
    public function checkData(PromptVersion $version, string $answer, array $candidateKeys, bool $isRetry): array
    {
        if (AiFeature::Intake !== $version->feature) {
            throw new \LogicException(\sprintf('The %s feature answers with text; check it with checkText().', $version->feature->value));
        }
        $schema = $this->loadSchema($version);
        $isRetryAllowed = !$isRetry;

        $json = $this->normalise($answer, $isRetryAllowed);
        if (1 === preg_match(self::FENCE_PATTERN, $json, $fence)) {
            $json = trim($fence['json']);
        }

        try {
            $answerForSchema = json_decode($json, false, self::JSON_MAX_DEPTH, \JSON_THROW_ON_ERROR);
            /** @var array<string, mixed> $answerFields the schema below proves it is an object */
            $answerFields = json_decode($json, true, self::JSON_MAX_DEPTH, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw AiAnswerRejected::notJson($isRetryAllowed);
        }

        $schemaValidator = new Validator();
        $schemaValidator->validate($answerForSchema, $schema, Constraint::CHECK_MODE_NORMAL);
        if (!$schemaValidator->isValid()) {
            throw AiAnswerRejected::schemaMismatch($isRetryAllowed, $version);
        }

        if (\is_array($answerFields['duplicates'] ?? null)) {
            $answerFields['duplicates'] = array_values(array_unique(array_filter(
                $answerFields['duplicates'],
                static fn (mixed $key): bool => \in_array($key, $candidateKeys, true),
            )));
        }

        return self::escapeStrings($answerFields);
    }

    private function loadSchema(PromptVersion $version): object
    {
        $path = \sprintf('%s/%s.schema.json', $this->schemaDirectory, $version->name());
        if (!is_file($path)) {
            throw UnknownPromptTemplate::noSchema($version);
        }

        $schema = json_decode((string) file_get_contents($path), false, 32, \JSON_THROW_ON_ERROR);
        if (!$schema instanceof \stdClass) {
            throw UnknownPromptTemplate::noSchema($version);
        }

        return $schema;
    }

    /**
     * @throws AiAnswerRejected when the answer is too big or blank
     */
    private function normalise(string $answer, bool $isRetryAllowed): string
    {
        if (\strlen($answer) > self::MAX_ANSWER_BYTES) {
            throw AiAnswerRejected::oversized($isRetryAllowed, self::MAX_ANSWER_BYTES);
        }

        $unixLines = str_replace(["\r\n", "\r"], "\n", mb_scrub($answer, 'UTF-8'));
        $text = trim(self::stripControlCharacters($unixLines));
        if ('' === $text) {
            throw AiAnswerRejected::blank($isRetryAllowed);
        }

        return $text;
    }

    /**
     * A sentence ends at ".", "!" or "?" followed by a space or the end; trailing words with no stop count as one more.
     */
    private static function countSentences(string $text): int
    {
        $stops = preg_match_all('/[.!?]+(?=\s|\z)/u', $text);
        $endsWithStop = 1 === preg_match('/[.!?]\z/u', $text);

        return (int) $stops + ($endsWithStop ? 0 : 1);
    }

    /**
     * @template TKey of array-key
     *
     * @param array<TKey, mixed> $values
     *
     * @return array<TKey, mixed>
     */
    private static function escapeStrings(array $values): array
    {
        return array_map(
            static fn (mixed $value): mixed => match (true) {
                \is_string($value) => self::escape(self::stripControlCharacters($value)),
                \is_array($value) => self::escapeStrings($value),
                default => $value,
            },
            $values,
        );
    }

    private static function stripControlCharacters(string $text): string
    {
        return preg_replace(self::CONTROL_CHARACTERS_PATTERN, '', $text)
            ?? throw new \RuntimeException('The AI answer could not be cleaned.');
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE | \ENT_HTML5, 'UTF-8');
    }
}
