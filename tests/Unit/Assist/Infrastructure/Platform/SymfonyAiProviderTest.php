<?php

declare(strict_types=1);

namespace App\Tests\Unit\Assist\Infrastructure\Platform;

use App\Assist\Application\AiPrompt;
use App\Assist\Application\AiRateLimited;
use App\Assist\Application\AiUnavailable;
use App\Assist\Application\ProviderChoice;
use App\Assist\Domain\AiFeature;
use App\Assist\Infrastructure\Platform\SymfonyAiProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The real bridges of Symfony AI against recorded-shape HTTP answers; nothing leaves the host.
 */
final class SymfonyAiProviderTest extends TestCase
{
    private const string OPERATOR_HF_TOKEN = 'hf-operator-token';
    private const string OPERATOR_ANTHROPIC_KEY = 'anthropic-operator-key';
    private const string OLLAMA_URL = 'http://ollama.internal:11434';
    private const string LEAKED_TEXT = 'Acme Foods checkout ticket text';
    private const int TIMEOUT_SECONDS = 17;

    #[Test]
    public function huggingFaceAnswersWithTextTokensAndTheModelItReports(): void
    {
        $sent = [];
        $provider = $this->provider([$this->recorded($sent, new JsonMockResponse([
            'model' => 'meta-llama/Llama-3.1-8B-Instruct',
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'Paying fails on the last step.'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 42, 'completion_tokens' => 7, 'total_tokens' => 49],
        ]))]);

        $answer = $provider->complete($this->summaryPrompt(), ProviderChoice::fromSettings(null));

        self::assertSame('Paying fails on the last step.', $answer->text);
        self::assertSame('huggingface', $answer->provider);
        self::assertSame('meta-llama/Llama-3.1-8B-Instruct', $answer->model);
        self::assertSame(42, $answer->inputTokens);
        self::assertSame(7, $answer->outputTokens);
        self::assertGreaterThanOrEqual(0, $answer->durationMs);
        self::assertCount(1, $sent);
        self::assertSame('POST https://router.huggingface.co/v1/chat/completions', $sent[0]['request']);
        self::assertContains('Authorization: Bearer '.self::OPERATOR_HF_TOKEN, $sent[0]['headers']);
        self::assertStringContainsString('Summarize the ticket.', $sent[0]['body']);
        self::assertStringContainsString('The checkout button does nothing.', $sent[0]['body']);
        self::assertSame(0, $sent[0]['maxRedirects']);
    }

    #[Test]
    public function tokensTheProviderDoesNotReportStayNullAndTheChosenModelIsNamed(): void
    {
        $provider = $this->provider([new JsonMockResponse([
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'Short.'], 'finish_reason' => 'stop']],
        ])]);

        $answer = $provider->complete($this->summaryPrompt(), ProviderChoice::fromSettings('huggingface', 'Qwen/Qwen3-8B'));

        self::assertSame('Qwen/Qwen3-8B', $answer->model);
        self::assertNull($answer->inputTokens);
        self::assertNull($answer->outputTokens);
    }

    #[Test]
    public function theOrganizationsKeyIsSentInsteadOfTheOperatorsOne(): void
    {
        $sent = [];
        $provider = $this->provider([$this->recorded($sent, $this->huggingFaceAnswer())]);

        $provider->complete($this->summaryPrompt(), ProviderChoice::fromSettings(null, null, 'hf-acme-own-token'));

        self::assertContains('Authorization: Bearer hf-acme-own-token', $sent[0]['headers']);
        self::assertNotContains('Authorization: Bearer '.self::OPERATOR_HF_TOKEN, $sent[0]['headers']);
    }

    #[Test]
    public function anthropicIsCalledWithItsKeyHeader(): void
    {
        $sent = [];
        $provider = $this->provider([$this->recorded($sent, new JsonMockResponse([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-haiku-4-5',
            'content' => [['type' => 'text', 'text' => 'Paying fails.']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 30, 'output_tokens' => 4],
        ]))]);

        $answer = $provider->complete($this->summaryPrompt(), ProviderChoice::fromSettings('anthropic'));

        self::assertSame('Paying fails.', $answer->text);
        self::assertSame('anthropic', $answer->provider);
        self::assertSame(30, $answer->inputTokens);
        self::assertSame(4, $answer->outputTokens);
        self::assertSame('POST https://api.anthropic.com/v1/messages', $sent[0]['request']);
        self::assertContains('x-api-key: '.self::OPERATOR_ANTHROPIC_KEY, $sent[0]['headers']);
    }

    #[Test]
    public function ollamaIsCalledOnTheOperatorsUrl(): void
    {
        $sent = [];
        $provider = $this->provider([
            $this->recorded($sent, new JsonMockResponse(['capabilities' => ['completion']])),
            $this->recorded($sent, new JsonMockResponse([
                'model' => 'qwen3:4b',
                'message' => ['role' => 'assistant', 'content' => 'Paying fails.'],
                'done' => true,
                'done_reason' => 'stop',
                'prompt_eval_count' => 25,
                'eval_count' => 3,
            ])),
        ]);

        $answer = $provider->complete($this->summaryPrompt(), ProviderChoice::fromSettings('ollama'));

        self::assertSame('Paying fails.', $answer->text);
        self::assertSame('ollama', $answer->provider);
        self::assertSame('qwen3:4b', $answer->model);
        self::assertSame(25, $answer->inputTokens);
        self::assertSame(3, $answer->outputTokens);
        self::assertSame(['POST '.self::OLLAMA_URL.'/api/show', 'POST '.self::OLLAMA_URL.'/api/chat'], array_column($sent, 'request'));
    }

    #[Test]
    public function aProviderWithoutAKeyIsRefusedBeforeAnyRequestAndNoOtherProviderIsTried(): void
    {
        $requests = 0;
        $client = new MockHttpClient(static function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse('{}');
        });
        $provider = new SymfonyAiProvider($client, '', self::OPERATOR_ANTHROPIC_KEY, self::OLLAMA_URL, self::TIMEOUT_SECONDS);

        try {
            $provider->complete($this->summaryPrompt(), ProviderChoice::fromSettings(null));
            self::fail('A provider without a key must refuse.');
        } catch (AiUnavailable $refused) {
            self::assertSame('The AI provider huggingface has no key or URL set.', $refused->getMessage());
            self::assertSame('huggingface', $refused->provider);
        }
        self::assertSame(0, $requests);
    }

    #[Test]
    public function anEmptyOrganizationKeyIsRefusedInsteadOfUsingTheOperatorsKey(): void
    {
        $provider = $this->provider([]);

        $this->expectException(AiUnavailable::class);
        $this->expectExceptionMessageIs('The AI provider anthropic has no key or URL set.');

        $provider->complete($this->summaryPrompt(), ProviderChoice::fromSettings('anthropic', null, ''));
    }

    #[Test]
    public function ollamaWithoutAnOperatorUrlIsRefused(): void
    {
        $provider = new SymfonyAiProvider(new MockHttpClient([]), self::OPERATOR_HF_TOKEN, self::OPERATOR_ANTHROPIC_KEY, '', self::TIMEOUT_SECONDS);

        $this->expectException(AiUnavailable::class);
        $this->expectExceptionMessageIs('The AI provider ollama has no key or URL set.');

        $provider->complete($this->summaryPrompt(), ProviderChoice::fromSettings('ollama', null, 'ollama-acme-key'));
    }

    #[Test]
    public function http429BecomesRateLimitedWithTheRetryAfterTheProviderSent(): void
    {
        $provider = $this->provider([
            new JsonMockResponse(['capabilities' => ['completion']]),
            new JsonMockResponse(['error' => self::LEAKED_TEXT], ['http_code' => 429, 'response_headers' => ['Retry-After' => '30']]),
        ]);

        try {
            $provider->complete($this->summaryPrompt(), ProviderChoice::fromSettings('ollama'));
            self::fail('A 429 must become AiRateLimited.');
        } catch (AiRateLimited $limited) {
            self::assertSame('ollama', $limited->provider);
            self::assertSame(30, $limited->retryAfterSeconds);
            self::assertStringNotContainsString(self::LEAKED_TEXT, $limited->getMessage());
            self::assertNull($limited->getPrevious());
        }
    }

    #[Test]
    public function http429WithoutRetryAfterBecomesRateLimitedWithNoWait(): void
    {
        $provider = $this->provider([new JsonMockResponse(['error' => ['message' => self::LEAKED_TEXT]], ['http_code' => 429])]);

        try {
            $provider->complete($this->summaryPrompt(), ProviderChoice::fromSettings(null));
            self::fail('A 429 must become AiRateLimited.');
        } catch (AiRateLimited $limited) {
            self::assertSame('huggingface', $limited->provider);
            self::assertNull($limited->retryAfterSeconds);
        }
    }

    #[Test]
    public function aServerErrorBecomesUnavailableWithoutTheProvidersMessage(): void
    {
        $provider = $this->provider([new JsonMockResponse(['error' => ['message' => self::LEAKED_TEXT]], ['http_code' => 503])]);

        try {
            $provider->complete($this->summaryPrompt(), ProviderChoice::fromSettings(null));
            self::fail('A 503 must become AiUnavailable.');
        } catch (AiUnavailable $unavailable) {
            self::assertSame('The AI provider huggingface did not answer (Symfony\AI\Platform\Exception\ServerException).', $unavailable->getMessage());
            self::assertNull($unavailable->getPrevious());
        }
    }

    #[Test]
    public function anUnreachableHostBecomesUnavailable(): void
    {
        $provider = $this->provider([new MockResponse('', ['error' => 'Could not resolve host'])]);

        $this->expectException(AiUnavailable::class);
        $this->expectExceptionMessageIsOrContains('The AI provider huggingface did not answer (');

        $provider->complete($this->summaryPrompt(), ProviderChoice::fromSettings(null));
    }

    #[Test]
    public function everyProviderCallIsBoundedByTheConfiguredTimeoutAndMaxDuration(): void
    {
        $sent = [];
        $provider = $this->provider([
            $this->recorded($sent, $this->huggingFaceAnswer()),
            $this->recorded($sent, new JsonMockResponse(['type' => 'message', 'content' => [['type' => 'text', 'text' => 'Short.']]])),
            $this->recorded($sent, new JsonMockResponse(['capabilities' => ['completion']])),
            $this->recorded($sent, new JsonMockResponse(['message' => ['role' => 'assistant', 'content' => 'Short.'], 'done' => true])),
        ]);

        $provider->complete($this->summaryPrompt(), ProviderChoice::fromSettings('huggingface'));
        $provider->complete($this->summaryPrompt(), ProviderChoice::fromSettings('anthropic'));
        $provider->complete($this->summaryPrompt(), ProviderChoice::fromSettings('ollama'));

        self::assertCount(4, $sent);
        foreach ($sent as $request) {
            self::assertEquals(self::TIMEOUT_SECONDS, $request['timeout'], $request['request']);
            self::assertEquals(self::TIMEOUT_SECONDS, $request['maxDuration'], $request['request']);
        }
    }

    #[Test]
    public function aProviderThatStopsSendingBecomesUnavailableInsteadOfHanging(): void
    {
        $provider = $this->provider([new MockResponse(['', '{"choices":'])]);

        try {
            $provider->complete($this->summaryPrompt(), ProviderChoice::fromSettings(null));
            self::fail('An idle timeout must become AiUnavailable.');
        } catch (AiUnavailable $unavailable) {
            self::assertStringStartsWith('The AI provider huggingface did not answer (', $unavailable->getMessage());
            self::assertSame('huggingface', $unavailable->provider);
        }
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function timeoutsOutOfRange(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-5];
        yield 'past the cap' => [301];
    }

    #[Test]
    #[DataProvider('timeoutsOutOfRange')]
    public function aTimeoutOutsideOneToThreeHundredSecondsIsRefused(int $timeoutSeconds): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('AI_PROVIDER_TIMEOUT_SECONDS must be from 1 to 300 seconds, not '.$timeoutSeconds.'.');

        new SymfonyAiProvider(new MockHttpClient([]), self::OPERATOR_HF_TOKEN, self::OPERATOR_ANTHROPIC_KEY, self::OLLAMA_URL, $timeoutSeconds);
    }

    #[Test]
    public function theTimeoutRangeIncludesBothEnds(): void
    {
        $sent = [];
        foreach ([1, 300] as $timeoutSeconds) {
            $provider = new SymfonyAiProvider(new MockHttpClient([$this->recorded($sent, $this->huggingFaceAnswer())]), self::OPERATOR_HF_TOKEN, self::OPERATOR_ANTHROPIC_KEY, self::OLLAMA_URL, $timeoutSeconds);
            $provider->complete($this->summaryPrompt(), ProviderChoice::fromSettings(null));
        }

        self::assertEquals([1, 300], array_column($sent, 'maxDuration'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blankAnswers(): iterable
    {
        yield 'empty' => [''];
        yield 'spaces and line breaks' => [" \r\n\t "];
        yield 'unicode spaces' => ["\u{00A0}\u{2028}\u{3000}\u{FEFF}"];
    }

    #[Test]
    #[DataProvider('blankAnswers')]
    public function aBlankAnswerBecomesUnavailable(string $blankText): void
    {
        $provider = $this->provider([new JsonMockResponse([
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $blankText], 'finish_reason' => 'stop']],
        ])]);

        try {
            $provider->complete($this->summaryPrompt(), ProviderChoice::fromSettings(null));
            self::fail('A blank answer must become AiUnavailable.');
        } catch (AiUnavailable $unavailable) {
            self::assertSame('The AI provider huggingface answered with no text.', $unavailable->getMessage());
            self::assertSame('huggingface', $unavailable->provider);
        }
    }

    #[Test]
    public function anAnswerWithTextAroundSpacesIsKeptAsSent(): void
    {
        $provider = $this->provider([new JsonMockResponse([
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => "\n Paying fails. \n"], 'finish_reason' => 'stop']],
        ])]);

        $answer = $provider->complete($this->summaryPrompt(), ProviderChoice::fromSettings(null));

        self::assertSame("\n Paying fails. \n", $answer->text);
    }

    #[Test]
    public function anEmptyOrganizationKeyForOllamaIsRefusedBeforeAnyRequest(): void
    {
        $requests = 0;
        $client = new MockHttpClient(static function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse('{}');
        });
        $provider = new SymfonyAiProvider($client, self::OPERATOR_HF_TOKEN, self::OPERATOR_ANTHROPIC_KEY, self::OLLAMA_URL, self::TIMEOUT_SECONDS);

        try {
            $provider->complete($this->summaryPrompt(), ProviderChoice::fromSettings('ollama', null, ''));
            self::fail('An empty organization key must refuse.');
        } catch (AiUnavailable $refused) {
            self::assertSame('The AI provider ollama has no key or URL set.', $refused->getMessage());
            self::assertSame('ollama', $refused->provider);
        }
        self::assertSame(0, $requests);
    }

    #[Test]
    public function aModelTheCatalogDoesNotKnowBecomesUnavailable(): void
    {
        $provider = $this->provider([]);

        $this->expectException(AiUnavailable::class);
        $this->expectExceptionMessageIs('The AI provider anthropic did not answer (Symfony\AI\Platform\Exception\ModelNotFoundException).');

        $provider->complete($this->summaryPrompt(), ProviderChoice::fromSettings('anthropic', 'no-such-model'));
    }

    /**
     * @param list<MockResponse|\Closure> $responses
     */
    private function provider(array $responses): SymfonyAiProvider
    {
        return new SymfonyAiProvider(new MockHttpClient($responses), self::OPERATOR_HF_TOKEN, self::OPERATOR_ANTHROPIC_KEY, self::OLLAMA_URL, self::TIMEOUT_SECONDS);
    }

    private function summaryPrompt(): AiPrompt
    {
        return new AiPrompt(AiFeature::Summary, 'Summarize the ticket.', 'The checkout button does nothing.');
    }

    private function huggingFaceAnswer(): MockResponse
    {
        return new JsonMockResponse([
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'Short.'], 'finish_reason' => 'stop']],
        ]);
    }

    /**
     * A response factory that keeps what was sent, for MockHttpClient.
     *
     * @param list<array{request: string, headers: list<string>, body: string, maxRedirects: mixed, timeout: mixed, maxDuration: mixed}> $sent
     */
    private function recorded(array &$sent, MockResponse $response): \Closure
    {
        return static function (string $method, string $url, array $options) use (&$sent, $response): MockResponse {
            $body = $options['body'] ?? '';
            $headers = $options['headers'] ?? [];
            $sent[] = [
                'request' => $method.' '.$url,
                'headers' => \is_array($headers) ? array_values(array_filter($headers, is_string(...))) : [],
                'body' => \is_string($body) ? $body : '',
                'maxRedirects' => $options['max_redirects'] ?? null,
                'timeout' => $options['timeout'] ?? null,
                'maxDuration' => $options['max_duration'] ?? null,
            ];

            return $response;
        };
    }
}
