<?php

declare(strict_types=1);

namespace App\Ticketing\UI;

use App\Organization\Api\MemberJob;
use App\Organization\Api\PossibleAssignee;

/**
 * Who on a team Report a problem names: its lead, who checks a report, and the people a report can be given to (every
 * lead or member but a tester). The form's choices and the live preview both read a team's cached people through here,
 * so the two cannot name different people.
 */
final class ReportTeamPeople
{
    /**
     * @param list<PossibleAssignee> $people one team's
     */
    public static function leadOf(array $people): ?PossibleAssignee
    {
        foreach ($people as $person) {
            if ($person->isLead) {
                return $person;
            }
        }

        return null;
    }

    /**
     * @param list<PossibleAssignee> $people one team's
     *
     * @return list<PossibleAssignee> in the given order
     */
    public static function giveToPeopleOf(array $people): array
    {
        return array_values(array_filter($people, static fn (PossibleAssignee $person): bool => MemberJob::Tester !== $person->job));
    }
}
