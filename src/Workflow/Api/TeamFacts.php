<?php

declare(strict_types=1);

namespace App\Workflow\Api;

use App\SharedKernel\Domain\UserId;

/**
 * What the turn rules need to know about the ticket's team; the caller reads it (the counts once per request).
 */
final readonly class TeamFacts
{
    /**
     * @param string               $leadDisplayName the name the override note uses, for example "Lena"
     * @param list<TeamMemberLoad> $developers      the team's developers; the lead may be among them
     * @param list<TeamMemberLoad> $testers         the team's testers
     */
    public function __construct(
        public UserId $leadId,
        public string $leadDisplayName,
        public array $developers = [],
        public array $testers = [],
    ) {
    }
}
