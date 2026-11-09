<?php

declare(strict_types=1);

namespace App\Organization\Application;

use App\SharedKernel\Application\TenantSwitchRefused;

/**
 * Finds the invitation behind an emailed link before any tenant is known (data-model.md › Lookups before the tenant is
 * known).
 */
interface InvitationLinks
{
    /**
     * Resolves the token's hash without a tenant, then reads the invitation under its organization in a short
     * transaction and puts the tenant back as it was. Clears the entity manager first, so call it before any write
     * and with no transaction open; unsaved changes are lost.
     *
     * @param string $token the token as the link carries it
     *
     * @return OpenInvitationLink|null null when no invitation has this token, or it is accepted, expired or replaced
     *                                 by a newer invitation of the same email
     *
     * @throws TenantSwitchRefused when a transaction is open
     */
    public function find(#[\SensitiveParameter] string $token): ?OpenInvitationLink;
}
