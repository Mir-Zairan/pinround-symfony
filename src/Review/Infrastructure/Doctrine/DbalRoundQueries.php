<?php

declare(strict_types=1);

namespace App\Review\Infrastructure\Doctrine;

use App\Review\Api\RoundPinView;
use App\Review\Api\RoundQueries;
use App\Review\Api\RoundScreenshotView;
use App\Review\Api\RoundView;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketQueries;
use App\Ticketing\Api\TicketVisibilities;
use Doctrine\DBAL\Connection;

/**
 * The rounds of a ticket from rvw_round, rvw_screenshot and rvw_pin through DBAL, after Ticketing's batch visibility
 * check of the ticket. Each level reads the next one's index ((organization_id, ticket_id, number), (organization_id,
 * round_id, position), (organization_id, screenshot_id, number)) and says whether it has rows below it, so a round with
 * no screenshot or a screenshot with no pin costs no query.
 */
final readonly class DbalRoundQueries implements RoundQueries
{
    public function __construct(
        private Connection $connection,
        private TenantContext $tenantContext,
        private TicketVisibilities $ticketVisibilities,
        private TicketQueries $ticketQueries,
    ) {
    }

    public function forTicket(TicketId $ticketId, UserId $viewer): array
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Rounds are read in a transaction under the organization as tenant; none is open.');
        }
        $organizationId = $this->tenantContext->require();
        $visibility = $this->ticketVisibilities->of($organizationId, $viewer);
        if ([] === $this->ticketQueries->visibleTicketIdsOf($visibility, [$ticketId])) {
            return [];
        }

        // A round with no number is an unshared draft; only its owner gets it, after every shared round.
        $roundRows = $this->connection->fetchAllAssociative(
            'SELECT round.id, round.number, round.kind, round.owner_id, round.shared_at, round.state,'
            .' EXISTS (SELECT 1 FROM rvw_screenshot AS screenshot WHERE screenshot.organization_id = round.organization_id AND screenshot.round_id = round.id) AS has_screenshots'
            .' FROM rvw_round AS round'
            .' WHERE round.organization_id = CAST(:organization AS uuid) AND round.ticket_id = CAST(:ticket AS uuid)'
            .' AND (round.shared_at IS NOT NULL OR round.owner_id = CAST(:viewer AS uuid))'
            .' ORDER BY round.number NULLS LAST, round.id',
            ['organization' => $organizationId->toString(), 'ticket' => $ticketId->toString(), 'viewer' => $viewer->toString()],
        );
        $roundIdsWithScreenshots = [];
        foreach ($roundRows as $roundRow) {
            if (self::boolean($roundRow['has_screenshots'], 'has_screenshots')) {
                $roundIdsWithScreenshots[] = self::text($roundRow['id'], 'id');
            }
        }
        $screenshotsByRound = $this->screenshotsOf($organizationId->toString(), $roundIdsWithScreenshots);

        return array_map(static function (array $roundRow) use ($screenshotsByRound): RoundView {
            $roundId = self::text($roundRow['id'], 'id');
            $sharedAt = self::textOrNull($roundRow['shared_at'], 'shared_at');

            return new RoundView(
                $roundId,
                self::integerOrNull($roundRow['number'], 'number'),
                self::text($roundRow['kind'], 'kind'),
                new UserId(self::text($roundRow['owner_id'], 'owner_id')),
                null === $sharedAt ? null : new \DateTimeImmutable($sharedAt),
                self::text($roundRow['state'], 'state'),
                $screenshotsByRound[$roundId] ?? [],
            );
        }, $roundRows);
    }

    /**
     * @param list<string> $roundIds
     *
     * @return array<string, list<RoundScreenshotView>> by round id, each by position
     */
    private function screenshotsOf(string $organizationId, array $roundIds): array
    {
        if ([] === $roundIds) {
            return [];
        }
        // pdo_pgsql binds no arrays, so the ids travel as one uuid[] literal; a uuid holds no comma or brace.
        $screenshotRows = $this->connection->fetchAllAssociative(
            'SELECT screenshot.id, screenshot.round_id, screenshot.media_id, screenshot.position, screenshot.variant_label,'
            .' screenshot.verdict, screenshot.title, screenshot.summary,'
            .' EXISTS (SELECT 1 FROM rvw_pin AS pin WHERE pin.organization_id = screenshot.organization_id AND pin.screenshot_id = screenshot.id) AS has_pins'
            .' FROM rvw_screenshot AS screenshot'
            .' WHERE screenshot.organization_id = CAST(:organization AS uuid) AND screenshot.round_id = ANY(CAST(:rounds AS uuid[]))'
            .' ORDER BY screenshot.position, screenshot.id',
            ['organization' => $organizationId, 'rounds' => '{'.implode(',', $roundIds).'}'],
        );
        $screenshotIdsWithPins = [];
        foreach ($screenshotRows as $screenshotRow) {
            if (self::boolean($screenshotRow['has_pins'], 'has_pins')) {
                $screenshotIdsWithPins[] = self::text($screenshotRow['id'], 'id');
            }
        }
        $pinsByScreenshot = $this->pinsOf($organizationId, $screenshotIdsWithPins);

        $screenshotsByRound = [];
        foreach ($screenshotRows as $screenshotRow) {
            $screenshotId = self::text($screenshotRow['id'], 'id');
            $screenshotsByRound[self::text($screenshotRow['round_id'], 'round_id')][] = new RoundScreenshotView(
                $screenshotId,
                self::text($screenshotRow['media_id'], 'media_id'),
                self::integer($screenshotRow['position'], 'position'),
                self::textOrNull($screenshotRow['variant_label'], 'variant_label'),
                self::textOrNull($screenshotRow['verdict'], 'verdict'),
                self::textOrNull($screenshotRow['title'], 'title'),
                self::textOrNull($screenshotRow['summary'], 'summary'),
                $pinsByScreenshot[$screenshotId] ?? [],
            );
        }

        return $screenshotsByRound;
    }

    /**
     * @param list<string> $screenshotIds
     *
     * @return array<string, list<RoundPinView>> by screenshot id, each by number
     */
    private function pinsOf(string $organizationId, array $screenshotIds): array
    {
        if ([] === $screenshotIds) {
            return [];
        }
        $pinRows = $this->connection->fetchAllAssociative(
            'SELECT pin.id, pin.screenshot_id, pin.number, pin.x_pct, pin.y_pct, pin.note, pin.verdict, pin.source, pin.ai_state, pin.author_id'
            .' FROM rvw_pin AS pin'
            .' WHERE pin.organization_id = CAST(:organization AS uuid) AND pin.screenshot_id = ANY(CAST(:screenshots AS uuid[]))'
            .' ORDER BY pin.number, pin.id',
            ['organization' => $organizationId, 'screenshots' => '{'.implode(',', $screenshotIds).'}'],
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
            );
        }

        return $pinsByScreenshot;
    }

    private static function text(mixed $value, string $column): string
    {
        if (!\is_string($value)) {
            throw new \LogicException(\sprintf('A round %s from the database is not a string.', $column));
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
            throw new \LogicException(\sprintf('A round %s from the database is not an integer.', $column));
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
            throw new \LogicException(\sprintf('A round %s from the database is not a boolean.', $column));
        }

        return $value;
    }
}
