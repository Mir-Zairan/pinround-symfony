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
    /** A laptop AI package was asked for, and the organization runs AI in the cloud only. */
    case CloudOnly = 'cloud_only';
    /** A draft report in an organization that runs AI on laptops only: laptop intake is not built yet. */
    case IntakeNotOnLaptop = 'intake_not_on_laptop';

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
            self::LaptopNotReady => 'Your company runs AI on each laptop, and it is not running on yours. Use “Local AI” at the top to set it up.',
            self::SecuritySensitiveDraftToCloud => 'This report mentions security, so it stays out of cloud AI.',
            self::CloudOnly => 'Your admin runs AI help in the company cloud, not on laptops.',
            self::IntakeNotOnLaptop => 'Your company runs AI on laptops, and Check with AI does not run there yet.',
        };
    }

    /**
     * The refusal a draft report gets for this one: LaptopNotReady's setup advice would not help a draft, as no laptop
     * checks one yet.
     */
    public function forDraft(): self
    {
        return self::LaptopNotReady === $this ? self::IntakeNotOnLaptop : $this;
    }
}
