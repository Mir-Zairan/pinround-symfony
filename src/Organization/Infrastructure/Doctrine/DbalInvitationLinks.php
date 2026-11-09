<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure\Doctrine;

use App\Identity\Api\UserQueries;
use App\Organization\Application\InvitationLinks;
use App\Organization\Application\OpenInvitationLink;
use App\Organization\Domain\InvitationLinkToken;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantSwitchRefused;
use App\SharedKernel\Domain\OrganizationId;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DbalInvitationLinks implements InvitationLinks
{
    // The open check repeats resolve_invitation's, as the invitation may be accepted between the two statements.
    private const string OPEN_INVITATION = 'SELECT i.email, o.name FROM org_invitation i'
        .' JOIN org_organization o ON o.organization_id = i.organization_id'
        .' WHERE i.organization_id = ? AND i.id = ? AND i.accepted_at IS NULL AND i.expires_at > now()';

    public function __construct(
        private Connection $connection,
        private EntityManagerInterface $entityManager,
        private TenantContext $tenantContext,
        private UserQueries $userQueries,
    ) {
    }

    public function find(#[\SensitiveParameter] string $token): ?OpenInvitationLink
    {
        // A transaction already open would keep its own tenant (or none) for the read under the invitation's organization.
        if ($this->connection->isTransactionActive()) {
            throw new TenantSwitchRefused();
        }
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

        $tenantBefore = $this->tenantContext->get();
        // The switch guard refuses a switch while the entity manager holds an entity, such as the logged-in user.
        $this->entityManager->clear();
        try {
            $this->tenantContext->set($organizationId);
            $invitation = $this->connection->transactional(
                static fn (Connection $connection): array|false => $connection->fetchAssociative(self::OPEN_INVITATION, [$organizationId->toString(), $resolved['invitation_id']]),
            );
        } finally {
            null === $tenantBefore ? $this->tenantContext->clear() : $this->tenantContext->set($tenantBefore);
        }
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
