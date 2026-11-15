<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Application;

use App\Media\Domain\UploadIntent;
use App\Media\Domain\UploadIntentId;
use App\Media\Domain\UploadIntents;
use App\Media\Domain\UploadIntentUnusable;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\UserId;
use Symfony\Component\Uid\Uuid;

/**
 * UploadIntents as DoctrineUploadIntents behaves on the real table: it needs an open transaction, sees and writes only
 * the tenant's rows, refuses a second row with the same key, and recordUse() checks what is stored, not the copy. Each
 * stored intent and each open count is written to the PortCallLog.
 */
final class InMemoryUploadIntents implements UploadIntents
{
    /** @var array<string, UploadIntent> by organization id and intent id, as the table's primary key */
    private array $stored = [];

    public function __construct(private readonly InMemoryTransactions $transactions, private readonly TenantContext $tenantContext, private readonly PortCallLog $portCallLog)
    {
        $transactions->enlist(function (): \Closure {
            $snapshot = array_map(static fn (UploadIntent $intent): UploadIntent => clone $intent, $this->stored);

            return function () use ($snapshot): void {
                $this->stored = $snapshot;
            };
        });
    }

    public function nextIdentity(): UploadIntentId
    {
        return new UploadIntentId(Uuid::v7()->toRfc4122());
    }

    public function add(UploadIntent $intent): void
    {
        $this->requireTransaction('Adding an upload intent needs an open transaction.');
        $tenantId = $this->tenantContext->require()->toString();
        if ($intent->organizationId()->toString() !== $tenantId) {
            throw new \RuntimeException('new row violates row-level security policy for table "med_upload_intent"');
        }
        $key = self::keyOf($tenantId, $intent->id()->toString());
        if (isset($this->stored[$key])) {
            throw new \RuntimeException('duplicate key value violates unique constraint "med_upload_intent_pkey"');
        }

        $this->stored[$key] = $intent;
        $this->portCallLog->record('add '.$intent->id()->toString());
    }

    public function byId(UploadIntentId $id): ?UploadIntent
    {
        $this->requireTransaction('Reading an upload intent needs an open transaction.');

        return $this->stored[self::keyOf($this->tenantContext->require()->toString(), $id->toString())] ?? null;
    }

    public function recordUse(UploadIntent $intent, \DateTimeImmutable $at): void
    {
        $this->requireTransaction('Using an upload intent needs an open transaction.');

        $storedIntent = $this->stored[self::keyOf($this->tenantContext->require()->toString(), $intent->id()->toString())] ?? null;
        if (null === $storedIntent || !$storedIntent->organizationId()->equals($intent->organizationId())) {
            throw new \LogicException('The upload intent is not stored under the tenant.');
        }
        if (null !== $storedIntent->usedAt()) {
            throw UploadIntentUnusable::usedAlready();
        }
        if ($storedIntent->expiresAt() <= $at) {
            throw UploadIntentUnusable::expired();
        }

        $storedIntent->markUsed($at);
        if ($storedIntent !== $intent) {
            $intent->markUsed($at);
        }
    }

    public function openCountOf(UserId $userId, \DateTimeImmutable $at): int
    {
        $this->requireTransaction('Reading the open upload intents needs an open transaction.');
        $this->portCallLog->record('openCountOf '.$userId->toString());

        $tenant = $this->tenantContext->require();

        return \count(array_filter(
            $this->stored,
            static fn (UploadIntent $intent): bool => $intent->organizationId()->equals($tenant) && $intent->userId()->equals($userId) && null === $intent->usedAt() && $intent->expiresAt() > $at,
        ));
    }

    /**
     * What the table holds, of every organization; for assertions only.
     *
     * @return list<UploadIntent>
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

    private static function keyOf(string $organizationId, string $intentId): string
    {
        return $organizationId.' '.$intentId;
    }
}
