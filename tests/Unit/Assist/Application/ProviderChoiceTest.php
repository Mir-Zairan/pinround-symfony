<?php

declare(strict_types=1);

namespace App\Tests\Unit\Assist\Application;

use App\Assist\Application\AiProviderName;
use App\Assist\Application\ProviderChoice;
use App\Assist\Application\UnknownAiProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProviderChoiceTest extends TestCase
{
    #[Test]
    public function nothingSetChoosesHuggingFaceWithItsDefaultModelAndTheOperatorsKey(): void
    {
        $choice = ProviderChoice::fromSettings(null);

        self::assertSame(AiProviderName::HuggingFace, $choice->provider);
        self::assertSame('meta-llama/Llama-3.1-8B-Instruct', $choice->model);
        self::assertNull($choice->organizationKey());
    }

    #[Test]
    public function anthropicChosenGivesAnthropicWithItsDefaultModel(): void
    {
        $choice = ProviderChoice::fromSettings('anthropic');

        self::assertSame(AiProviderName::Anthropic, $choice->provider);
        self::assertSame('claude-haiku-4-5', $choice->model);
    }

    #[Test]
    public function ollamaChosenGivesOllamaWithItsDefaultModel(): void
    {
        $choice = ProviderChoice::fromSettings('ollama');

        self::assertSame(AiProviderName::Ollama, $choice->provider);
        self::assertSame('qwen3:4b', $choice->model);
    }

    #[Test]
    public function aChosenModelAndTheOrganizationsKeyAreKept(): void
    {
        $choice = ProviderChoice::fromSettings('anthropic', 'claude-sonnet-4-5', 'org-key-acme');

        self::assertSame(AiProviderName::Anthropic, $choice->provider);
        self::assertSame('claude-sonnet-4-5', $choice->model);
        self::assertSame('org-key-acme', $choice->organizationKey());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownProviders(): iterable
    {
        yield 'another vendor' => ['openai'];
        yield 'empty string' => [''];
        yield 'other case' => ['HuggingFace'];
        yield 'trailing newline' => ["ollama\n"];
    }

    #[Test]
    #[DataProvider('unknownProviders')]
    public function anUnknownProviderIsRefusedInsteadOfFallingBackToTheDefault(string $provider): void
    {
        $this->expectException(UnknownAiProvider::class);
        $this->expectExceptionMessageIs('The AI settings name a provider that is not huggingface, anthropic or ollama.');

        ProviderChoice::fromSettings($provider);
    }

    #[Test]
    public function anEmptyModelIsRefusedInsteadOfUsingTheDefault(): void
    {
        $this->expectException(UnknownAiProvider::class);
        $this->expectExceptionMessageIs('The AI settings name an empty model.');

        ProviderChoice::fromSettings('ollama', '');
    }
}
