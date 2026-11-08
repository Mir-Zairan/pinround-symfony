<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure\Doctrine;

use App\Organization\Domain\Membership;
use App\Organization\Domain\Memberships;
use App\Organization\Domain\OrgRole;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\ResultSetMappingBuilder;

final readonly class DoctrineMemberships implements Memberships
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function of(OrganizationId $organizationId, UserId $userId): ?Membership
    {
        return $this->entityManager->find(Membership::class, ['organizationId' => $organizationId->toString(), 'userId' => $userId->toString()]);
    }

    /**
     * Native SQL, because DQL cannot test an array element. The tenant filter does not apply to native SQL, so the
     * organization is in the WHERE clause, and row-level security still limits it to the transaction's tenant. The
     * rows are locked in user id order, so two callers never wait on each other's locks crosswise, and the loaded
     * memberships are refreshed with the locked rows.
     */
    public function lockAdminsOf(OrganizationId $organizationId): array
    {
        if (!$this->entityManager->getConnection()->isTransactionActive()) {
            throw new \LogicException('Locking the admins of an organization needs an open transaction.');
        }

        $resultSetMapping = new ResultSetMappingBuilder($this->entityManager);
        $resultSetMapping->addRootEntityFromClassMetadata(Membership::class, 'm');
        $admins = $this->entityManager->createNativeQuery(
            'SELECT '.$resultSetMapping->generateSelectClause().' FROM org_membership m'
            .' WHERE m.organization_id = ? AND ? = ANY (m.org_roles) ORDER BY m.user_id FOR UPDATE',
            $resultSetMapping,
        )
            ->setParameter(1, $organizationId->toString())
            ->setParameter(2, OrgRole::Admin->value)
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();

        $lockedAdmins = [];
        foreach (\is_array($admins) ? $admins : [] as $admin) {
            if ($admin instanceof Membership) {
                $lockedAdmins[] = $admin;
            }
        }

        return $lockedAdmins;
    }

    public function add(Membership $membership): void
    {
        $this->entityManager->persist($membership);
        $this->entityManager->flush();
    }

    public function save(Membership $membership): void
    {
        $this->add($membership);
    }
}
