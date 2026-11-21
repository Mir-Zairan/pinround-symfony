<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

use App\Identity\Domain\OAuthRefreshToken;
use App\Identity\Domain\OAuthRefreshTokens;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * OAuthRefreshTokens on idn_oauth_refresh_token (Version20261121230000), a tenant table: marked used and revoked in one
 * conditional SQL statement each, naming the tenant's organization_id as well as row-level security does. Each write
 * first takes the family's advisory lock (FAMILY_LOCK_KEY_FORMAT) for the rest of the transaction.
 */
final readonly class DoctrineOAuthRefreshTokens implements OAuthRefreshTokens
{
    // PostgreSQL checks the WHERE clause again after waiting on another call's row lock, so only one call marks it.
    private const string MARK_USED = 'UPDATE idn_oauth_refresh_token SET used_at = ?'
        .' WHERE organization_id = ? AND token_hash = ? AND client_id = ? AND used_at IS NULL AND revoked_at IS NULL'
        .' AND expires_at > ? RETURNING id::text';

    private const string ID_BY_HASH = 'SELECT id::text FROM idn_oauth_refresh_token WHERE organization_id = ? AND token_hash = ?';

    private const string FAMILY_BY_HASH = 'SELECT family_id::text FROM idn_oauth_refresh_token WHERE organization_id = ? AND token_hash = ?';

    // An advisory lock, not a row lock: a row lock does not stop the insert of a family's next token, which a revoke's
    // UPDATE that began before it would then miss.
    private const string LOCK_FAMILY = 'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))';

    /** Names the lock among every module's advisory locks, which share one key space; then organization and family. */
    public const string FAMILY_LOCK_KEY_FORMAT = 'idn_oauth_refresh_token:family:%s:%s';

    private const string REVOKE_FAMILY = 'UPDATE idn_oauth_refresh_token SET revoked_at = ?'
        .' WHERE organization_id = ? AND family_id = ? AND revoked_at IS NULL RETURNING access_token_id::text, used_at IS NULL AS untraded';

    // Every token of a family has the same person; served by idn_oauth_refresh_token_family_id_idx.
    private const string OWNER_OF_FAMILY = 'SELECT user_id::text FROM idn_oauth_refresh_token WHERE organization_id = ? AND family_id = ? LIMIT 1';

    private const string RESOLVE = 'SELECT organization_id FROM resolve_oauth_refresh_token(?)';

    // The columns keep whole seconds, and PostgreSQL rounds a fraction; cutting it here keeps "now" from moving ahead.
    private const string TIMESTAMP_FORMAT = 'Y-m-d H:i:sP';

    public function __construct(private EntityManagerInterface $entityManager, private TenantContext $tenantContext)
    {
    }

    public function nextIdentity(): string
    {
        return Uuid::v7()->toRfc4122();
    }

    public function add(OAuthRefreshToken $token): void
    {
        if (!$this->tenantContext->require()->equals($token->organizationId())) {
            throw new \LogicException('A refresh token is saved under its own organization as tenant.');
        }
        $this->lockFamily($token->organizationId()->toString(), $token->familyId());
        $this->entityManager->persist($token);
        $this->entityManager->flush();
    }

    public function markUsed(#[\SensitiveParameter] string $tokenHash, string $clientId, \DateTimeImmutable $at): ?OAuthRefreshToken
    {
        $organizationId = $this->tenantContext->require()->toString();
        $this->refuseOutsideTransaction();
        $familyId = $this->entityManager->getConnection()->fetchOne(self::FAMILY_BY_HASH, [$organizationId, $tokenHash], [1 => ParameterType::BINARY]);
        if (!\is_string($familyId)) {
            return null;
        }
        $this->lockFamily($organizationId, $familyId);
        $usedAt = $at->format(self::TIMESTAMP_FORMAT);
        $markedIds = $this->entityManager->getConnection()->fetchFirstColumn(
            self::MARK_USED,
            [$usedAt, $organizationId, $tokenHash, $clientId, $usedAt],
            [2 => ParameterType::BINARY],
        );
        // token_hash is unique, so more than one row cannot be marked; anything but one is a refusal.
        if (1 !== \count($markedIds) || !\is_string($markedIds[0])) {
            return null;
        }

        return $this->freshById($organizationId, $markedIds[0]);
    }

    public function byHash(#[\SensitiveParameter] string $tokenHash): ?OAuthRefreshToken
    {
        $organizationId = $this->tenantContext->require()->toString();
        $id = $this->entityManager->getConnection()->fetchOne(self::ID_BY_HASH, [$organizationId, $tokenHash], [1 => ParameterType::BINARY]);
        if (!\is_string($id)) {
            return null;
        }

        return $this->entityManager->find(OAuthRefreshToken::class, ['organizationId' => $organizationId, 'id' => $id]);
    }

    public function byAccessTokenId(string $accessTokenId): ?OAuthRefreshToken
    {
        $organizationId = $this->tenantContext->require()->toString();
        if (!Uuid::isValid($accessTokenId)) {
            return null;
        }

        return $this->entityManager->getRepository(OAuthRefreshToken::class)->findOneBy(['organizationId' => $organizationId, 'accessTokenId' => strtolower($accessTokenId)]);
    }

    public function ownerOfFamily(string $familyId): ?UserId
    {
        $organizationId = $this->tenantContext->require()->toString();
        if (!Uuid::isValid($familyId)) {
            return null;
        }
        $userId = $this->entityManager->getConnection()->fetchOne(self::OWNER_OF_FAMILY, [$organizationId, strtolower($familyId)]);

        return \is_string($userId) ? new UserId($userId) : null;
    }

    public function revokeFamily(string $familyId, \DateTimeImmutable $revokedAt): array
    {
        $organizationId = $this->tenantContext->require()->toString();
        // Taken before the UPDATE, whose snapshot then holds every token a rotation that held the lock has added.
        $this->lockFamily($organizationId, $familyId);
        $revoked = $this->entityManager->getConnection()->fetchAllAssociative(self::REVOKE_FAMILY, [
            $revokedAt->format(self::TIMESTAMP_FORMAT),
            $organizationId,
            $familyId,
        ]);
        $accessTokenIds = [];
        foreach ($revoked as $row) {
            if (true === $row['untraded'] && \is_string($row['access_token_id'])) {
                $accessTokenIds[] = $row['access_token_id'];
            }
        }

        return $accessTokenIds;
    }

    public function organizationOfToken(#[\SensitiveParameter] string $tokenHash): ?OrganizationId
    {
        $organizationId = $this->entityManager->getConnection()->fetchOne(self::RESOLVE, [$tokenHash], [ParameterType::BINARY]);
        if (false === $organizationId) {
            return null;
        }
        if (!\is_string($organizationId)) {
            throw new \UnexpectedValueException('resolve_oauth_refresh_token gave a row without its organization.');
        }

        return new OrganizationId($organizationId);
    }

    /**
     * Held to the end of the transaction, so a family's rotation, its revocation and the exchange that starts it run
     * one after another.
     *
     * @throws \LogicException when no transaction is open
     */
    private function lockFamily(string $organizationId, string $familyId): void
    {
        $this->refuseOutsideTransaction();
        $this->entityManager->getConnection()->executeStatement(self::LOCK_FAMILY, [\sprintf(self::FAMILY_LOCK_KEY_FORMAT, $organizationId, strtolower($familyId))]);
    }

    /**
     * Outside a transaction the family lock would end with its own statement, and a token lookup would find nothing.
     *
     * @throws \LogicException when no transaction is open
     */
    private function refuseOutsideTransaction(): void
    {
        if (!$this->entityManager->getConnection()->isTransactionActive()) {
            throw new \LogicException('A refresh token family is changed only in an open transaction; none is open.');
        }
    }

    /**
     * Read again from the database even when the entity manager holds it, so used_at is the one just written. A loaded
     * copy is detached rather than refreshed, as Doctrine refuses to refresh the readonly createdAt.
     */
    private function freshById(string $organizationId, string $id): ?OAuthRefreshToken
    {
        $loaded = $this->entityManager->getUnitOfWork()->tryGetById(['organizationId' => $organizationId, 'id' => $id], OAuthRefreshToken::class);
        if ($loaded instanceof OAuthRefreshToken) {
            $this->entityManager->detach($loaded);
        }
        $token = $this->entityManager->createQuery('SELECT t FROM '.OAuthRefreshToken::class.' t WHERE t.organizationId = :organizationId AND t.id = :id')
            ->setParameters(['organizationId' => $organizationId, 'id' => $id])
            ->getOneOrNullResult();

        return $token instanceof OAuthRefreshToken ? $token : null;
    }
}
