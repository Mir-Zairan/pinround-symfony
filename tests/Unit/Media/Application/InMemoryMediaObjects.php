<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Application;

use App\Media\Domain\MediaId;
use App\Media\Domain\MediaObject;
use App\Media\Domain\MediaObjects;
use App\Media\Domain\MediaStatus;
use App\SharedKernel\Application\TenantContext;
use Symfony\Component\Uid\Uuid;

/**
 * MediaObjects as DoctrineMediaObjects behaves on the real table: it needs an open transaction, sees and writes only
 * the tenant's rows and refuses a second row with the same key. A test sets $nextAddFailure to make the next add()
 * fail as the database would.
 */
final class InMemoryMediaObjects implements MediaObjects
{
    public ?\Throwable $nextAddFailure = null;

    /** @var array<string, MediaObject> by organization id and media id, as the table's primary key */
    private array $stored = [];

    public function __construct(private readonly InMemoryTransactions $transactions, private readonly TenantContext $tenantContext)
    {
        $transactions->enlist(function (): \Closure {
            $snapshot = array_map(static fn (MediaObject $mediaObject): MediaObject => clone $mediaObject, $this->stored);

            return function () use ($snapshot): void {
                $this->stored = $snapshot;
            };
        });
    }

    public function nextIdentity(): MediaId
    {
        return new MediaId(Uuid::v7()->toRfc4122());
    }

    public function add(MediaObject $mediaObject): void
    {
        $this->requireTransaction('Adding a media object needs an open transaction.');
        if (null !== $this->nextAddFailure) {
            $failure = $this->nextAddFailure;
            $this->nextAddFailure = null;

            throw $failure;
        }
        $tenantId = $this->tenantContext->require()->toString();
        if ($mediaObject->organizationId()->toString() !== $tenantId) {
            throw new \RuntimeException('new row violates row-level security policy for table "med_object"');
        }
        $key = self::keyOf($tenantId, $mediaObject->id()->toString());
        if (isset($this->stored[$key])) {
            throw new \RuntimeException('duplicate key value violates unique constraint "med_object_pkey"');
        }

        $this->stored[$key] = $mediaObject;
    }

    public function save(MediaObject $mediaObject): void
    {
        $this->requireTransaction('Saving a media object needs an open transaction.');
        if (!\in_array($mediaObject, $this->stored, true)) {
            throw new \LogicException('Only a media object read through this port, and not replaced by lockById() since, can be saved.');
        }
    }

    public function byId(MediaId $id): ?MediaObject
    {
        $this->requireTransaction('Reading a media object needs an open transaction.');

        return $this->stored[self::keyOf($this->tenantContext->require()->toString(), $id->toString())] ?? null;
    }

    public function lockById(MediaId $id): ?MediaObject
    {
        $this->requireTransaction('Locking a media object needs an open transaction.');

        return $this->byId($id);
    }

    public function readyBySha256(string $sha256): ?MediaObject
    {
        $this->requireTransaction('Reading a media object needs an open transaction.');

        $tenant = $this->tenantContext->require();
        $matches = array_filter(
            $this->stored,
            static fn (MediaObject $mediaObject): bool => $mediaObject->organizationId()->equals($tenant) && MediaStatus::Ready === $mediaObject->status() && $sha256 === $mediaObject->sha256(),
        );
        usort($matches, static fn (MediaObject $left, MediaObject $right): int => [$left->createdAt(), $left->id()->toString()] <=> [$right->createdAt(), $right->id()->toString()]);

        return $matches[0] ?? null;
    }

    public function storedBytes(): int
    {
        $this->requireTransaction('Reading the stored media objects needs an open transaction.');

        $tenant = $this->tenantContext->require();
        $takingSpace = array_filter(
            $this->stored,
            static fn (MediaObject $mediaObject): bool => $mediaObject->organizationId()->equals($tenant) && MediaStatus::Rejected !== $mediaObject->status(),
        );

        return array_sum(array_map(static fn (MediaObject $mediaObject): int => $mediaObject->bytes(), $takingSpace));
    }

    /**
     * What the table holds, of every organization; for assertions only.
     *
     * @return list<MediaObject>
     */
    public function storedRows(): array
    {
        return array_values($this->stored);
    }

    private function requireTransaction(string $refusal): void
    {
        if (!$this->transactions->isOpen()) {
            throw new \LogicException($refusal);
        }
    }

    private static function keyOf(string $organizationId, string $mediaId): string
    {
        return $organizationId.' '.$mediaId;
    }
}
