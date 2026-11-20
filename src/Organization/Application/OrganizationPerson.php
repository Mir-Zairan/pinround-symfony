<?php

declare(strict_types=1);

namespace App\Organization\Application;

use App\SharedKernel\Domain\UserId;

/**
 * A member of the organization as the admin People and roles tab lists them: name, the company-wide roles Admin and
 * Manager, and a team role in each of their teams.
 */
final readonly class OrganizationPerson
{
    /**
     * @param list<PersonInTeam> $teams by team name ignoring case, then team id; empty for a person in no team
     */
    public function __construct(
        public UserId $userId,
        public string $displayName,
        public bool $isAdmin,
        public bool $isManager,
        public array $teams,
    ) {
    }
}
