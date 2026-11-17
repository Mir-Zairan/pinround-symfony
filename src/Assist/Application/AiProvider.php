<?php

declare(strict_types=1);

namespace App\Assist\Application;

/**
 * Sends one prompt to one model. Only the gateway calls it, after the policy check; it applies no policy itself.
 */
interface AiProvider
{
    /**
     * Waits for the whole answer; call it from a Messenger worker, never in a web request.
     *
     * @throws AiRateLimited when the provider answered 429
     * @throws AiUnavailable when the chosen provider has no key or URL, cannot be reached, fails, or answers no text
     */
    public function complete(AiPrompt $prompt, ProviderChoice $choice): ProviderAnswer;
}
