<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Doctrine;

use App\Notification\Application\Digest\Deliveries;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\UserId;
use Doctrine\DBAL\Connection;

/**
 * Deliveries on ntf_delivery, which pinround_app may only select and insert. Names the tenant's organization_id as well
 * as row-level security, so each statement uses the primary key (organization_id, user_id, window_key).
 */
final readonly class DbalDeliveries implements Deliveries
{
    // An advisory lock, not a row lock: a person with no digest yet has no row to lock.
    private const string LOCK = 'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))';

    private const string LAST_DIGEST_SENT_AT = 'SELECT max(sent_at) FROM ntf_delivery'
        .' WHERE organization_id = ? AND user_id = ? AND starts_with(window_key, ?)';

    private const string RECORD = 'INSERT INTO ntf_delivery (organization_id, user_id, window_key, sent_at) VALUES (?, ?, ?, ?)'
        .' ON CONFLICT (organization_id, user_id, window_key) DO NOTHING';

    private const string TIMESTAMP_FORMAT = 'Y-m-d H:i:s.uP';

    public function __construct(private Connection $connection, private TenantContext $tenantContext)
    {
    }

    public function lockFor(UserId $userId): void
    {
        $organizationId = $this->tenantContext->require();
        $this->requireTransaction();

        $this->connection->executeStatement(self::LOCK, [\sprintf('ntf_delivery:digest:%s:%s', $organizationId->toString(), $userId->toString())]);
    }

    public function lastDigestSentAt(UserId $userId): ?\DateTimeImmutable
    {
        $organizationId = $this->tenantContext->require();
        $this->requireTransaction();

        $sentAt = $this->connection->fetchOne(self::LAST_DIGEST_SENT_AT, [$organizationId->toString(), $userId->toString(), self::DIGEST_WINDOW_PREFIX]);
        if (null === $sentAt) {
            return null;
        }
        if (!\is_string($sentAt)) {
            throw new \LogicException('A delivery time from the database is not a string.');
        }

        return new \DateTimeImmutable($sentAt);
    }

    public function recordDigest(UserId $userId, string $windowKey, \DateTimeImmutable $sentAt): bool
    {
        if (!str_starts_with($windowKey, self::DIGEST_WINDOW_PREFIX) || self::DIGEST_WINDOW_PREFIX === $windowKey) {
            throw new \InvalidArgumentException(\sprintf('A digest window key starts with "%s" and names the window.', self::DIGEST_WINDOW_PREFIX));
        }
        $organizationId = $this->tenantContext->require();
        $this->requireTransaction();

        return 1 === $this->connection->executeStatement(self::RECORD, [
            $organizationId->toString(),
            $userId->toString(),
            $windowKey,
            $sentAt->format(self::TIMESTAMP_FORMAT),
        ]);
    }

    private function requireTransaction(): void
    {
        // TenantMiddleware sets the tenant when a transaction begins; outside one no delivery is seen and every digest
        // would look unsent.
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('A digest delivery is read and recorded in a transaction; none is open.');
        }
    }
}
