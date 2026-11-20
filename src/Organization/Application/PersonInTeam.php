<?php

declare(strict_types=1);

namespace App\Organization\Application;

use App\Organization\Api\MemberJob;
use App\Organization\Domain\TeamRole;
use App\SharedKernel\Domain\TeamId;

/**
 * One team of an OrganizationPerson: the person's team role and job in it.
 */
final readonly class PersonInTeam
{
    public function __construct(public TeamId $teamId, public string $teamName, public TeamRole $teamRole, public MemberJob $job)
    {
    }
}
