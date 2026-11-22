<?php

declare(strict_types=1);

namespace App\Review\Infrastructure\Doctrine;

use App\Review\Api\GeneralNoteCommands;
use App\Review\Api\GeneralNoteQueries;
use App\Review\Api\GeneralNoteView;
use App\Review\Domain\GeneralNote;
use App\Review\Domain\GeneralNoteId;
use App\Review\Domain\GeneralNotes;
use App\Review\Domain\ReviewedRound;
use App\Review\Domain\RoundId;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\UserId;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * GeneralNotes and GeneralNoteQueries on rvw_round_note through DBAL; no entity maps it (doctrine.yaml schema_filter).
 * Every statement names organization_id as well as row-level security, so each one uses
 * rvw_round_note_round_id_created_at_idx. The round is locked as DbalAreas locks it for a verdict.
 */
final readonly class DbalGeneralNotes implements GeneralNotes, GeneralNoteQueries
{
    private const string TIMESTAMP = 'Y-m-d H:i:sP';

    public function __construct(
        private Connection $connection,
        private TenantContext $tenantContext,
        private DbalAreas $areas,
    ) {
    }

    public function nextId(): GeneralNoteId
    {
        return new GeneralNoteId(Uuid::v7()->toRfc4122());
    }

    public function lockRound(RoundId $roundId): ?ReviewedRound
    {
        return $this->areas->lockRound($roundId);
    }

    public function countOn(RoundId $roundId): int
    {
        $organizationId = $this->requireTransactionAndTenant();
        $count = $this->connection->fetchOne(
            'SELECT count(*) FROM rvw_round_note WHERE organization_id = ? AND round_id = ?',
            [$organizationId, $roundId->toString()],
        );

        return \is_int($count) ? $count : throw new \LogicException('A count of general notes from the database is not a whole number.');
    }

    public function add(GeneralNote $note): void
    {
        $organizationId = $this->requireTransactionAndTenant();
        $this->connection->executeStatement(
            'INSERT INTO rvw_round_note (organization_id, id, round_id, author_id, verdict, note, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $organizationId,
                $note->id->toString(),
                $note->roundId->toString(),
                $note->authorId->toString(),
                $note->verdict?->value,
                $note->note,
                $note->createdAt->format(self::TIMESTAMP),
            ],
        );
    }

    public function notesOf(string $roundId): array
    {
        $organizationId = $this->requireTransactionAndTenant();
        try {
            $id = new RoundId($roundId);
        } catch (\InvalidArgumentException) {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, author_id, verdict, note, created_at FROM rvw_round_note WHERE organization_id = ? AND round_id = ?'
            .' ORDER BY created_at DESC, id DESC LIMIT '.GeneralNoteCommands::MAX_PER_ROUND,
            [$organizationId, $id->toString()],
        );

        return array_map(static fn (array $row): GeneralNoteView => new GeneralNoteView(
            self::text($row['id'], 'id'),
            new UserId(self::text($row['author_id'], 'author_id')),
            null === $row['verdict'] ? null : self::text($row['verdict'], 'verdict'),
            self::text($row['note'], 'note'),
            new \DateTimeImmutable(self::text($row['created_at'], 'created_at')),
        ), $rows);
    }

    private function requireTransactionAndTenant(): string
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('General notes are read and added in a transaction under the organization as tenant; none is open.');
        }

        return $this->tenantContext->require()->toString();
    }

    private static function text(mixed $value, string $column): string
    {
        return \is_string($value) ? $value : throw new \LogicException(\sprintf('A general note %s from the database is not a string.', $column));
    }
}
