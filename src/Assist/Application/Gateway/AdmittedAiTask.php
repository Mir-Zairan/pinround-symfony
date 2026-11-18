<?php

declare(strict_types=1);

namespace App\Assist\Application\Gateway;

use App\Assist\Application\Policy\PolicyDecision;
use App\Assist\Application\PromptTicket;
use App\Organization\Api\AiSettings;

/**
 * An AI task the organization's policy lets the viewer send: the settings it was decided on, the ticket or draft report
 * as the viewer may see it, the decision with its route and "what goes to the AI" filter, and for a draft the open
 * tickets it may duplicate.
 */
final readonly class AdmittedAiTask
{
    /**
     * @param array<string, string> $duplicateCandidates titles by ticket key, newest updated first, at most 80; empty
     *                                                   for a ticket task
     */
    public function __construct(
        public AiSettings $settings,
        public PromptTicket $promptTicket,
        public PolicyDecision $decision,
        public array $duplicateCandidates = [],
    ) {
    }
}
