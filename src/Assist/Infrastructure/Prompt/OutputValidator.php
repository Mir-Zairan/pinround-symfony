<?php

declare(strict_types=1);

namespace App\Assist\Infrastructure\Prompt;

use App\Assist\Application\AiAnswerCheck;
use App\Assist\Application\AiAnswerRejected;
use App\Assist\Application\PromptVersion;
use App\Assist\Application\UnknownPromptTemplate;
use App\Assist\Domain\AiFeature;
use JsonSchema\Constraints\Constraint;
use JsonSchema\Validator;

/**
 * Checks AI answers against the template they answer: text answers must fit the size limits of FR-AI-18, data answers
 * must match the template's `{feature}.v{n}.schema.json`. Every string that passes is cleaned as UntrustedText.
 *
 * Twig escapes at render; the checker never escapes. An answer that is stored and shown later is still plain text, so
 * escaping here would show "&quot;" to people and save it into tickets.
 *
 * A data answer that is exactly one Markdown code fence (```json … ```) is read without the fence, because small
 * models add one even when told not to; anything else around the JSON rejects it.
 */
final readonly class OutputValidator implements AiAnswerCheck
{
    /** Longer than any answer that fits the limits; a bigger one is refused before it is parsed. */
    public const int MAX_ANSWER_BYTES = 20000;
    public const int SUMMARY_MAX_WORDS = 60;
    public const int SUMMARY_MAX_SENTENCES = 4;
    public const int EXPLANATION_MAX_WORDS = 160;

    private const int JSON_MAX_DEPTH = 8;
    private const string FENCE_PATTERN = '/\A```(?:json)?[ \t]*+\R(?<json>.*)\R[ \t]*+```\z/si';
    /** A run of stops starts only after a non-stop, so a long run of dots is matched once, never from each dot again. */
    private const string SENTENCE_STOP_PATTERN = '/(?<![.!?])[.!?]++(?=\s|\z)/u';

    public function __construct(private string $templateDirectory = __DIR__.'/templates')
    {
    }

    public function retryInstruction(): string
    {
        return 'Your last answer was not valid. Return only valid JSON that matches the format above, with no other text.';
    }

    public function checkText(PromptVersion $version, string $answer): string
    {
        [$maxWords, $maxSentences] = match ($version->feature) {
            AiFeature::Summary => [self::SUMMARY_MAX_WORDS, self::SUMMARY_MAX_SENTENCES],
            AiFeature::Explain => [self::EXPLANATION_MAX_WORDS, null],
            AiFeature::Intake => throw new \LogicException('The intake feature answers with data; check it with checkData().'),
        };
        if (!is_file(\sprintf('%s/%s.md.twig', $this->templateDirectory, $version->name()))) {
            throw UnknownPromptTemplate::noTemplate($version);
        }

        $text = $this->normalise($answer, false);
        if (self::countMatches('/\S++/u', $text) > $maxWords) {
            throw AiAnswerRejected::tooManyWords($version, $maxWords);
        }
        if (null !== $maxSentences && self::countSentences($text) > $maxSentences) {
            throw AiAnswerRejected::tooManySentences($version, $maxSentences);
        }

        return $text;
    }

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

        return self::cleanStrings($answerFields);
    }

    private function loadSchema(PromptVersion $version): object
    {
        $path = \sprintf('%s/%s.schema.json', $this->templateDirectory, $version->name());
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

        $text = trim(UntrustedText::normalise($answer));
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
        $endsWithStop = 1 === preg_match('/[.!?]\z/u', $text);

        return self::countMatches(self::SENTENCE_STOP_PATTERN, $text) + ($endsWithStop ? 0 : 1);
    }

    /**
     * @throws \RuntimeException when the pattern fails, so a failed count never passes as zero
     */
    private static function countMatches(string $pattern, string $text): int
    {
        $count = preg_match_all($pattern, $text);
        if (false === $count) {
            throw new \RuntimeException('The AI answer could not be measured.');
        }

        return $count;
    }

    /**
     * @template TKey of array-key
     *
     * @param array<TKey, mixed> $values
     *
     * @return array<TKey, mixed>
     */
    private static function cleanStrings(array $values): array
    {
        return array_map(
            static fn (mixed $value): mixed => match (true) {
                \is_string($value) => UntrustedText::normalise($value),
                \is_array($value) => self::cleanStrings($value),
                default => $value,
            },
            $values,
        );
    }
}
