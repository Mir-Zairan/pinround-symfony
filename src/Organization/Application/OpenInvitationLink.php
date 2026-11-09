<?php

declare(strict_types=1);

namespace App\Organization\Application;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * An invitation whose link was open when it was looked up, with what the accept page shows. It is also the subject of
 * OrganizationPermission::ACCEPT_INVITATION.
 */
final readonly class OpenInvitationLink
{
    /**
     * @param string  $invitationId      a canonical UUIDv7, kept as a string so the UI can carry it
     * @param ?UserId $existingAccountId the account that has the invited email, null when nobody has one yet
     */
    public function __construct(
        public string $invitationId,
        public OrganizationId $organizationId,
        public string $invitedEmail,
        public string $organizationName,
        public ?UserId $existingAccountId,
    ) {
    }
}
