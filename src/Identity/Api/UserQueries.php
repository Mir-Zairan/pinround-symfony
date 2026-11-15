<?php

declare(strict_types=1);

namespace App\Identity\Api;

use App\SharedKernel\Domain\UserId;

/**
 * Questions about people, for other modules.
 */
interface UserQueries
{
    public const int MAX_TIME_ZONE_USER_IDS = 100;

    public const int MAX_EMAIL_USER_IDS = 100;

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

    /**
     * One query; an empty $userIds runs none. Not limited to the tenant, like byIds: pass only ids read under it. An
     * unknown id is left out; a saved zone PHP does not know gives UTC.
     *
     * @param list<UserId> $userIds at most MAX_TIME_ZONE_USER_IDS
     *
     * @return array<string, \DateTimeZone> the person's saved IANA time zone, keyed by user id string, in user id order
     *
     * @throws \InvalidArgumentException when $userIds holds more than MAX_TIME_ZONE_USER_IDS ids, before any query
     */
    public function timeZonesOf(array $userIds): array;

    /**
     * One query; an empty $userIds runs none. Not limited to the tenant, like byIds: pass only ids read under it. An
     * unknown id is left out, and so is a blocked user, who may not sign in and so is sent nothing.
     *
     * @param list<UserId> $userIds at most MAX_EMAIL_USER_IDS
     *
     * @return array<string, string> the person's email address, trimmed and in lowercase, keyed by user id string, in
     *                               user id order
     *
     * @throws \InvalidArgumentException when $userIds holds more than MAX_EMAIL_USER_IDS ids, before any query
     */
    public function emailsOf(array $userIds): array;
}
