<?php

declare(strict_types=1);

namespace App\Assist\Application;

use App\Organization\Api\ActorContext;

/**
 * The one door to server-side AI (ADR-0018): it applies the organization's AI policy, builds the prompt from a template,
 * calls the provider, validates and meters the answer, and labels it. It never writes anything a person did not click.
 */
interface AiGateway
{
    /**
     * @param ActorContext $viewer the person asking, in the organization whose policy and keys apply
     *
     * @throws AiNotAllowed  when the org policy forbids this feature for this person; nothing is sent
     * @throws AiRateLimited when the person, the organization or the provider made too many calls
     * @throws AiUnavailable when the provider is not configured, cannot be reached or fails
     */
    public function run(AiTask $task, ActorContext $viewer): AiResult;
}
