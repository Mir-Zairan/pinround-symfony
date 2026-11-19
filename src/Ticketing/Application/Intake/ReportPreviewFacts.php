<?php

declare(strict_types=1);

namespace App\Ticketing\Application\Intake;

use App\Ticketing\Api\IntakeCandidate;

/**
 * What Report a problem's live preview shows and the create then does (P06.T2 to P06.T4): the possible duplicates, the
 * check reasons and whether the words make the ticket team-only.
 */
final readonly class ReportPreviewFacts
{
    /**
     * @param list<IntakeCandidate> $duplicates    at most three, the most shared words first
     * @param list<string>          $checkReasons  CheckReason values, in the order the sentence names them
     * @param list<string>          $reasonPhrases the reasons as the sentence says them, in the same order
     */
    public function __construct(
        public array $duplicates,
        public array $checkReasons,
        public array $reasonPhrases,
        public bool $isTeamOnly,
    ) {
    }
}
