<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Doctrine;

use App\Media\Domain\UploadIntent;
use App\Media\Domain\UploadIntentId;
use App\Media\Domain\UploadIntents;
use App\Media\Domain\UploadIntentUnusable;
use App\SharedKernel\Domain\UserId;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineUploadIntents implements UploadIntents
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function nextIdentity(): UploadIntentId
    {
        return new UploadIntentId(Uuid::v7()->toRfc4122());
    }

    public function add(UploadIntent $intent): void
    {
        $this->requireTransaction('Adding an upload intent needs an open transaction.');

        $this->entityManager->persist($intent);
        $this->entityManager->flush();
    }

    /**
     * The key is (organization_id, id); the tenant filter supplies the organization.
     */
    public function byId(UploadIntentId $id): ?UploadIntent
    {
        $this->requireTransaction('Reading an upload intent needs an open transaction.');

        return $this->entityManager->getRepository(UploadIntent::class)->findOneBy(['id' => $id->toString()]);
    }

    /**
     * The WHERE is the check, so it holds against what is committed, not against the copy in memory: a second use at
     * the same time waits for the row lock of the first and then finds used_at set. The copy is not refreshed, as
     * Doctrine refuses to refresh its readonly expiresAt; it is marked used only after the row was, and the unit of
     * work is told used_at is stored, so the next flush does not write it again.
     *
     * @throws \LogicException when the tenant has no stored intent with that id
     */
    public function recordUse(UploadIntent $intent, \DateTimeImmutable $at): void
    {
        $this->requireTransaction('Using an upload intent needs an open transaction.');

        $key = ['organizationId' => $intent->organizationId()->toString(), 'id' => $intent->id()->toString()];
        $connection = $this->entityManager->getConnection();
        $changedRows = $connection->executeStatement(
            'UPDATE med_upload_intent SET used_at = :at WHERE organization_id = :organizationId AND id = :id AND used_at IS NULL AND expires_at > :at',
            ['at' => $at, ...$key],
            ['at' => Types::DATETIMETZ_IMMUTABLE],
        );
        if (1 === $changedRows) {
            $intent->markUsed($at);
            $unitOfWork = $this->entityManager->getUnitOfWork();
            if ($unitOfWork->isInIdentityMap($intent)) {
                $unitOfWork->setOriginalEntityProperty(spl_object_id($intent), 'usedAt', $at);
            }

            return;
        }

        $storedUsedAt = $connection->fetchOne('SELECT used_at FROM med_upload_intent WHERE organization_id = :organizationId AND id = :id', $key);
        if (false === $storedUsedAt) {
            throw new \LogicException('The upload intent is not stored under the tenant.');
        }

        throw null === $storedUsedAt ? UploadIntentUnusable::expired() : UploadIntentUnusable::usedAlready();
    }

    /**
     * Served index-only by med_upload_intent_open_idx; the tenant filter supplies the organization.
     */
    public function openCountOf(UserId $userId, \DateTimeImmutable $at): int
    {
        $this->requireTransaction('Reading the open upload intents needs an open transaction.');

        $openCount = $this->entityManager->createQuery('SELECT COUNT(i.id) FROM '.UploadIntent::class.' i WHERE i.userId = :userId AND i.usedAt IS NULL AND i.expiresAt > :at')
            ->setParameter('userId', $userId->toString())
            ->setParameter('at', $at, Types::DATETIMETZ_IMMUTABLE)
            ->getSingleScalarResult();
        if (!\is_int($openCount)) {
            throw new \UnexpectedValueException('A count of upload intents must be a whole number.');
        }

        return $openCount;
    }

    /**
     * Outside a transaction no tenant is set, so row-level security would hide or refuse the row, and the use would
     * not be one unit with the work that follows it.
     */
    private function requireTransaction(string $refusal): void
    {
        if (!$this->entityManager->getConnection()->isTransactionActive()) {
            throw new \LogicException($refusal);
        }
    }
}
