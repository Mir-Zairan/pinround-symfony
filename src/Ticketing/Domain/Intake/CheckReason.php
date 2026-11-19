<?php

declare(strict_types=1);

namespace App\Ticketing\Domain\Intake;

/**
 * Why a new ticket gets a quick look from its checker (FR-INT-07, report-a-problem.md, Check reasons). The value is what
 * tkt_ticket.check_reasons stores; the phrase is how the preview sentence and the toasts say it.
 */
enum CheckReason: string
{
    case Duplicate = 'dupe';
    case LittleInfo = 'info';
    case NoTeam = 'team';
    case Risky = 'risk';
    case CompanyRule = 'rule';

    public function phrase(): string
    {
        return match ($this) {
            self::Duplicate => 'maybe the same as an older ticket',
            self::LittleInfo => 'little detail and no pins',
            self::NoTeam => 'no team was picked',
            self::Risky => 'it sounds risky',
            self::CompanyRule => 'the company rule: every new ticket gets a lead check',
        };
    }
}
