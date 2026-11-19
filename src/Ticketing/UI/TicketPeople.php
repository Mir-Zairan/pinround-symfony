<?php

declare(strict_types=1);

namespace App\Ticketing\UI;

use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketRole;
use App\Ticketing\Application\TicketPeopleRoles;
use App\Workflow\Api\NowNextContext;
use App\Workflow\Api\TeamFacts;
use App\Workflow\Api\TeamMemberLoad;
use App\Workflow\Api\TicketState;
use App\Workflow\Api\Turn;

/**
 * The rows of the ticket page's People section (FR-TKT-11; ticket-page.md, People): who is listed in which roles is
 * {@see TicketPeopleRoles}' rule; this words each row with the person's job in the team, their turn and whether they
 * are the viewer. Reads no database: the names, the team and the turn come with the page.
 *
 * One row per person: the prototype shows the reporter only "when different from the assignee", and its sample names a
 * reporter who tests once, as the tester, so the reporter's row is dropped for anyone with another role on the ticket.
 */
final readonly class TicketPeople
{
    public const string TESTER_FOR_THE_TEAM = 'Tester for the team';

    private const string JOB_LEAD = 'Lead';
    private const string JOB_DEVELOPER = 'Developer';
    private const string JOB_TESTER = 'Tester';

    public function __construct(
        private TicketPeopleRoles $ticketPeopleRoles,
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
        $people = [];
        foreach ($this->ticketPeopleRoles->of($ticket, $team) as $person) {
            $userId = $person->userId;
            $userKey = $userId->toString();
            $name = trim($displayNames[$userKey] ?? '');
            $firstName = NowNextContext::firstNameFrom($name);
            if (null === $firstName) {
                $name = $firstName = ucfirst(PersonNames::UNKNOWN_PERSON);
            }
            $people[] = new TicketPerson(
                $userKey,
                $name,
                $firstName,
                self::roleLineOf($person->roles, null === $team ? null : self::jobOf($team, $userId)),
                $userId->equals($viewerId),
                null !== $turn?->userId && $turn->userId->equals($userId),
            );
        }

        return $people;
    }

    /**
     * @param non-empty-list<TicketRole> $roles
     */
    private static function roleLineOf(array $roles, ?string $job): string
    {
        if (\count($roles) > 1) {
            $roles = array_values(array_filter($roles, static fn (TicketRole $role): bool => TicketRole::Reporter !== $role));
        }
        $words = array_map(static fn (TicketRole $role): string => match ($role) {
            TicketRole::Assignee => NextStepChoices::ROLE_ASSIGNEE,
            TicketRole::Reporter => NextStepChoices::ROLE_REPORTER,
            TicketRole::Reviewer => NextStepChoices::ROLE_REVIEWER,
            TicketRole::Tester => self::TESTER_FOR_THE_TEAM,
        }, $roles);
        if (null !== $job && !\in_array(TicketRole::Tester, $roles, true)) {
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
