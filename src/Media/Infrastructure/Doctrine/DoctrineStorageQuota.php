<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Doctrine;

use App\Media\Domain\MediaStatus;
use App\Media\Domain\StorageQuota;
use App\SharedKernel\Domain\OrganizationId;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineStorageQuota implements StorageQuota
{
    /** Names the lock among every module's advisory locks, which share one key space. */
    public const string LOCK_PREFIX = 'med_upload_quota:';

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * A transaction-scoped advisory lock rather than a row lock, as the organization may have no row to lock yet.
     */
    public function lock(OrganizationId $organizationId): void
    {
        $this->requireTransaction('Locking the storage quota needs an open transaction.');

        $this->entityManager->getConnection()->executeStatement(
            'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
            [self::LOCK_PREFIX.$organizationId->toString()],
        );
    }

    /**
     * One statement, so under READ COMMITTED both sums read one snapshot: two would miss an upload whose complete
     * commits between them, as its intent and its media object. Served index-only by med_object_status_bytes_idx and
     * med_upload_intent_open_idx.
     */
    public function bytesInUseAt(OrganizationId $organizationId, \DateTimeImmutable $at): int
    {
        $this->requireTransaction('Reading the storage in use needs an open transaction.');

        return ByteSum::of($this->entityManager->getConnection()->fetchOne(
            'SELECT (SELECT COALESCE(SUM(bytes), 0) FROM med_object WHERE organization_id = :organizationId AND status IN (:takingSpace))'
            .' + (SELECT COALESCE(SUM(max_bytes), 0) FROM med_upload_intent WHERE organization_id = :organizationId AND used_at IS NULL AND expires_at > :at)',
            ['organizationId' => $organizationId->toString(), 'takingSpace' => [MediaStatus::Pending->value, MediaStatus::Ready->value], 'at' => $at],
            ['takingSpace' => ArrayParameterType::STRING, 'at' => Types::DATETIMETZ_IMMUTABLE],
        ));
    }

    /**
     * Outside a transaction no tenant is set, so row-level security would hide every row, and the lock would end with
     * its own statement.
     */
    private function requireTransaction(string $refusal): void
    {
        if (!$this->entityManager->getConnection()->isTransactionActive()) {
            throw new \LogicException($refusal);
        }
    }
}
