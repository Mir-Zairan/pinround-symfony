<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

use App\Identity\Domain\ApiToken;
use App\Identity\Domain\ApiTokenOwner;
use App\Identity\Domain\ApiTokens;
use App\Identity\Domain\ExpiringApiToken;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * ApiTokens on idn_api_token: added and read through the entity manager, whose tenant filter supplies the
 * organization; revoked, marked used and claimed for the expiry warning in one conditional SQL statement each, naming
 * the tenant's organization_id as well as row-level security does.
 */
final readonly class DoctrineApiTokens implements ApiTokens
{
    private const string REVOKE = 'UPDATE idn_api_token SET revoked_at = ? WHERE organization_id = ? AND id = ? AND revoked_at IS NULL';

    private const string MARK_USED = 'UPDATE idn_api_token SET last_used_at = ?'
        .' WHERE organization_id = ? AND id = ? AND (last_used_at IS NULL OR last_used_at < ?)';

    // FOR UPDATE, not SKIP LOCKED: a token in use locks its row each minute (MARK_USED), and a skipped row would miss its
    // only warning. A claim that waited checks the row again, so a token revoked or claimed meanwhile is left out. Served
    // by idn_api_token_expires_at_id_live_idx.
    // The page is picked once, in a MATERIALIZED CTE: as `t.id IN (subquery)` the planner may rescan the subquery, and a
    // rescan's FOR UPDATE skips the rows this statement already updated, so LIMIT would hand over the next ones too.
    private const string CLAIM_EXPIRY_WARNINGS = 'WITH picked AS MATERIALIZED ('
        .'SELECT c.id FROM idn_api_token c WHERE c.organization_id = ? AND c.revoked_at IS NULL AND c.expiry_warned_at IS NULL'
        .' AND c.expires_at >= ? AND c.expires_at < ? ORDER BY c.expires_at, c.id LIMIT ? FOR UPDATE),'
        .' claimed AS (UPDATE idn_api_token t SET expiry_warned_at = ? FROM picked'
        .' WHERE t.organization_id = ? AND t.expiry_warned_at IS NULL AND t.id = picked.id'
        .' RETURNING t.id, t.user_id, t.name, t.prefix, t.expires_at)'
        .' SELECT id::text AS id, user_id::text AS user_id, name, prefix,'
        ." to_char(expires_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') || '+00:00' AS expires_at"
        .' FROM claimed ORDER BY claimed.expires_at, claimed.id';

    private const string RESOLVE = 'SELECT token_id, organization_id, user_id FROM resolve_api_token(?)';

    // An advisory lock, not a row lock: a user with no token has no row to lock, and a row lock does not stop an insert.
    private const string LOCK_ISSUING = 'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))';

    /** Names the lock among every module's advisory locks, which share one key space; then organization and user. */
    public const string ISSUING_LOCK_KEY_FORMAT = 'idn_api_token:issue:%s:%s';

    // Served by idn_api_token_user_id_created_at_idx.
    private const string COUNT_LIVE = 'SELECT count(*) FROM idn_api_token'
        .' WHERE organization_id = ? AND user_id = ? AND revoked_at IS NULL AND expires_at > ?';

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

    public function lockIssuingFor(OrganizationId $organizationId, UserId $userId): void
    {
        $connection = $this->entityManager->getConnection();
        // Outside a transaction the lock would end with its own statement.
        if (!$connection->isTransactionActive()) {
            throw new \LogicException('Issuing a token needs an open transaction; none is open.');
        }
        if (!$this->tenantContext->require()->equals($organizationId)) {
            throw new \LogicException('A token is issued under its own organization as tenant.');
        }

        $connection->executeStatement(self::LOCK_ISSUING, [\sprintf(self::ISSUING_LOCK_KEY_FORMAT, $organizationId->toString(), $userId->toString())]);
    }

    public function countLiveOf(UserId $userId, \DateTimeImmutable $at): int
    {
        $liveTokens = $this->entityManager->getConnection()->fetchOne(self::COUNT_LIVE, [
            $this->tenantContext->require()->toString(),
            $userId->toString(),
            $at->format(self::TIMESTAMP_FORMAT),
        ]);
        if (!\is_int($liveTokens)) {
            throw new \UnexpectedValueException('The count of live tokens is not a number.');
        }

        return $liveTokens;
    }

    public function byIdForOwner(string $id, UserId $ownerId): ?ApiToken
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->entityManager->getRepository(ApiToken::class)->findOneBy(['id' => strtolower($id), 'userId' => $ownerId->toString()]);
    }

    public function byId(string $id): ?ApiToken
    {
        $organizationId = $this->tenantContext->require();
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->entityManager->getRepository(ApiToken::class)->findOneBy(['organizationId' => $organizationId->toString(), 'id' => strtolower($id)]);
    }

    public function livePage(\DateTimeImmutable $at, int $limit, ?\DateTimeImmutable $afterExpiresAt = null, ?string $afterId = null): array
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('A page of tokens holds at least one.');
        }
        if ((null === $afterExpiresAt) !== (null === $afterId) || (null !== $afterId && !Uuid::isValid($afterId))) {
            throw new \InvalidArgumentException('A page of tokens starts after both the expiry and the id of a token.');
        }
        $organizationId = $this->tenantContext->require();
        $query = $this->entityManager->createQuery(
            'SELECT t FROM '.ApiToken::class.' t WHERE t.organizationId = :organizationId'
            .' AND t.revokedAt IS NULL AND t.expiresAt > :at'
            .(null === $afterExpiresAt ? '' : ' AND (t.expiresAt > :afterExpiresAt OR (t.expiresAt = :afterExpiresAt AND t.id > :afterId))')
            .' ORDER BY t.expiresAt ASC, t.id ASC',
        )
            ->setParameter('organizationId', $organizationId->toString())
            ->setParameter('at', $at->format(self::TIMESTAMP_FORMAT))
            ->setMaxResults($limit);
        if (null !== $afterExpiresAt && null !== $afterId) {
            $query->setParameter('afterExpiresAt', $afterExpiresAt->format(self::TIMESTAMP_FORMAT))
                ->setParameter('afterId', strtolower($afterId));
        }
        /** @var list<ApiToken> $tokens */
        $tokens = $query->getResult();

        return $tokens;
    }

    public function forUser(UserId $userId): array
    {
        /** @var list<ApiToken> $tokens */
        $tokens = $this->entityManager->createQuery(
            'SELECT t FROM '.ApiToken::class.' t WHERE t.userId = :userId ORDER BY t.createdAt DESC, t.id DESC',
        )
            ->setParameter('userId', $userId->toString())
            ->setMaxResults(self::MAX_LISTED_TOKENS)
            ->getResult();

        return $tokens;
    }

    public function liveForUser(UserId $userId, \DateTimeImmutable $at): array
    {
        // Named as well as row-level security does, like countLiveOf(); require() fails closed without a tenant.
        $organizationId = $this->tenantContext->require();
        /** @var list<ApiToken> $tokens */
        $tokens = $this->entityManager->createQuery(
            'SELECT t FROM '.ApiToken::class.' t WHERE t.organizationId = :organizationId AND t.userId = :userId'
            .' AND t.revokedAt IS NULL AND t.expiresAt > :at ORDER BY t.createdAt DESC, t.id DESC',
        )
            ->setParameter('organizationId', $organizationId->toString())
            ->setParameter('userId', $userId->toString())
            ->setParameter('at', $at->format(self::TIMESTAMP_FORMAT))
            ->setMaxResults(self::MAX_LISTED_TOKENS)
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

    public function claimExpiryWarnings(\DateTimeImmutable $expiresFrom, \DateTimeImmutable $expiresBefore, \DateTimeImmutable $warnedAt, int $limit): array
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('A claim of expiry warnings takes at least one token.');
        }
        if ($expiresBefore <= $expiresFrom) {
            throw new \InvalidArgumentException('The expiry range of a claim ends after it starts.');
        }
        $organizationId = $this->tenantContext->require()->toString();

        $rows = $this->entityManager->getConnection()->fetchAllAssociative(self::CLAIM_EXPIRY_WARNINGS, [
            $organizationId,
            $expiresFrom->format(self::TIMESTAMP_FORMAT),
            $expiresBefore->format(self::TIMESTAMP_FORMAT),
            $limit,
            $warnedAt->format(self::TIMESTAMP_FORMAT),
            $organizationId,
        ], [3 => ParameterType::INTEGER]);

        return array_map(static function (array $row): ExpiringApiToken {
            if (!\is_string($row['id']) || !\is_string($row['user_id']) || !\is_string($row['name']) || !\is_string($row['prefix']) || !\is_string($row['expires_at'])) {
                throw new \UnexpectedValueException('A claimed token row lacks a column.');
            }

            return new ExpiringApiToken($row['id'], new UserId($row['user_id']), $row['name'], $row['prefix'], new \DateTimeImmutable($row['expires_at']));
        }, $rows);
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
