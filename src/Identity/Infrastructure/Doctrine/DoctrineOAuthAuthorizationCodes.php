<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

use App\Identity\Domain\OAuthAuthorizationCode;
use App\Identity\Domain\OAuthAuthorizationCodes;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantSwitchRefused;
use App\SharedKernel\Domain\OrganizationId;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * OAuthAuthorizationCodes on idn_oauth_authorization_code (Version20261121220000), a tenant table: each write switches
 * the tenant to the code's organization for its own transaction, as TenantSwitchingReads does in Organization, and
 * names the organization_id as well as row-level security does.
 */
final readonly class DoctrineOAuthAuthorizationCodes implements OAuthAuthorizationCodes
{
    // PostgreSQL checks the WHERE clause again after waiting on another call's row lock, so only one call marks it.
    private const string CONSUME = 'UPDATE idn_oauth_authorization_code SET used_at = ?'
        .' WHERE organization_id = ? AND code_hash = ? AND used_at IS NULL AND expires_at > ? RETURNING id::text';

    private const string RESOLVE = 'SELECT organization_id FROM resolve_oauth_authorization_code(?)';

    // The columns keep whole seconds, and PostgreSQL rounds a fraction; cutting it here keeps "now" from moving ahead.
    private const string TIMESTAMP_FORMAT = 'Y-m-d H:i:sP';

    public function __construct(private EntityManagerInterface $entityManager, private TenantContext $tenantContext)
    {
    }

    public function nextIdentity(): string
    {
        return Uuid::v7()->toRfc4122();
    }

    public function add(OAuthAuthorizationCode $code): void
    {
        $this->writeAs($code->organizationId(), function () use ($code): void {
            $this->entityManager->persist($code);
            $this->entityManager->flush();
        });
    }

    public function consume(OrganizationId $organizationId, #[\SensitiveParameter] string $codeHash, \DateTimeImmutable $at): ?OAuthAuthorizationCode
    {
        return $this->writeAs($organizationId, function () use ($organizationId, $codeHash, $at): ?OAuthAuthorizationCode {
            $usedAt = $at->format(self::TIMESTAMP_FORMAT);
            $markedIds = $this->entityManager->getConnection()->fetchFirstColumn(
                self::CONSUME,
                [$usedAt, $organizationId->toString(), $codeHash, $usedAt],
                [2 => ParameterType::BINARY],
            );
            // code_hash is unique, so more than one row cannot be marked; anything but one is a refusal.
            if (1 !== \count($markedIds) || !\is_string($markedIds[0])) {
                return null;
            }

            return $this->entityManager->find(OAuthAuthorizationCode::class, ['organizationId' => $organizationId->toString(), 'id' => $markedIds[0]]);
        });
    }

    public function organizationOfLiveCode(#[\SensitiveParameter] string $codeHash): ?OrganizationId
    {
        $organizationId = $this->entityManager->getConnection()->fetchOne(self::RESOLVE, [$codeHash], [ParameterType::BINARY]);
        if (false === $organizationId) {
            return null;
        }
        if (!\is_string($organizationId)) {
            throw new \UnexpectedValueException('resolve_oauth_authorization_code gave a row without its organization.');
        }

        return new OrganizationId($organizationId);
    }

    /**
     * Runs $write in its own transaction under $organizationId, then puts the tenant back as it was. The entity manager
     * is cleared first, as the switch guard refuses while it holds an entity such as the logged-in user, and after, so
     * no entity of $organizationId outlives its tenant.
     *
     * @template T
     *
     * @param \Closure(): T $write
     *
     * @return T
     *
     * @throws TenantSwitchRefused when a transaction is open: it would keep its own tenant, or none
     */
    private function writeAs(OrganizationId $organizationId, \Closure $write): mixed
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            throw new TenantSwitchRefused();
        }
        $tenantBefore = $this->tenantContext->get();
        $this->entityManager->clear();
        try {
            $this->tenantContext->set($organizationId);

            return $this->entityManager->wrapInTransaction(static fn (): mixed => $write());
        } finally {
            $this->entityManager->clear();
            if (null === $tenantBefore) {
                $this->tenantContext->clear();
            } else {
                $this->tenantContext->set($tenantBefore);
            }
        }
    }
}
