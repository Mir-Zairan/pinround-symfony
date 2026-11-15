<?php

declare(strict_types=1);

namespace App\Review\Infrastructure\Doctrine;

use App\Review\Application\RoundUploadTarget;
use App\Review\Application\RoundUploadTargets;
use App\Review\Domain\RoundId;
use App\Review\Domain\RoundKind;
use App\Review\Domain\RoundState;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use Doctrine\DBAL\Connection;

/**
 * The round is read by its primary key, naming organization_id as well as row-level security.
 */
final readonly class DbalRoundUploadTargets implements RoundUploadTargets
{
    public function __construct(private Connection $connection, private TenantContext $tenantContext)
    {
    }

    public function of(string $roundId): ?RoundUploadTarget
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('A round is read as an upload target in a transaction under its organization as tenant; none is open.');
        }
        $organizationId = $this->tenantContext->require();
        try {
            $id = new RoundId($roundId);
        } catch (\InvalidArgumentException) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT ticket_id, owner_id, kind = ? AND shared_at IS NULL AS is_unshared_draft, state = ? AS is_open'
            .' FROM rvw_round WHERE organization_id = ? AND id = ?',
            [RoundKind::Draft->value, RoundState::Open->value, $organizationId->toString(), $id->toString()],
        );
        if (false === $row) {
            return null;
        }

        return new RoundUploadTarget(
            new TicketId(self::text($row['ticket_id'], 'ticket_id')),
            new UserId(self::text($row['owner_id'], 'owner_id')),
            self::flag($row['is_unshared_draft'], 'is_unshared_draft'),
            self::flag($row['is_open'], 'is_open'),
        );
    }

    private static function text(mixed $value, string $column): string
    {
        return \is_string($value) ? $value : throw new \LogicException(\sprintf('A round %s from the database is not a string.', $column));
    }

    private static function flag(mixed $value, string $column): bool
    {
        return \is_bool($value) ? $value : throw new \LogicException(\sprintf('A round %s from the database is not a boolean.', $column));
    }
}
