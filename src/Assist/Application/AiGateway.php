<?php

declare(strict_types=1);

namespace App\Assist\Application;

use App\Organization\Api\ActorContext;

/**
 * The one door to server-side AI (ADR-0018): it applies the organization's AI policy, builds the prompt from a template,
 * calls the provider, validates and meters the answer, and labels it. It never writes anything a person did not click.
 *
 * Once the provider was asked, run() throws nothing: Metering writes the usage row in the caller's transaction, so a
 * rejected or failed call comes back as an AiResult the caller stores, and the paid call stays metered.
 */
interface AiGateway
{
    /**
     * Waits for the provider; call it from a Messenger worker, in a transaction whose tenant is the viewer's organization.
     *
     * @param ActorContext $viewer the person asking, in the organization whose policy and keys apply
     *
     * @return AiResult answered, or rejected or failed when the provider was asked but gave nothing usable
     *
     * @throws AiNotAllowed              when the org policy forbids this feature for this person or ticket; nothing is sent
     * @throws AiRateLimited             when the person made too many calls (AiCallLimit); nothing is sent
     * @throws AiUnavailable             when the organization's own provider key cannot be read; nothing is sent, and the
     *                                   operator's key is never used instead
     * @throws UnknownAiProvider         when the settings name a provider or model pinround cannot use; nothing is sent
     * @throws UnknownPromptTemplate     when the feature has no prompt template yet; nothing is sent
     * @throws \InvalidArgumentException when the task names no ticket; nothing is sent
     */
    public function run(AiTask $task, ActorContext $viewer): AiResult;
}
