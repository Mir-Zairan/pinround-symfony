<?php

declare(strict_types=1);

namespace App\Ticketing\Application;

use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketPagePerson;
use App\Ticketing\Api\TicketRole;
use App\Workflow\Api\TeamFacts;
use App\Workflow\Api\TicketState;
use App\Workflow\Api\TurnPeopleChoice;

/**
 * Who the ticket page's People section lists and in which roles (FR-TKT-11; ticket-page.md, People): the assignee, the
 * reporter, the code reviewer while the ticket is In progress or in Code review, and the team's tester while the
 * ticket is open. A reviewer or tester not stored yet is the one the move into Code review or Testing would store, so
 * the list names who the turn will go to; with no developer to review, the lead reviews. Reads no database.
 *
 * The prototype leaves the tester out in Backlog; FR-TKT-11 lists them on every open ticket, and the requirement wins.
 */
final readonly class TicketPeopleRoles
{
    /** Core codes of the steps where the code reviewer is listed. */
    private const array REVIEWER_CORE_CODES = ['progress', 'review'];

    /** Workflow's group code of the closed steps, where no tester is listed. */
    private const string DONE_GROUP = 'done';

    public function __construct(
        private TurnPeopleChoice $turnPeopleChoice,
    ) {
    }

    /**
     * @param TeamFacts|null $team null for a ticket no team owns, which lists only its stored people
     *
     * @return list<TicketPagePerson> each person once, in the order of their first role: assignee, reporter, reviewer,
     *                                tester
     */
    public function of(TicketState $ticket, ?TeamFacts $team): array
    {
        /** @var array<string, non-empty-list<TicketRole>> $rolesByUser */
        $rolesByUser = [];
        foreach ([
            [TicketRole::Assignee, $ticket->assigneeId],
            [TicketRole::Reporter, $ticket->reporterId],
            [TicketRole::Reviewer, $this->reviewerOf($ticket, $team)],
            [TicketRole::Tester, $this->testerOf($ticket, $team)],
        ] as [$role, $userId]) {
            if (null !== $userId) {
                $rolesByUser[$userId->toString()][] = $role;
            }
        }

        $people = [];
        foreach ($rolesByUser as $userKey => $roles) {
            $people[] = new TicketPagePerson(new UserId($userKey), $roles);
        }

        return $people;
    }

    private function reviewerOf(TicketState $ticket, ?TeamFacts $team): ?UserId
    {
        if (!\in_array($ticket->coreCode, self::REVIEWER_CORE_CODES, true)) {
            return null;
        }
        if (null !== $ticket->reviewerId || null === $team) {
            return $ticket->reviewerId;
        }

        return $this->turnPeopleChoice->reviewerToStore($ticket->assigneeId, $team) ?? $team->leadId;
    }

    private function testerOf(TicketState $ticket, ?TeamFacts $team): ?UserId
    {
        if (self::DONE_GROUP === $ticket->groupCode) {
            return null;
        }
        if (null !== $ticket->testerId || null === $team) {
            return $ticket->testerId;
        }

        return $this->turnPeopleChoice->testerToStore($team);
    }
}
