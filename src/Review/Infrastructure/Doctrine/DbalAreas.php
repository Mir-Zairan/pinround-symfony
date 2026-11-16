<?php

declare(strict_types=1);

namespace App\Review\Infrastructure\Doctrine;

use App\Review\Domain\AiPinState;
use App\Review\Domain\Area;
use App\Review\Domain\AreaId;
use App\Review\Domain\Areas;
use App\Review\Domain\AreaVerdict;
use App\Review\Domain\PinId;
use App\Review\Domain\PinSource;
use App\Review\Domain\PinToCheck;
use App\Review\Domain\PinVerdict;
use App\Review\Domain\ReviewedRound;
use App\Review\Domain\RoundId;
use App\Review\Domain\RoundKind;
use App\Review\Domain\RoundState;
use App\Review\Domain\ScreenshotId;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use Doctrine\DBAL\Connection;

/**
 * Areas on rvw_area, rvw_round and rvw_pin through DBAL; no entity maps them (doctrine.yaml schema_filter). Every
 * statement names organization_id as well as row-level security, so each one uses an index that starts with it.
 */
final readonly class DbalAreas implements Areas
{
    private const string TIMESTAMP = 'Y-m-d H:i:sP';

    public function __construct(private Connection $connection, private TenantContext $tenantContext)
    {
    }

    public function lockRound(RoundId $roundId): ?ReviewedRound
    {
        $organizationId = $this->requireTransactionAndTenant();
        $roundRow = $this->connection->fetchAssociative(
            'SELECT id, ticket_id, kind, shared_at, state, deleted_at FROM rvw_round WHERE organization_id = ? AND id = ? FOR UPDATE',
            [$organizationId, $roundId->toString()],
        );
        if (false === $roundRow) {
            return null;
        }

        return new ReviewedRound(
            new RoundId(self::text($roundRow['id'], 'id')),
            new TicketId(self::text($roundRow['ticket_id'], 'ticket_id')),
            RoundKind::from(self::text($roundRow['kind'], 'kind')),
            null !== $roundRow['shared_at'],
            RoundState::from(self::text($roundRow['state'], 'state')),
            null !== $roundRow['deleted_at'],
        );
    }

    public function areaOf(RoundId $roundId, AreaId $areaId): ?Area
    {
        $organizationId = $this->requireTransactionAndTenant();
        $areaRow = $this->connection->fetchAssociative(
            'SELECT id, round_id, name, position, before_screenshot_id, after_screenshot_id, verdict, note, decided_by, decided_at'
            .' FROM rvw_area WHERE organization_id = ? AND id = ? AND round_id = ?',
            [$organizationId, $areaId->toString(), $roundId->toString()],
        );
        if (false === $areaRow) {
            return null;
        }
        $beforeScreenshotId = self::textOrNull($areaRow['before_screenshot_id'], 'before_screenshot_id');
        $verdict = self::textOrNull($areaRow['verdict'], 'verdict');
        $decidedBy = self::textOrNull($areaRow['decided_by'], 'decided_by');
        $decidedAt = self::textOrNull($areaRow['decided_at'], 'decided_at');

        return Area::restored(
            new AreaId(self::text($areaRow['id'], 'id')),
            new RoundId(self::text($areaRow['round_id'], 'round_id')),
            self::text($areaRow['name'], 'name'),
            self::integer($areaRow['position'], 'position'),
            null === $beforeScreenshotId ? null : new ScreenshotId($beforeScreenshotId),
            new ScreenshotId(self::text($areaRow['after_screenshot_id'], 'after_screenshot_id')),
            null === $verdict ? null : AreaVerdict::from($verdict),
            self::textOrNull($areaRow['note'], 'note'),
            null === $decidedBy ? null : new UserId($decidedBy),
            null === $decidedAt ? null : new \DateTimeImmutable($decidedAt),
        );
    }

    public function saveVerdict(Area $area): void
    {
        $organizationId = $this->requireTransactionAndTenant();
        $updated = $this->connection->executeStatement(
            'UPDATE rvw_area SET verdict = ?, note = ?, decided_by = ?, decided_at = ? WHERE organization_id = ? AND id = ? AND round_id = ?',
            [
                $area->verdict()?->value,
                $area->note(),
                $area->decidedBy()?->toString(),
                $area->decidedAt()?->format(self::TIMESTAMP),
                $organizationId,
                $area->id()->toString(),
                $area->roundId()->toString(),
            ],
        );
        if (1 !== $updated) {
            throw new \LogicException(\sprintf('Area %s is not stored; a verdict is saved on an area read before.', $area->id()->toString()));
        }
    }

    public function pinToCheckOf(RoundId $roundId, PinId $pinId): ?PinToCheck
    {
        $organizationId = $this->requireTransactionAndTenant();
        // EXISTS, as two areas of a round may compare against one "before" picture. The pin by its key, then the
        // areas by rvw_area_before_screenshot_id_idx.
        $pinRow = $this->connection->fetchAssociative(
            'SELECT pin.id, pin.source, pin.ai_state, pin.verdict FROM rvw_pin AS pin WHERE pin.organization_id = ? AND pin.id = ?'
            .' AND EXISTS (SELECT 1 FROM rvw_area AS area WHERE area.organization_id = pin.organization_id AND area.before_screenshot_id = pin.screenshot_id AND area.round_id = ?)',
            [$organizationId, $pinId->toString(), $roundId->toString()],
        );
        if (false === $pinRow) {
            return null;
        }
        $aiState = self::textOrNull($pinRow['ai_state'], 'ai_state');
        $verdict = self::textOrNull($pinRow['verdict'], 'verdict');

        return new PinToCheck(
            new PinId(self::text($pinRow['id'], 'id')),
            PinSource::from(self::text($pinRow['source'], 'source')),
            null === $aiState ? null : AiPinState::from($aiState),
            null === $verdict ? null : PinVerdict::from($verdict),
        );
    }

    public function savePinVerdict(PinToCheck $read, PinToCheck $changed): bool
    {
        $organizationId = $this->requireTransactionAndTenant();
        if (!$read->id()->equals($changed->id())) {
            throw new \LogicException('A pin verdict is saved over the same pin it was read from.');
        }

        // IS NOT DISTINCT FROM, so a pin read with no verdict matches NULL. The UPDATE locks the row, so a second save
        // waits here and then finds the verdict moved.
        $updated = $this->connection->executeStatement(
            'UPDATE rvw_pin SET verdict = ? WHERE organization_id = ? AND id = ? AND verdict IS NOT DISTINCT FROM ?',
            [$changed->verdict()?->value, $organizationId, $read->id()->toString(), $read->verdict()?->value],
        );

        return 1 === $updated;
    }

    private function requireTransactionAndTenant(): string
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Areas and verdicts are read and saved in a transaction under the organization as tenant; none is open.');
        }

        return $this->tenantContext->require()->toString();
    }

    private static function text(mixed $value, string $column): string
    {
        return \is_string($value) ? $value : throw new \LogicException(\sprintf('An area %s from the database is not a string.', $column));
    }

    private static function textOrNull(mixed $value, string $column): ?string
    {
        return null === $value ? null : self::text($value, $column);
    }

    private static function integer(mixed $value, string $column): int
    {
        return \is_int($value) ? $value : throw new \LogicException(\sprintf('An area %s from the database is not an integer.', $column));
    }
}
