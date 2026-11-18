<?php

declare(strict_types=1);

namespace App\Ticketing\Application\Intake;

use App\Ticketing\Api\DraftReportChecks;
use App\Ticketing\Domain\Intake\CheckReasons;
use App\Ticketing\Domain\Intake\DraftReport;

final readonly class WordListDraftReportChecks implements DraftReportChecks
{
    public function __construct(private CheckReasons $checkReasons)
    {
    }

    public function isSecuritySensitive(string $title, string $detail, array $pinNotes): bool
    {
        return $this->checkReasons->isSecuritySensitive(new DraftReport($title, $detail, $pinNotes));
    }
}
