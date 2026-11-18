<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

use App\Identity\Domain\ApiToken;
use App\Identity\Domain\ApiTokenOwner;
use App\Identity\Domain\ApiTokens;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * ApiTokens on idn_api_token: added and read through the entity manager, whose tenant filter supplies the
 * organization; revoked and marked used in one conditional SQL statement each, naming the tenant's organization_id
 * as well as row-level security does.
 */
final readonly class DoctrineApiTokens implements ApiTokens
{
    private const string REVOKE = 'UPDATE idn_api_token SET revoked_at = ? WHERE organization_id = ? AND id = ? AND revoked_at IS NULL';

    private const string MARK_USED = 'UPDATE idn_api_token SET last_used_at = ?'
        .' WHERE organization_id = ? AND id = ? AND (last_used_at IS NULL OR last_used_at < ?)';

    private const string RESOLVE = 'SELECT token_id, organization_id, user_id FROM resolve_api_token(?)';

    // The columns keep whole seconds, and PostgreSQL rounds a fraction; cutting it here keeps "now" from moving ahead.
    private const string TIMESTAMP_FORMAT = 'Y-m-d H:i:sP';

    public function __construct(private EntityManagerInterface $entityManager, private TenantContext $tenantContext)
    {
    }

    public function nextIdentity(): string
    {
        return Uuid::v7()->toRfc4122();
    }

    public function add(ApiToken $token): void
    {
        $this->entityManager->persist($token);
        $this->entityManager->flush();
    }

    public function byIdForOwner(string $id, UserId $ownerId): ?ApiToken
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->entityManager->getRepository(ApiToken::class)->findOneBy(['id' => strtolower($id), 'userId' => $ownerId->toString()]);
    }

    public function forUser(UserId $userId): array
    {
        /** @var list<ApiToken> $tokens */
        $tokens = $this->entityManager->createQuery(
            'SELECT t FROM '.ApiToken::class.' t WHERE t.userId = :userId ORDER BY t.createdAt DESC, t.id DESC',
        )
            ->setParameter('userId', $userId->toString())
            ->getResult();

        return $tokens;
    }

    /**
     * A second revoke waits on the row lock of the first and then sees revoked_at set, so it updates nothing
     * (PostgreSQL checks the WHERE clause again after the lock).
     */
    public function revoke(string $id, \DateTimeImmutable $revokedAt): bool
    {
        $organizationId = $this->tenantContext->require();
        if (!Uuid::isValid($id)) {
            return false;
        }

        return 1 === $this->entityManager->getConnection()->executeStatement(self::REVOKE, [
            $revokedAt->format(self::TIMESTAMP_FORMAT),
            $organizationId->toString(),
            $id,
        ]);
    }

    public function markUsed(string $id, \DateTimeImmutable $usedAt): bool
    {
        $organizationId = $this->tenantContext->require();
        if (!Uuid::isValid($id)) {
            return false;
        }

        return 1 === $this->entityManager->getConnection()->executeStatement(self::MARK_USED, [
            $usedAt->format(self::TIMESTAMP_FORMAT),
            $organizationId->toString(),
            $id,
            $usedAt->modify('-1 minute')->format(self::TIMESTAMP_FORMAT),
        ]);
    }

    public function ownerOfLiveToken(string $tokenHash): ?ApiTokenOwner
    {
        $row = $this->entityManager->getConnection()->fetchAssociative(self::RESOLVE, [$tokenHash], [ParameterType::BINARY]);
        if (false === $row) {
            return null;
        }
        if (!\is_string($row['token_id']) || !\is_string($row['organization_id']) || !\is_string($row['user_id'])) {
            throw new \UnexpectedValueException('resolve_api_token gave a row without its ids.');
        }

        return new ApiTokenOwner($row['token_id'], new OrganizationId($row['organization_id']), new UserId($row['user_id']));
    }
}
