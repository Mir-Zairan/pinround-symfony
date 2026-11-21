<?php

declare(strict_types=1);

namespace App\Identity\Api;

use App\SharedKernel\Application\TenantSwitchRefused;
use App\SharedKernel\Domain\UserId;

/**
 * The organizations a person is a member of now, with their names. A port Identity owns and the Organization module
 * implements, as Identity may not depend on Organization (deptrac.yaml).
 */
interface MemberOrganizations
{
    /**
     * Reads each name in its own short transaction with that organization as the tenant, then puts the tenant back as
     * it was. Clears the entity manager first, so call it before any write and with no transaction open; unsaved
     * changes are lost.
     *
     * @return list<MemberOrganization> in id order; empty when the person is in no organization. An organization the
     *                                  person left is not in it.
     *
     * @throws TenantSwitchRefused when a transaction is open
     */
    public function of(UserId $userId): array;
}
