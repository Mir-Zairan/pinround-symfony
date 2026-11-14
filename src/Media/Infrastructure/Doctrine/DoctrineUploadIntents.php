<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Doctrine;

use App\Media\Domain\UploadIntent;
use App\Media\Domain\UploadIntentId;
use App\Media\Domain\UploadIntents;
use App\Media\Domain\UploadIntentUnusable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineUploadIntents implements UploadIntents
{
    public function __construct(private EntityManagerInterface $entityManager, private ClockInterface $clock)
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
     * Doctrine refuses to refresh its readonly expiresAt; it is marked used only after the row was, and the next flush
     * writes the same used_at once more.
     *
     * @throws \LogicException when the tenant has no stored intent with that id
     */
    public function recordUse(UploadIntent $intent): void
    {
        $this->requireTransaction('Using an upload intent needs an open transaction.');

        $now = $this->clock->now();
        $key = ['organizationId' => $intent->organizationId()->toString(), 'id' => $intent->id()->toString()];
        $connection = $this->entityManager->getConnection();
        $changedRows = $connection->executeStatement(
            'UPDATE med_upload_intent SET used_at = :now WHERE organization_id = :organizationId AND id = :id AND used_at IS NULL AND expires_at > :now',
            ['now' => $now, ...$key],
            ['now' => Types::DATETIMETZ_IMMUTABLE],
        );
        if (1 === $changedRows) {
            $intent->markUsed($now);

            return;
        }

        $storedUsedAt = $connection->fetchOne('SELECT used_at FROM med_upload_intent WHERE organization_id = :organizationId AND id = :id', $key);
        if (false === $storedUsedAt) {
            throw new \LogicException('The upload intent is not stored under the tenant.');
        }

        throw new UploadIntentUnusable(null === $storedUsedAt ? 'The upload intent has expired.' : 'The upload intent is used already.');
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
