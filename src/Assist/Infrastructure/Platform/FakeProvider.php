<?php

declare(strict_types=1);

namespace App\Assist\Infrastructure\Platform;

use App\Assist\Application\AiPrompt;
use App\Assist\Application\AiProvider;
use App\Assist\Application\AiRateLimited;
use App\Assist\Application\AiUnavailable;
use App\Assist\Application\ProviderAnswer;
use App\Assist\Application\ProviderChoice;
use App\Assist\Domain\AiFeature;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The provider of the test env (config/services.yaml, when@test): the same answer for the same feature, and no network.
 * Like SymfonyAiProvider it answers with the chosen model, refuses an empty organization key with AiUnavailable, and
 * throws AiRateLimited or AiUnavailable when a test arms a failure. It keeps every call, failed ones too, so a test can
 * check what was sent; reset() forgets them.
 */
final class FakeProvider implements AiProvider, ResetInterface
{
    public const string PROVIDER = 'fake';

    /** @var list<array{prompt: AiPrompt, choice: ProviderChoice}> */
    private array $calls = [];

    /** @var list<AiRateLimited|AiUnavailable> */
    private array $armedFailures = [];

    public function complete(AiPrompt $prompt, ProviderChoice $choice): ProviderAnswer
    {
        $this->calls[] = ['prompt' => $prompt, 'choice' => $choice];

        if ('' === $choice->organizationKey()) {
            throw AiUnavailable::notConfigured($choice->provider->value);
        }

        $failure = array_shift($this->armedFailures);
        if (null !== $failure) {
            throw $failure;
        }

        $text = self::fixedTextFor($prompt->feature);

        return new ProviderAnswer(
            $text,
            $choice->model,
            self::PROVIDER,
            self::tokensIn($prompt->instructions.$prompt->content),
            self::tokensIn($text),
            0,
        );
    }

    /**
     * The next call throws this failure instead of answering; arming several fails that many calls in order.
     */
    public function failNextCallWith(AiRateLimited|AiUnavailable $failure): void
    {
        $this->armedFailures[] = $failure;
    }

    /**
     * @return list<array{prompt: AiPrompt, choice: ProviderChoice}> oldest first
     */
    public function calls(): array
    {
        return $this->calls;
    }

    public function reset(): void
    {
        $this->calls = [];
        $this->armedFailures = [];
    }

    public static function fixedTextFor(AiFeature $feature): string
    {
        return match ($feature) {
            AiFeature::Summary => 'Fake summary: the checkout button does nothing on the last step.',
            AiFeature::Explain => 'Fake explanation: people cannot pay, because the last checkout step does not respond.',
            AiFeature::Intake => '{}',
        };
    }

    /**
     * About four bytes per token, like the providers' own rough rule, so metering sees counts that grow with the text.
     */
    private static function tokensIn(string $text): int
    {
        return intdiv(\strlen($text) + 3, 4);
    }
}
