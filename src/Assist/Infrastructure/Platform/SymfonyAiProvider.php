<?php

declare(strict_types=1);

namespace App\Assist\Infrastructure\Platform;

use App\Assist\Application\AiPrompt;
use App\Assist\Application\AiProvider;
use App\Assist\Application\AiProviderName;
use App\Assist\Application\AiRateLimited;
use App\Assist\Application\AiUnavailable;
use App\Assist\Application\ProviderAnswer;
use App\Assist\Application\ProviderChoice;
use Symfony\AI\Platform\Bridge\Anthropic\Factory as AnthropicFactory;
use Symfony\AI\Platform\Bridge\HuggingFace\Factory as HuggingFaceFactory;
use Symfony\AI\Platform\Bridge\Ollama\Factory as OllamaFactory;
use Symfony\AI\Platform\Exception\ExceptionInterface as PlatformFailure;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpFailure;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Calls the chosen provider through its Symfony AI bridge (Hugging Face, Anthropic or Ollama) with the organization's
 * key, or the operator's when the organization has none. Every request follows no redirects and gives up after
 * $timeoutSeconds, both waiting for the next byte and for the whole answer. It throws only AiRateLimited and
 * AiUnavailable, never chaining the library's exception, and logs nothing (ADR-0027).
 */
final readonly class SymfonyAiProvider implements AiProvider
{
    private const int MAX_TIMEOUT_SECONDS = 300;

    private HttpClientInterface $httpClient;

    /**
     * @param string $huggingFaceToken the operator's token, used when the organization has no key; empty turns it off
     * @param string $anthropicKey     the operator's key, used when the organization has no key; empty turns it off
     * @param string $ollamaUrl        the operator-run Ollama server; never a tenant setting (SSRF)
     * @param int    $timeoutSeconds   from 1 to 300
     *
     * @throws \InvalidArgumentException when $timeoutSeconds is outside 1 to 300
     */
    public function __construct(
        HttpClientInterface $httpClient,
        #[Autowire(env: 'AI_HUGGINGFACE_TOKEN')]
        #[\SensitiveParameter]
        private string $huggingFaceToken,
        #[Autowire(env: 'AI_ANTHROPIC_KEY')]
        #[\SensitiveParameter]
        private string $anthropicKey,
        #[Autowire(env: 'AI_OLLAMA_URL')]
        private string $ollamaUrl,
        #[Autowire(env: 'int:default:assist.ai_provider_timeout_default_seconds:AI_PROVIDER_TIMEOUT_SECONDS')]
        int $timeoutSeconds,
    ) {
        if ($timeoutSeconds < 1 || $timeoutSeconds > self::MAX_TIMEOUT_SECONDS) {
            throw new \InvalidArgumentException(\sprintf('AI_PROVIDER_TIMEOUT_SECONDS must be from 1 to %d seconds, not %d.', self::MAX_TIMEOUT_SECONDS, $timeoutSeconds));
        }

        // ai-architecture.md, providers: outbound AI calls follow no redirects.
        $this->httpClient = $httpClient->withOptions([
            'max_redirects' => 0,
            'timeout' => (float) $timeoutSeconds,
            'max_duration' => (float) $timeoutSeconds,
        ]);
    }

    public function complete(AiPrompt $prompt, ProviderChoice $choice): ProviderAnswer
    {
        $startedAt = hrtime(true);

        try {
            $modelAnswer = $this->platformFor($choice)->invoke($choice->model, new MessageBag(Message::forSystem($prompt->instructions), Message::ofUser($prompt->content)));
            $text = $modelAnswer->asText();
            $usage = $modelAnswer->getMetadata()->get('token_usage');
        } catch (RateLimitExceededException $limited) {
            throw new AiRateLimited($choice->provider->value, $limited->getRetryAfter());
        } catch (PlatformFailure|HttpFailure $failure) {
            throw AiUnavailable::failed($choice->provider->value, $failure::class);
        }

        if (self::isBlank($text)) {
            throw AiUnavailable::emptyAnswer($choice->provider->value);
        }

        $tokenUsage = $usage instanceof TokenUsageInterface ? $usage : null;

        return new ProviderAnswer(
            $text,
            $tokenUsage?->getModel() ?? $choice->model,
            $choice->provider->value,
            $tokenUsage?->getPromptTokens(),
            $tokenUsage?->getCompletionTokens(),
            intdiv(hrtime(true) - $startedAt, 1_000_000),
        );
    }

    /**
     * @throws AiUnavailable when the chosen provider has no key or URL, or the organization's key is empty; no other
     *                       provider and not the operator's key is tried
     */
    private function platformFor(ProviderChoice $choice): PlatformInterface
    {
        $organizationKey = $choice->organizationKey();
        if ('' === $organizationKey) {
            throw AiUnavailable::notConfigured($choice->provider->value);
        }

        return match ($choice->provider) {
            AiProviderName::HuggingFace => HuggingFaceFactory::createPlatform($this->configuredOrRefuse($choice, $organizationKey ?? $this->huggingFaceToken), httpClient: $this->httpClient),
            AiProviderName::Anthropic => AnthropicFactory::createPlatform($this->configuredOrRefuse($choice, $organizationKey ?? $this->anthropicKey), httpClient: $this->httpClient),
            AiProviderName::Ollama => OllamaFactory::createPlatform($this->configuredOrRefuse($choice, $this->ollamaUrl), $organizationKey, $this->httpClient),
        };
    }

    private function configuredOrRefuse(ProviderChoice $choice, #[\SensitiveParameter] string $keyOrUrl): string
    {
        if ('' === $keyOrUrl) {
            throw AiUnavailable::notConfigured($choice->provider->value);
        }

        return $keyOrUrl;
    }

    /**
     * Unicode spaces, line separators and the zero-width space and BOM count as blank too: a model can answer with
     * them alone. Text that is not valid UTF-8 is not blank. FakeProvider refuses the same answers with this check.
     */
    public static function isBlank(string $text): bool
    {
        return 1 === preg_match('/\A[\s\p{Z}\x{200B}\x{FEFF}]*\z/u', $text);
    }
}
