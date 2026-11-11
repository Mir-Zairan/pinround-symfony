<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;

/**
 * What the ticket visibility predicate reads of one ticket: its organization, its visibility, its team and the six
 * people on it, as tkt_ticket and ins_ticket_turn hold them.
 */
final readonly class TicketVisibilityFacts
{
    /** The values of Domain\Visibility, which this Api may not depend on. */
    public const string FOR_EVERYONE = 'all';
    public const string TEAM_ONLY = 'team';

    /**
     * @param string $visibility all, or team for a restricted ticket
     *
     * @throws \InvalidArgumentException when the visibility is not all or team
     */
    public function __construct(
        public OrganizationId $organizationId,
        public string $visibility,
        public ?TeamId $teamId,
        public UserId $reporterId,
        public ?UserId $assigneeId = null,
        public ?UserId $pendingAssigneeId = null,
        public ?UserId $reviewerId = null,
        public ?UserId $testerId = null,
        public ?UserId $waitingOnUserId = null,
    ) {
        if (!\in_array($visibility, [self::FOR_EVERYONE, self::TEAM_ONLY], true)) {
            throw new \InvalidArgumentException('A ticket visibility must be all or team.');
        }
    }

    /**
     * @return list<UserId> the people on the ticket, without the empty columns
     */
    public function involvedUserIds(): array
    {
        return array_values(array_filter([
            $this->reporterId,
            $this->assigneeId,
            $this->pendingAssigneeId,
            $this->reviewerId,
            $this->testerId,
            $this->waitingOnUserId,
        ]));
    }
}
