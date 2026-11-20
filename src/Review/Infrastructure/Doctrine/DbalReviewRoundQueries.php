<?php

declare(strict_types=1);

namespace App\Review\Infrastructure\Doctrine;

use App\Review\Api\AreaView;
use App\Review\Api\ReviewRoundQueries;
use App\Review\Api\ReviewRoundView;
use App\Review\Api\RoundPinView;
use App\Review\Api\RoundScreenshotView;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketQueries;
use App\Ticketing\Api\TicketVisibilities;
use Doctrine\DBAL\Connection;

/**
 * One shared round from rvw_round, its areas from rvw_area joined with both pictures in rvw_screenshot, and the pins
 * that count from rvw_pin, through DBAL, after Ticketing's visibility check of the ticket. The round reads
 * (organization_id, ticket_id, number), the areas (organization_id, round_id, position) and the pins (organization_id,
 * screenshot_id, number); the round and area rows say whether rows lie below them, so none costs an empty query.
 */
final readonly class DbalReviewRoundQueries implements ReviewRoundQueries
{
    /** Whether the row of rvw_pin AS pin counts (PinToCheck::counts): a person's, or an AI pin a person kept. */
    public const string COUNTED_PIN = "(pin.source = 'human' OR pin.ai_state = 'kept')";

    public function __construct(
        private Connection $connection,
        private TenantContext $tenantContext,
        private TicketVisibilities $ticketVisibilities,
        private TicketQueries $ticketQueries,
    ) {
    }

    public function roundOf(TicketId $ticketId, int $number, UserId $viewer): ?ReviewRoundView
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('A review round is read in a transaction under the organization as tenant; none is open.');
        }
        $organizationId = $this->tenantContext->require();
        if ($number < 0) {
            return null;
        }
        if ([] === $this->ticketQueries->visibleTicketIdsOf($this->ticketVisibilities->of($organizationId, $viewer), [$ticketId])) {
            return null;
        }

        // An unshared draft, and so a deleted one, has no number: it never matches.
        $roundRow = $this->connection->fetchAssociative(
            'SELECT round.id, round.number, round.kind, round.owner_id, round.shared_at, round.state,'
            .' EXISTS (SELECT 1 FROM rvw_area AS area WHERE area.organization_id = round.organization_id AND area.round_id = round.id) AS has_areas'
            .' FROM rvw_round AS round'
            .' WHERE round.organization_id = CAST(:organization AS uuid) AND round.ticket_id = CAST(:ticket AS uuid)'
            .' AND round.number = :number AND round.deleted_at IS NULL',
            ['organization' => $organizationId->toString(), 'ticket' => $ticketId->toString(), 'number' => $number],
        );
        if (false === $roundRow) {
            return null;
        }
        $roundId = self::text($roundRow['id'], 'id');

        return new ReviewRoundView(
            $roundId,
            self::integer($roundRow['number'], 'number'),
            self::text($roundRow['kind'], 'kind'),
            new UserId(self::text($roundRow['owner_id'], 'owner_id')),
            new \DateTimeImmutable(self::text($roundRow['shared_at'], 'shared_at')),
            self::text($roundRow['state'], 'state'),
            self::boolean($roundRow['has_areas'], 'has_areas') ? $this->areasOf($organizationId, $roundId) : [],
        );
    }

    /**
     * @return list<AreaView> by position
     */
    private function areasOf(OrganizationId $organizationId, string $roundId): array
    {
        $screenshotColumns = static fn (string $alias): string => \sprintf(
            '%1$s.id AS %1$s_id, %1$s.media_id AS %1$s_media_id, %1$s.position AS %1$s_position, %1$s.variant_label AS %1$s_variant_label,'
            .' %1$s.verdict AS %1$s_verdict, %1$s.title AS %1$s_title, %1$s.summary AS %1$s_summary',
            $alias,
        );
        $areaRows = $this->connection->fetchAllAssociative(
            'SELECT area.id, area.name, area.position, area.verdict, area.note, area.decided_by, area.decided_at,'
            .' '.$screenshotColumns('after_shot').', '.$screenshotColumns('before_shot').', before_round.number AS before_round_number,'
            .' EXISTS (SELECT 1 FROM rvw_pin AS pin WHERE pin.organization_id = area.organization_id'
            .' AND pin.screenshot_id IN (area.before_screenshot_id, area.after_screenshot_id) AND '.self::COUNTED_PIN.') AS has_pins'
            .' FROM rvw_area AS area'
            .' JOIN rvw_screenshot AS after_shot ON after_shot.organization_id = area.organization_id AND after_shot.id = area.after_screenshot_id'
            // A "before" is an earlier round's "after" as the team saw it, so an earlier try of a shared draft reads as none.
            .' LEFT JOIN rvw_screenshot AS before_shot ON before_shot.organization_id = area.organization_id AND before_shot.id = area.before_screenshot_id'
            .' AND '.DbalRoundQueries::latestTrySql('before_shot')
            .' LEFT JOIN rvw_round AS before_round ON before_round.organization_id = before_shot.organization_id AND before_round.id = before_shot.round_id'
            .' WHERE area.organization_id = CAST(:organization AS uuid) AND area.round_id = CAST(:round AS uuid)'
            .' ORDER BY area.position',
            ['organization' => $organizationId->toString(), 'round' => $roundId],
        );
        $screenshotIdsWithPins = [];
        foreach ($areaRows as $areaRow) {
            if (self::boolean($areaRow['has_pins'], 'has_pins')) {
                $screenshotIdsWithPins[] = self::text($areaRow['after_shot_id'], 'after_shot_id');
                $beforeId = self::textOrNull($areaRow['before_shot_id'], 'before_shot_id');
                if (null !== $beforeId) {
                    $screenshotIdsWithPins[] = $beforeId;
                }
            }
        }
        $pinsByScreenshot = $this->countedPinsOf($organizationId, $screenshotIdsWithPins);

        return array_map(static function (array $areaRow) use ($pinsByScreenshot): AreaView {
            $decidedBy = self::textOrNull($areaRow['decided_by'], 'decided_by');
            $decidedAt = self::textOrNull($areaRow['decided_at'], 'decided_at');
            $before = null === $areaRow['before_shot_id'] ? null : self::screenshotOf($areaRow, 'before_shot', $pinsByScreenshot);

            return new AreaView(
                self::text($areaRow['id'], 'id'),
                self::text($areaRow['name'], 'name'),
                self::integer($areaRow['position'], 'position'),
                $before,
                null === $before ? null : self::integerOrNull($areaRow['before_round_number'], 'before_round_number'),
                self::screenshotOf($areaRow, 'after_shot', $pinsByScreenshot),
                self::textOrNull($areaRow['verdict'], 'verdict'),
                self::textOrNull($areaRow['note'], 'note'),
                null === $decidedBy ? null : new UserId($decidedBy),
                null === $decidedAt ? null : new \DateTimeImmutable($decidedAt),
            );
        }, $areaRows);
    }

    /**
     * @param array<string, mixed>              $areaRow
     * @param array<string, list<RoundPinView>> $pinsByScreenshot
     */
    private static function screenshotOf(array $areaRow, string $alias, array $pinsByScreenshot): RoundScreenshotView
    {
        $screenshotId = self::text($areaRow[$alias.'_id'], $alias.'_id');

        return new RoundScreenshotView(
            $screenshotId,
            self::text($areaRow[$alias.'_media_id'], $alias.'_media_id'),
            self::integer($areaRow[$alias.'_position'], $alias.'_position'),
            self::textOrNull($areaRow[$alias.'_variant_label'], $alias.'_variant_label'),
            self::textOrNull($areaRow[$alias.'_verdict'], $alias.'_verdict'),
            self::textOrNull($areaRow[$alias.'_title'], $alias.'_title'),
            self::textOrNull($areaRow[$alias.'_summary'], $alias.'_summary'),
            $pinsByScreenshot[$screenshotId] ?? [],
        );
    }

    /**
     * @param list<string> $screenshotIds
     *
     * @return array<string, list<RoundPinView>> by screenshot id, each by number
     */
    private function countedPinsOf(OrganizationId $organizationId, array $screenshotIds): array
    {
        if ([] === $screenshotIds) {
            return [];
        }
        // pdo_pgsql binds no arrays, so the ids travel as one uuid[] literal; a uuid holds no comma or brace.
        $pinRows = $this->connection->fetchAllAssociative(
            'SELECT pin.id, pin.screenshot_id, pin.number, pin.x_pct, pin.y_pct, pin.note, pin.verdict, pin.source, pin.ai_state, pin.author_id, pin.fixed_on_try'
            .' FROM rvw_pin AS pin'
            .' WHERE pin.organization_id = CAST(:organization AS uuid) AND pin.screenshot_id = ANY(CAST(:screenshots AS uuid[]))'
            .' AND '.self::COUNTED_PIN
            .' ORDER BY pin.number, pin.id',
            ['organization' => $organizationId->toString(), 'screenshots' => '{'.implode(',', $screenshotIds).'}'],
        );
        $pinsByScreenshot = [];
        foreach ($pinRows as $pinRow) {
            $pinsByScreenshot[self::text($pinRow['screenshot_id'], 'screenshot_id')][] = new RoundPinView(
                self::text($pinRow['id'], 'id'),
                self::integer($pinRow['number'], 'number'),
                // NUMERIC(5, 2) comes back as its decimal text.
                (float) self::text($pinRow['x_pct'], 'x_pct'),
                (float) self::text($pinRow['y_pct'], 'y_pct'),
                self::text($pinRow['note'], 'note'),
                self::textOrNull($pinRow['verdict'], 'verdict'),
                self::text($pinRow['source'], 'source'),
                self::textOrNull($pinRow['ai_state'], 'ai_state'),
                new UserId(self::text($pinRow['author_id'], 'author_id')),
                self::integerOrNull($pinRow['fixed_on_try'], 'fixed_on_try'),
            );
        }

        return $pinsByScreenshot;
    }

    private static function text(mixed $value, string $column): string
    {
        if (!\is_string($value)) {
            throw new \LogicException(\sprintf('A review round %s from the database is not a string.', $column));
        }

        return $value;
    }

    private static function textOrNull(mixed $value, string $column): ?string
    {
        return null === $value ? null : self::text($value, $column);
    }

    private static function integer(mixed $value, string $column): int
    {
        if (!\is_int($value)) {
            throw new \LogicException(\sprintf('A review round %s from the database is not an integer.', $column));
        }

        return $value;
    }

    private static function integerOrNull(mixed $value, string $column): ?int
    {
        return null === $value ? null : self::integer($value, $column);
    }

    private static function boolean(mixed $value, string $column): bool
    {
        if (!\is_bool($value)) {
            throw new \LogicException(\sprintf('A review round %s from the database is not a boolean.', $column));
        }

        return $value;
    }
}
