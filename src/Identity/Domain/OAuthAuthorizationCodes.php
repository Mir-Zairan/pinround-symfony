<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use App\SharedKernel\Application\TenantSwitchRefused;
use App\SharedKernel\Domain\OrganizationId;

/**
 * OAuth authorization codes. A code is issued on the consent page, whose request has the person's chosen organization
 * or none as tenant, so add() runs in its own transaction under the code's organization and puts the tenant back
 * afterwards. The token endpoint finds the organization with organizationOfCode() and then trades the code in its own
 * transaction under that organization as tenant, so the tokens it issues commit with the code's use.
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
     * Marks the tenant's code with this hash as used at $at, in one conditional UPDATE in the caller's transaction:
     * only when it is unused and expires after $at. Of two calls at once the second waits on the row lock of the first
     * until its transaction ends, and then finds it used.
     *
     * @param string $codeHash ApiTokenHashing::hash() of the presented code
     *
     * @return OAuthAuthorizationCode|null the code as this call marked it; null when no code of the tenant has the
     *                                     hash, or it was used already or has expired at $at, and then nothing is
     *                                     changed
     *
     * @throws \LogicException when no tenant is set, or no transaction is open; nothing is changed
     */
    public function consume(#[\SensitiveParameter] string $codeHash, \DateTimeImmutable $at): ?OAuthAuthorizationCode;

    /**
     * @param string $codeHash ApiTokenHashing::hash() of the presented code
     *
     * @return string|null the id of the tenant's code with this hash when it was used, expired or not; null for an
     *                     unknown or unused code
     *
     * @throws \LogicException when no tenant is set
     */
    public function idOfUsedCode(#[\SensitiveParameter] string $codeHash): ?string;

    /**
     * Through resolve_oauth_authorization_code, before any tenant is known: the organization of the code with this hash
     * when its person is still a member there and it is either used, so a replay can be told from an unknown code, or
     * unused and not expired by the database clock.
     *
     * @param string $codeHash ApiTokenHashing::hash() of the presented code
     *
     * @return OrganizationId|null null for an unknown or expired unused code, or one of a person who left, alike
     */
    public function organizationOfCode(#[\SensitiveParameter] string $codeHash): ?OrganizationId;
}
