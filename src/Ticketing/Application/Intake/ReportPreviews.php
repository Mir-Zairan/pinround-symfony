<?php

declare(strict_types=1);

namespace App\Ticketing\Application\Intake;

use App\Ticketing\Api\TicketVisibility;
use App\Ticketing\Domain\Intake\CheckReason;
use App\Ticketing\Domain\Intake\CheckReasons;
use App\Ticketing\Domain\Intake\DraftReport;
use App\Ticketing\Domain\Intake\ReportContext;

/**
 * The live preview of a report as it is typed (P06.T2, P06.T4): its possible duplicates, then the check reasons with
 * them, through the same CheckReasons the create uses, so the "When you send:" line never says what the create does
 * not do. One query (the duplicates), none for a short title.
 */
final readonly class ReportPreviews
{
    public function __construct(private DuplicateSuggestions $duplicateSuggestions, private CheckReasons $checkReasons)
    {
    }

    /**
     * @param list<string> $pinNotes
     */
    public function of(TicketVisibility $reporter, string $title, string $detail, array $pinNotes, bool $hasTeam): ReportPreviewFacts
    {
        $duplicates = $this->duplicateSuggestions->for($reporter, $title, $detail, $pinNotes);
        $draft = new DraftReport($title, $detail, $pinNotes);
        $reasons = $this->checkReasons->for($draft, new ReportContext(hasTeam: $hasTeam, pinCount: \count($pinNotes), wasDuplicateShown: [] !== $duplicates));

        return new ReportPreviewFacts(
            $duplicates,
            array_map(static fn (CheckReason $reason): string => $reason->value, $reasons),
            array_map(static fn (CheckReason $reason): string => $reason->phrase(), $reasons),
            $this->checkReasons->isSecuritySensitive($draft),
        );
    }
}
