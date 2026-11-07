<?php

declare(strict_types=1);

namespace App\Organization\Domain;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * Memberships of the organization of the current transaction (row-level security hides every other one).
 */
interface Memberships
{
    public function of(OrganizationId $organizationId, UserId $userId): ?Membership;

    /**
     * Every membership of the organization that holds Admin, locked for update until the transaction ends, so two
     * admins removing each other's Admin role at the same time are checked one after the other.
     *
     * @return list<Membership>
     *
     * @throws \LogicException when no transaction is open, as the lock would end with the statement
     */
    public function lockAdminsOf(OrganizationId $organizationId): array;

    /**
     * Saves the membership at once; a second membership of the same user in the same organization fails here.
     */
    public function add(Membership $membership): void;

    /**
     * Saves the changes to a membership already added, at once.
     */
    public function save(Membership $membership): void;
}
