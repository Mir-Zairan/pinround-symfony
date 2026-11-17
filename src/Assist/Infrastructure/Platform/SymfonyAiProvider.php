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
 * The only class that uses Symfony AI (deptrac.yaml, layer Assist_AiPlatform): Symfony AI is beta, so a change in it
 * stays here. It builds the chosen provider's platform for each call, because the key can be the organization's own.
 * It logs nothing: prompts and answers never reach a log (ADR-0027).
 */
final readonly class SymfonyAiProvider implements AiProvider
{
    private HttpClientInterface $httpClient;

    /**
     * @param string $huggingFaceToken the operator's token, used when the organization has no key; empty turns it off
     * @param string $anthropicKey     the operator's key, used when the organization has no key; empty turns it off
     * @param string $ollamaUrl        the operator-run Ollama server; never a tenant setting (SSRF)
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
    ) {
        // ai-architecture.md, providers: outbound AI calls follow no redirects.
        $this->httpClient = $httpClient->withOptions(['max_redirects' => 0]);
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
     * @throws AiUnavailable when the chosen provider has no key or URL; no other provider is tried
     */
    private function platformFor(ProviderChoice $choice): PlatformInterface
    {
        $organizationKey = $choice->organizationKey();

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
}
