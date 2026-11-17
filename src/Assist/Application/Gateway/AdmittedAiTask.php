<?php

declare(strict_types=1);

namespace App\Assist\Application\Gateway;

use App\Assist\Application\Policy\PolicyDecision;
use App\Organization\Api\AiSettings;

/**
 * An AI task the organization's policy lets the viewer send: the settings it was decided on, the ticket as the viewer
 * may see it, and the decision with its route and "what goes to the AI" filter.
 */
final readonly class AdmittedAiTask
{
    public function __construct(
        public AiSettings $settings,
        public AskedTicket $ticket,
        public PolicyDecision $decision,
    ) {
    }
}
