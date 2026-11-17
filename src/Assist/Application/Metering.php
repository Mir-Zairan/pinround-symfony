<?php

declare(strict_types=1);

namespace App\Assist\Application;

use App\Assist\Domain\AiFeature;
use App\Organization\Api\ActorContext;

/**
 * Counts server-side AI calls in ast_usage (ai-architecture.md › Cost, rate limits and metering): who called, for which
 * feature, which provider and model answered, the tokens and the time. Never the prompt or the answer.
 */
interface Metering
{
    /**
     * Writes exactly one usage row at the clock's now. Token counts the provider did not report stay unknown, never 0.
     * Commits its own transaction when none is open; inside one, the row is kept or rolled back with it.
     *
     * @param ActorContext $caller the person who asked, in the organization of the transaction's tenant
     *
     * @throws \App\SharedKernel\Application\TenantNotSet when no tenant is set; nothing is written
     * @throws \LogicException                            when the caller's organization is not the tenant; nothing is written
     */
    public function record(ProviderAnswer $answer, AiFeature $feature, ActorContext $caller): void;
}
