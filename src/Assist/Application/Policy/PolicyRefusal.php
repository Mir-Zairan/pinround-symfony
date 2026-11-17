<?php

declare(strict_types=1);

namespace App\Assist\Application\Policy;

/**
 * Why the organization's AI policy gives a person no AI help. The value is the reason code PolicyView carries.
 */
enum PolicyRefusal: string
{
    case AiOff = 'ai_off';
    case LeadsOnly = 'leads_only';
    case CannotSeeTicket = 'cannot_see_ticket';
    case RestrictedTicketToCloud = 'restricted_ticket_to_cloud';
    case LaptopNotReady = 'laptop_not_ready';
    case SecuritySensitiveDraftToCloud = 'security_sensitive_draft_to_cloud';

    /**
     * The one line shown where the AI buttons would be.
     */
    public function sentence(): string
    {
        return match ($this) {
            self::AiOff => 'Your admin turned AI help off.',
            self::LeadsOnly => 'Your admin allows AI help for leads only.',
            self::CannotSeeTicket => 'AI help works only on tickets you can see.',
            self::RestrictedTicketToCloud => 'Your admin keeps team-only tickets away from cloud AI.',
            self::LaptopNotReady => 'AI help runs on your laptop, and Ollama is not ready there yet.',
            self::SecuritySensitiveDraftToCloud => 'This report mentions security, so it stays out of cloud AI.',
        };
    }
}
