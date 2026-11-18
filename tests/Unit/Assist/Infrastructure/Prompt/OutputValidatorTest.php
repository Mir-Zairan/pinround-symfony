<?php

declare(strict_types=1);

namespace App\Tests\Unit\Assist\Infrastructure\Prompt;

use App\Assist\Application\AiAnswerRejected;
use App\Assist\Application\PromptVersion;
use App\Assist\Application\UnknownPromptTemplate;
use App\Assist\Domain\AiFeature;
use App\Assist\Infrastructure\Prompt\OutputValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OutputValidatorTest extends TestCase
{
    private const string VALID_INTAKE = '{"summary":"The pay button does nothing.","type":"Bug","priority":"Major","duplicates":["SHOP-12"],"missing":false,"risky":true}';
    private const array CANDIDATE_KEYS = ['SHOP-12', 'SHOP-14'];

    private OutputValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new OutputValidator();
    }

    #[Test]
    public function aValidIntakeAnswerPasses(): void
    {
        $data = $this->validator->checkData($this->intakeV1(), self::VALID_INTAKE, self::CANDIDATE_KEYS, false);

        self::assertSame([
            'summary' => 'The pay button does nothing.',
            'type' => 'Bug',
            'priority' => 'Major',
            'duplicates' => ['SHOP-12'],
            'missing' => false,
            'risky' => true,
        ], $data);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rejectedIntakeAnswers(): iterable
    {
        $schemaMismatch = 'The AI answer does not match the intake.v1 schema.';
        $notJson = 'The AI answer is not JSON.';
        $valid = json_decode(self::VALID_INTAKE, true, 8, \JSON_THROW_ON_ERROR);
        $with = static fn (array $changes): string => json_encode(array_merge($valid, $changes), \JSON_THROW_ON_ERROR);
        $without = static function (string $key) use ($valid): string {
            unset($valid[$key]);

            return json_encode($valid, \JSON_THROW_ON_ERROR);
        };

        yield 'extra key' => [$with(['assignee' => 'Raj']), $schemaMismatch];
        yield 'missing key' => [$without('risky'), $schemaMismatch];
        yield 'type not in the enum' => [$with(['type' => 'Epic']), $schemaMismatch];
        yield 'priority in the wrong case' => [$with(['priority' => 'major']), $schemaMismatch];
        yield 'priority Blocker, which intake may not suggest' => [$with(['priority' => 'Blocker']), $schemaMismatch];
        yield 'lower-case duplicate key' => [$with(['duplicates' => ['shop-12']]), $schemaMismatch];
        yield 'duplicate key with no number' => [$with(['duplicates' => ['SHOP-']]), $schemaMismatch];
        yield 'duplicate key that is a number' => [$with(['duplicates' => [12]]), $schemaMismatch];
        yield 'four duplicates' => [$with(['duplicates' => ['SHOP-1', 'SHOP-2', 'SHOP-3', 'SHOP-4']]), $schemaMismatch];
        yield 'summary of 301 characters' => [$with(['summary' => str_repeat('é', 301)]), $schemaMismatch];
        yield 'missing as a string' => [$with(['missing' => 'false']), $schemaMismatch];
        yield 'a list, not an object' => ['[]', $schemaMismatch];
        yield 'JSON null' => ['null', $schemaMismatch];
        yield 'plain text' => ['The ticket is a bug with Major priority.', $notJson];
        yield 'text before the JSON' => ['Here is the JSON: '.self::VALID_INTAKE, $notJson];
        yield 'text before a code fence' => ["Here you go:\n```json\n".self::VALID_INTAKE."\n```", $notJson];
        yield 'cut off JSON' => [substr(self::VALID_INTAKE, 0, 40), $notJson];
        yield 'nested deeper than any intake answer' => ['{"summary":[[[[[[[[["x"]]]]]]]]]}', $notJson];
        yield 'blank' => [" \r\n\t", 'The AI answer is blank.'];
        yield 'over 20,000 bytes' => [str_repeat(' ', 20001).self::VALID_INTAKE, 'The AI answer is longer than 20000 bytes.'];
    }

    #[Test]
    #[DataProvider('rejectedIntakeAnswers')]
    public function aBadIntakeAnswerIsRejectedWithOneRetryAllowed(string $answer, string $reason): void
    {
        $rejection = $this->rejectionOf(fn (): array => $this->validator->checkData($this->intakeV1(), $answer, self::CANDIDATE_KEYS, false));

        self::assertSame($reason, $rejection->getMessage());
        self::assertTrue($rejection->isRetryAllowed);
    }

    #[Test]
    #[DataProvider('rejectedIntakeAnswers')]
    public function aBadIntakeAnswerToTheRetryIsRefusedWithNoMoreTries(string $answer, string $reason): void
    {
        $rejection = $this->rejectionOf(fn (): array => $this->validator->checkData($this->intakeV1(), $answer, self::CANDIDATE_KEYS, true));

        self::assertSame($reason, $rejection->getMessage());
        self::assertFalse($rejection->isRetryAllowed);
    }

    #[Test]
    public function aValidAnswerToTheRetryPasses(): void
    {
        $data = $this->validator->checkData($this->intakeV1(), self::VALID_INTAKE, self::CANDIDATE_KEYS, true);

        self::assertSame('Bug', $data['type']);
    }

    #[Test]
    public function theRetryInstructionAsksForValidJsonOnly(): void
    {
        self::assertStringContainsString('Return only valid JSON', $this->validator->retryInstruction());
    }

    #[Test]
    public function anAnswerThatIsExactlyOneJsonCodeFenceIsReadWithoutTheFence(): void
    {
        $answer = "\u{FEFF}```json\r\n".self::VALID_INTAKE."\r\n```\n";

        $data = $this->validator->checkData($this->intakeV1(), $answer, self::CANDIDATE_KEYS, false);

        self::assertSame('Bug', $data['type']);
    }

    #[Test]
    public function duplicateKeysThatWereNotSentAsCandidatesAreDropped(): void
    {
        $answer = str_replace('["SHOP-12"]', '["SHOP-99","SHOP-14","SHOP-14"]', self::VALID_INTAKE);

        $data = $this->validator->checkData($this->intakeV1(), $answer, self::CANDIDATE_KEYS, false);

        self::assertSame(['SHOP-14'], $data['duplicates']);
    }

    #[Test]
    public function noCandidatesSentDropsEveryDuplicateKey(): void
    {
        $data = $this->validator->checkData($this->intakeV1(), self::VALID_INTAKE, [], false);

        self::assertSame([], $data['duplicates']);
    }

    #[Test]
    public function aDuplicateKeyWithATrailingNewlineNeverComesThrough(): void
    {
        $answer = str_replace('["SHOP-12"]', '["SHOP-12\\n"]', self::VALID_INTAKE);

        $data = $this->validator->checkData($this->intakeV1(), $answer, self::CANDIDATE_KEYS, false);

        self::assertSame([], $data['duplicates']);
    }

    #[Test]
    public function everyStringInADataAnswerIsCleanedButNotHtmlEscaped(): void
    {
        $summaryAsJson = json_encode("<b>Pay</b> & 'card' \"fails\" \u{1B}[31m\u{200B}\u{FEFF}now\u{2028}today", \JSON_THROW_ON_ERROR);
        self::assertStringContainsString('\u001b[31m', $summaryAsJson);
        $answer = str_replace('"The pay button does nothing."', $summaryAsJson, self::VALID_INTAKE);

        $data = $this->validator->checkData($this->intakeV1(), $answer, self::CANDIDATE_KEYS, false);

        self::assertSame("<b>Pay</b> & 'card' \"fails\" [31mnow\ntoday", $data['summary']);
    }

    #[Test]
    public function aSummaryIsTrimmedWithUnixLineEndsAndNotHtmlEscaped(): void
    {
        $text = $this->validator->checkText($this->summaryV1(), "\u{FEFF}  <script>alert(1)</script> Checkout fails.\r\nLena sees \"Error\" & 'Retry'.  \n");

        self::assertSame("<script>alert(1)</script> Checkout fails.\nLena sees \"Error\" & 'Retry'.", $text);
    }

    #[Test]
    public function controlAndZeroWidthCharactersAreRemovedAndLineSeparatorsBecomeNewlines(): void
    {
        $text = $this->validator->checkText(
            $this->summaryV1(),
            "Check\u{00AD}out\u{1B}[31m fails.\u{2028}Omar\ttests\u{200D} it.\u{2029}Mia\u{2060} signs off.",
        );

        self::assertSame("Checkout[31m fails.\nOmar\ttests it.\nMia signs off.", $text);
    }

    #[Test]
    public function aLongRunOfStopsIsCountedOnceAndQuickly(): void
    {
        $summary = 'Checkout fails'.str_repeat('.', 19000).'x';

        self::assertSame($summary, $this->validator->checkText($this->summaryV1(), $summary));
    }

    #[Test]
    public function aVersionWithNoShippedTemplateIsRefusedForText(): void
    {
        $this->expectException(UnknownPromptTemplate::class);
        $this->expectExceptionMessageIs('There is no prompt template summary.v999.');

        $this->validator->checkText(new PromptVersion(AiFeature::Summary, 999), 'Checkout fails.');
    }

    #[Test]
    public function aSummaryOf60WordsAndFourSentencesPasses(): void
    {
        $summary = $this->sentences([15, 15, 15, 15]);

        self::assertSame($summary, $this->validator->checkText($this->summaryV1(), $summary));
    }

    #[Test]
    public function aSummaryOver60WordsIsRefusedWithNoRetry(): void
    {
        $rejection = $this->rejectionOf(fn (): string => $this->validator->checkText($this->summaryV1(), $this->sentences([15, 15, 15, 16])));

        self::assertSame('The AI answer to summary.v1 has more than 60 words.', $rejection->getMessage());
        self::assertFalse($rejection->isRetryAllowed);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function summariesOfFiveSentences(): iterable
    {
        yield 'five stops' => ['One. Two! Three? Four. Five.'];
        yield 'four stops and trailing words' => ['One. Two! Three? Four. Five'];
    }

    #[Test]
    #[DataProvider('summariesOfFiveSentences')]
    public function aSummaryOfFiveSentencesIsRefused(string $summary): void
    {
        $rejection = $this->rejectionOf(fn (): string => $this->validator->checkText($this->summaryV1(), $summary));

        self::assertSame('The AI answer to summary.v1 has more than 4 sentences.', $rejection->getMessage());
        self::assertFalse($rejection->isRetryAllowed);
    }

    #[Test]
    public function aStopInsideAWordDoesNotEndASentence(): void
    {
        $summary = 'Version 1.2.3 fails. Mia found it. Omar fixes it. Raj checks shop.example.com today.';

        self::assertSame($summary, $this->validator->checkText($this->summaryV1(), $summary));
    }

    #[Test]
    public function anExplanationOf160WordsPassesAndOf161IsRefused(): void
    {
        $validator = new OutputValidator(__DIR__.'/Fixtures/templates');
        $explanation = $this->sentences(array_fill(0, 16, 10));
        self::assertSame($explanation, $validator->checkText(new PromptVersion(AiFeature::Explain, 1), $explanation));

        $rejection = $this->rejectionOf(static fn (): string => $validator->checkText(new PromptVersion(AiFeature::Explain, 1), $explanation.' More'));

        self::assertSame('The AI answer to explain.v1 has more than 160 words.', $rejection->getMessage());
        self::assertFalse($rejection->isRetryAllowed);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unusableTextAnswers(): iterable
    {
        yield 'blank' => [" \r\n\u{FEFF}", 'The AI answer is blank.'];
        yield 'only control characters' => ["\u{1B}\u{7F}", 'The AI answer is blank.'];
        yield 'over 20,000 bytes' => [str_repeat(' ', 20000).'Short.', 'The AI answer is longer than 20000 bytes.'];
    }

    #[Test]
    #[DataProvider('unusableTextAnswers')]
    public function anUnusableTextAnswerIsRefusedWithNoRetry(string $answer, string $reason): void
    {
        $rejection = $this->rejectionOf(fn (): string => $this->validator->checkText($this->summaryV1(), $answer));

        self::assertSame($reason, $rejection->getMessage());
        self::assertFalse($rejection->isRetryAllowed);
    }

    #[Test]
    public function anIntakeAnswerCannotBeCheckedAsText(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIs('The intake feature answers with data; check it with checkData().');

        $this->validator->checkText($this->intakeV1(), 'Short.');
    }

    #[Test]
    public function aSummaryCannotBeCheckedAsData(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIs('The summary feature answers with text; check it with checkText().');

        $this->validator->checkData($this->summaryV1(), self::VALID_INTAKE, self::CANDIDATE_KEYS, false);
    }

    #[Test]
    public function aVersionWithNoSchemaFailsClosedInsteadOfPassingTheAnswer(): void
    {
        $this->expectException(UnknownPromptTemplate::class);
        $this->expectExceptionMessageIs('The prompt template intake.v2 has no output schema.');

        $this->validator->checkData(new PromptVersion(AiFeature::Intake, 2), self::VALID_INTAKE, self::CANDIDATE_KEYS, false);
    }

    #[Test]
    public function aSchemaFileThatIsNotAnObjectFailsClosed(): void
    {
        $validator = new OutputValidator(__DIR__.'/Fixtures/templates');

        $this->expectException(UnknownPromptTemplate::class);
        $this->expectExceptionMessageIs('The prompt template intake.v3 has no output schema.');

        $validator->checkData(new PromptVersion(AiFeature::Intake, 3), self::VALID_INTAKE, self::CANDIDATE_KEYS, false);
    }

    private function summaryV1(): PromptVersion
    {
        return new PromptVersion(AiFeature::Summary, 1);
    }

    private function intakeV1(): PromptVersion
    {
        return new PromptVersion(AiFeature::Intake, 1);
    }

    /**
     * @param list<int> $wordsPerSentence
     */
    private function sentences(array $wordsPerSentence): string
    {
        $sentences = array_map(
            static fn (int $words): string => ucfirst(implode(' ', array_fill(0, $words, 'word'))).'.',
            $wordsPerSentence,
        );

        return implode(' ', $sentences);
    }

    private function rejectionOf(callable $check): AiAnswerRejected
    {
        try {
            $check();
        } catch (AiAnswerRejected $rejection) {
            return $rejection;
        }

        self::fail('The answer was not rejected.');
    }
}
