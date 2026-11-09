<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure\Doctrine;

use App\Identity\Api\UserQueries;
use App\Organization\Application\InvitationLinks;
use App\Organization\Application\OpenInvitationLink;
use App\Organization\Domain\InvitationLinkToken;
use App\SharedKernel\Domain\OrganizationId;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final readonly class DbalInvitationLinks implements InvitationLinks
{
    // The open check repeats resolve_invitation's, as the invitation may be accepted between the two statements.
    private const string OPEN_INVITATION = 'SELECT i.email, o.name FROM org_invitation i'
        .' JOIN org_organization o ON o.organization_id = i.organization_id'
        .' WHERE i.organization_id = ? AND i.id = ? AND i.accepted_at IS NULL AND i.expires_at > now()';

    public function __construct(
        private Connection $connection,
        private TenantSwitchingReads $tenantSwitchingReads,
        private UserQueries $userQueries,
    ) {
    }

    public function find(#[\SensitiveParameter] string $token): ?OpenInvitationLink
    {
        $this->tenantSwitchingReads->refuseInsideATransaction();
        $linkToken = InvitationLinkToken::fromLink($token);
        if (null === $linkToken) {
            return null;
        }
        $resolved = $this->connection->fetchAssociative(
            'SELECT invitation_id, organization_id FROM resolve_invitation(?)',
            [$linkToken->hash()],
            [ParameterType::BINARY],
        );
        if (false === $resolved || !\is_string($resolved['invitation_id']) || !\is_string($resolved['organization_id'])) {
            return null;
        }
        $organizationId = new OrganizationId($resolved['organization_id']);

        $invitation = $this->tenantSwitchingReads->readAs(
            $organizationId,
            static fn (Connection $connection): array|false => $connection->fetchAssociative(self::OPEN_INVITATION, [$organizationId->toString(), $resolved['invitation_id']]),
        );
        if (false === $invitation || !\is_string($invitation['email']) || !\is_string($invitation['name'])) {
            return null;
        }

        return new OpenInvitationLink(
            $resolved['invitation_id'],
            $organizationId,
            $invitation['email'],
            $invitation['name'],
            $this->userQueries->idByEmail($invitation['email']),
        );
    }
}
