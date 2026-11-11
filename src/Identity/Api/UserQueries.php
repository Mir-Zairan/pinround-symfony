<?php

declare(strict_types=1);

namespace App\Identity\Api;

use App\SharedKernel\Domain\UserId;

/**
 * Questions about people, for other modules.
 */
interface UserQueries
{
    /**
     * Reads with plain SQL and holds no entity, so it may run while another module's tenant is being switched.
     *
     * @return UserId|null null when no one has this address; the address is compared in lowercase, trimmed
     */
    public function idByEmail(string $email): ?UserId;

    /**
     * One query; an empty $userIds runs none. Users are global, not an organization's, so this does not limit them to
     * the tenant: pass only ids read under the tenant, such as a team's members or a ticket's assignee. An unknown id
     * is left out; a blocked user is kept, as their name still labels their past work.
     *
     * @param list<UserId> $userIds
     *
     * @return array<string, UserView> keyed by user id string, in user id order
     */
    public function byIds(array $userIds): array;
}
