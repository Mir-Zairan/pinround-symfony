<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use App\SharedKernel\Application\TenantSwitchRefused;
use App\SharedKernel\Domain\OrganizationId;

/**
 * OAuth authorization codes. A code is issued on the consent page, whose request has the person's chosen organization
 * or none as tenant, and traded on the token endpoint, which has no tenant; so add() and consume() each run in their
 * own transaction under the code's organization and put the tenant back afterwards.
 */
interface OAuthAuthorizationCodes
{
    /**
     * A new id no code has yet: a UUIDv7 (ADR-0006).
     */
    public function nextIdentity(): string;

    /**
     * Saves the code at once, in its own transaction under the code's organization as tenant, then puts the tenant back
     * as it was. Clears the entity manager before and after; unsaved changes are lost and loaded entities detached.
     *
     * @throws TenantSwitchRefused when a transaction is open; nothing is saved
     */
    public function add(OAuthAuthorizationCode $code): void;

    /**
     * Marks the code with this hash in $organizationId as used at $at, in one conditional UPDATE of its own transaction
     * under $organizationId as tenant: only when it is unused and expires after $at. Of two calls at once the second
     * waits on the row lock of the first and then finds it used. Clears the entity manager before and after, as add().
     *
     * @param string $codeHash ApiTokenHashing::hash() of the presented code
     *
     * @return OAuthAuthorizationCode|null the code as this call marked it, detached; null when no code of
     *                                     $organizationId has the hash, or it was used already or has expired at $at,
     *                                     and then nothing is changed
     *
     * @throws TenantSwitchRefused when a transaction is open; nothing is changed
     */
    public function consume(OrganizationId $organizationId, #[\SensitiveParameter] string $codeHash, \DateTimeImmutable $at): ?OAuthAuthorizationCode;

    /**
     * Through resolve_oauth_authorization_code, before any tenant is known: the organization of the code with this hash
     * when it is unused, not expired by the database clock, and its person is still a member there.
     *
     * @param string $codeHash ApiTokenHashing::hash() of the presented code
     *
     * @return OrganizationId|null null for an unknown, used or expired code, or one of a person who left, alike
     */
    public function organizationOfLiveCode(#[\SensitiveParameter] string $codeHash): ?OrganizationId;
}
