<?php

declare(strict_types=1);

namespace App\Ticketing\UI;

use App\SharedKernel\Domain\UserId;
use App\Workflow\Api\NowNextContext;
use App\Workflow\Api\TeamFacts;
use App\Workflow\Api\TeamMemberLoad;
use App\Workflow\Api\TicketState;
use App\Workflow\Api\Turn;
use App\Workflow\Api\TurnPeopleChoice;

/**
 * The ticket page's People section (FR-TKT-11; ticket-page.md, People): the assignee, the reporter, the code reviewer
 * while the ticket is In progress or in Code review, and the team's tester while the ticket is open. A reviewer or
 * tester not stored yet is the one the move into Code review or Testing would store, so the list names who the turn
 * will go to. Reads no database: the names, the team and the turn come with the page.
 *
 * The prototype leaves the tester out in Backlog; FR-TKT-11 lists them on every open ticket, and the requirement wins.
 * One row per person: the prototype shows the reporter only "when different from the assignee", and its sample names a
 * reporter who tests once, as the tester, so the reporter's row is dropped for anyone with another role on the ticket.
 */
final readonly class TicketPeople
{
    public const string TESTER_FOR_THE_TEAM = 'Tester for the team';

    /** Core codes of the steps where the code reviewer is listed. */
    private const array REVIEWER_CORE_CODES = ['progress', 'review'];

    /** Workflow's group code of the closed steps, where no tester is listed. */
    private const string DONE_GROUP = 'done';

    private const string JOB_LEAD = 'Lead';
    private const string JOB_DEVELOPER = 'Developer';
    private const string JOB_TESTER = 'Tester';

    public function __construct(
        private TurnPeopleChoice $turnPeopleChoice,
    ) {
    }

    /**
     * @param array<string, string> $displayNames display names by user id string; a person without one is named
     *                                            "An unknown person"
     * @param TeamFacts|null        $team         null for a ticket no team owns, which lists only its stored people
     * @param Turn|null             $turn         null for a ticket no team owns, which has no turn
     *
     * @return list<TicketPerson> in the order of each person's first role: assignee, reporter, reviewer, tester
     */
    public function of(TicketState $ticket, array $displayNames, ?TeamFacts $team, ?Turn $turn, UserId $viewerId): array
    {
        $rolesByUser = [];
        foreach ([
            NextStepChoices::ROLE_ASSIGNEE => $ticket->assigneeId,
            NextStepChoices::ROLE_REPORTER => $ticket->reporterId,
            NextStepChoices::ROLE_REVIEWER => $this->reviewerOf($ticket, $team),
            NextStepChoices::ROLE_TESTER => $this->testerOf($ticket, $team),
        ] as $role => $userId) {
            if (null !== $userId) {
                $rolesByUser[$userId->toString()][] = $role;
            }
        }

        $people = [];
        foreach ($rolesByUser as $userKey => $roles) {
            $userId = new UserId($userKey);
            $name = trim($displayNames[$userKey] ?? '');
            $firstName = NowNextContext::firstNameFrom($name);
            if (null === $firstName) {
                $name = $firstName = ucfirst(PersonNames::UNKNOWN_PERSON);
            }
            $people[] = new TicketPerson(
                $userKey,
                $name,
                $firstName,
                self::roleLineOf($roles, null === $team ? null : self::jobOf($team, $userId)),
                $userId->equals($viewerId),
                null !== $turn?->userId && $turn->userId->equals($userId),
            );
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

    /**
     * @param non-empty-list<string> $roles
     */
    private static function roleLineOf(array $roles, ?string $job): string
    {
        if (\count($roles) > 1) {
            $roles = array_values(array_diff($roles, [NextStepChoices::ROLE_REPORTER]));
        }
        $isTester = \in_array(NextStepChoices::ROLE_TESTER, $roles, true);
        $words = array_map(
            static fn (string $role): string => NextStepChoices::ROLE_TESTER === $role ? self::TESTER_FOR_THE_TEAM : $role,
            $roles,
        );
        if (null !== $job && !$isTester) {
            $words[] = $job;
        }

        return implode(' · ', $words);
    }

    /**
     * @return string|null the person's job in the team, or null for someone outside it, such as a reporter from support
     */
    private static function jobOf(TeamFacts $team, UserId $userId): ?string
    {
        $isUser = static fn (TeamMemberLoad $member): bool => $member->userId->equals($userId);

        return match (true) {
            $team->leadId->equals($userId) => self::JOB_LEAD,
            array_any($team->developers, $isUser) => self::JOB_DEVELOPER,
            array_any($team->testers, $isUser) => self::JOB_TESTER,
            default => null,
        };
    }
}
