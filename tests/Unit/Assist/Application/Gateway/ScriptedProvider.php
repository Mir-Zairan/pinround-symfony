<?php

declare(strict_types=1);

namespace App\Tests\Unit\Assist\Application\Gateway;

use App\Assist\Application\AiPrompt;
use App\Assist\Application\AiProvider;
use App\Assist\Application\AiRateLimited;
use App\Assist\Application\AiUnavailable;
use App\Assist\Application\ProviderAnswer;
use App\Assist\Application\ProviderChoice;

/**
 * A provider that answers each call with the next scripted text or throws the next scripted failure, as the real one
 * does: it names the chosen provider and model, refuses an empty organization key, and keeps every call.
 */
final class ScriptedProvider implements AiProvider
{
    /** @var list<array{prompt: AiPrompt, choice: ProviderChoice}> */
    public array $calls = [];

    /**
     * @param list<string|AiRateLimited|AiUnavailable> $script
     */
    public function __construct(private array $script)
    {
    }

    public function complete(AiPrompt $prompt, ProviderChoice $choice): ProviderAnswer
    {
        $this->calls[] = ['prompt' => $prompt, 'choice' => $choice];
        if ('' === $choice->organizationKey()) {
            throw AiUnavailable::notConfigured($choice->provider->value);
        }

        $next = array_shift($this->script) ?? throw new \LogicException('The provider was called more often than scripted.');
        if (!\is_string($next)) {
            throw $next;
        }

        return new ProviderAnswer($next, $choice->model, $choice->provider->value, 812, 64, 1350);
    }
}
