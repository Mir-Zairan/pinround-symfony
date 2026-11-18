<?php

declare(strict_types=1);

namespace App\Assist\Application\Gateway;

use App\Assist\Application\PromptTicket;

/**
 * The ticket an AI task is about, as the asking person may see it: what may go into the prompt, and whether it is
 * team-only, which keeps it from cloud AI unless the admin allows it.
 */
final readonly class AskedTicket
{
    public function __construct(
        public PromptTicket $promptTicket,
        public bool $isTeamOnly,
    ) {
    }
}
