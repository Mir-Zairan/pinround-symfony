<?php

declare(strict_types=1);

namespace App\Organization\Domain;

use App\SharedKernel\Domain\OrganizationId;

/**
 * Invitations of the organization of the current transaction.
 */
interface Invitations
{
    /**
     * A new id no invitation has yet: a UUIDv7 (ADR-0006).
     */
    public function nextIdentity(): InvitationId;

    /**
     * Saves the invitation at once, so a second invitation of the same email that is not yet accepted fails here: the
     * unique index refuses it. Call removeNotAcceptedFor() first to replace one.
     *
     * @throws InvitationAlreadyOpen when an invitation of the email is not yet accepted, also one another transaction
     *                               saved after removeNotAcceptedFor(); the transaction is then failed and must be
     *                               rolled back
     */
    public function add(Invitation $invitation): void;

    public function byId(InvitationId $id): ?Invitation;

    /**
     * Deletes every invitation of the email that is not accepted, expired ones included, so a new one can be added.
     * It runs in the database at once; an invitation already loaded stays in memory and must not be saved again.
     *
     * @param string $email compared without regard to letter case
     */
    public function removeNotAcceptedFor(OrganizationId $organizationId, string $email): void;
}
