<?php

declare(strict_types=1);

namespace App\Review\Infrastructure\Doctrine;

use App\Review\Domain\AiPinState;
use App\Review\Domain\DraftDecision;
use App\Review\Domain\Pin;
use App\Review\Domain\PinId;
use App\Review\Domain\PinSource;
use App\Review\Domain\PinVerdict;
use App\Review\Domain\Round;
use App\Review\Domain\RoundChanged;
use App\Review\Domain\RoundId;
use App\Review\Domain\RoundKind;
use App\Review\Domain\Rounds;
use App\Review\Domain\RoundSlotTaken;
use App\Review\Domain\RoundState;
use App\Review\Domain\Screenshot;
use App\Review\Domain\ScreenshotId;
use App\Review\Domain\ScreenshotVerdict;
use App\Review\Domain\VariantLabel;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\Uid\Uuid;

/**
 * Rounds on rvw_round, rvw_screenshot and rvw_pin through DBAL; no entity maps them (doctrine.yaml schema_filter).
 * Adding a round takes three statements whatever its size: the round, all its screenshots, all their pins. At
 * Round::MAX_SCREENSHOTS times Screenshot::MAX_PINS pins the last one binds 60,000 values, under PostgreSQL's 65,535.
 * Reads name organization_id as well as row-level security, so each one uses an index that starts with it.
 */
final readonly class DbalRounds implements Rounds
{
    private const string TIMESTAMP = 'Y-m-d H:i:sP';

    private const string ROUND_COLUMNS = 'id, organization_id, ticket_id, kind, owner_id, number, shared_at, state, version, created_at, deleted_at, decision, decided_at';

    private const array SCREENSHOT_COLUMNS = ['organization_id', 'id', 'round_id', 'media_id', 'position', 'variant_label', 'verdict', 'title', 'summary', 'created_at'];

    private const array PIN_COLUMNS = ['organization_id', 'id', 'screenshot_id', 'number', 'x_pct', 'y_pct', 'note', 'verdict', 'source', 'ai_state', 'author_id', 'created_at'];

    public function __construct(private Connection $connection, private TenantContext $tenantContext)
    {
    }

    public function nextIdentity(): RoundId
    {
        return new RoundId(Uuid::v7()->toRfc4122());
    }

    public function nextScreenshotIdentity(): ScreenshotId
    {
        return new ScreenshotId(Uuid::v7()->toRfc4122());
    }

    public function nextPinIdentity(): PinId
    {
        return new PinId(Uuid::v7()->toRfc4122());
    }

    public function add(Round $round): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('A round is added in a transaction under its organization as tenant; none is open.');
        }

        // A savepoint inside the caller's transaction, so a refused screenshot or pin leaves no half round behind even
        // when the caller goes on.
        $this->connection->transactional(function () use ($round): void {
            $organizationId = $round->organizationId()->toString();
            $roundId = $round->id()->toString();
            $createdAt = $round->createdAt()->format(self::TIMESTAMP);
            // DO NOTHING on any unique index: the number one and the one-open-draft one are the slots a round takes. A
            // conflict target names one index only, so the primary key is told apart from the slots after the fact.
            $inserted = $this->connection->executeStatement(
                'INSERT INTO rvw_round (organization_id, id, ticket_id, number, kind, owner_id, shared_at, state, version, created_at, deleted_at, decision, decided_at)'
                .' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT DO NOTHING',
                [
                    $organizationId,
                    $roundId,
                    $round->ticketId()->toString(),
                    $round->number(),
                    $round->kind()->value,
                    $round->ownerId()->toString(),
                    $round->sharedAt()?->format(self::TIMESTAMP),
                    $round->state()->value,
                    $round->version(),
                    $createdAt,
                    $round->deletedAt()?->format(self::TIMESTAMP),
                    $round->decision()?->value,
                    $round->decidedAt()?->format(self::TIMESTAMP),
                ],
            );
            if (1 !== $inserted) {
                // A round id comes from nextIdentity once, so meeting it again is a bug or a redelivered message, never
                // a ticket's slot the person could be told about.
                if (false !== $this->connection->fetchOne('SELECT 1 FROM rvw_round WHERE organization_id = ? AND id = ?', [$organizationId, $roundId])) {
                    throw new \LogicException(\sprintf('Round %s is added already; a round is added once.', $roundId));
                }

                throw new RoundSlotTaken('The ticket has this round already.');
            }

            $this->insertRows('rvw_screenshot', self::SCREENSHOT_COLUMNS, array_map(
                fn (Screenshot $screenshot): array => $this->screenshotRow($organizationId, $roundId, $screenshot, $createdAt),
                $round->screenshots(),
            ));
            $this->insertRows('rvw_pin', self::PIN_COLUMNS, $this->pinRowsOf($organizationId, $round->screenshots(), $createdAt));
        });
    }

    public function get(RoundId $id): ?Round
    {
        $organizationId = $this->requireTransactionAndTenant();
        $roundRow = $this->connection->fetchAssociative(
            'SELECT '.self::ROUND_COLUMNS.' FROM rvw_round WHERE organization_id = ? AND id = ?',
            [$organizationId->toString(), $id->toString()],
        );

        return false === $roundRow ? null : $this->roundOf($organizationId, $roundRow);
    }

    public function openUnsharedDraftOf(TicketId $ticketId, UserId $ownerId): ?Round
    {
        $organizationId = $this->requireTransactionAndTenant();
        // The predicate of rvw_round_ticket_id_owner_id_open_draft_key, which holds at most one such row.
        $roundRow = $this->connection->fetchAssociative(
            'SELECT '.self::ROUND_COLUMNS.' FROM rvw_round WHERE organization_id = ? AND ticket_id = ? AND owner_id = ?'
            ." AND kind = 'draft' AND shared_at IS NULL AND state = 'open'",
            [$organizationId->toString(), $ticketId->toString(), $ownerId->toString()],
        );

        return false === $roundRow ? null : $this->roundOf($organizationId, $roundRow);
    }

    public function nextFreeNumberOf(TicketId $ticketId): int
    {
        $organizationId = $this->requireTransactionAndTenant();
        // An advisory lock, not a row lock: the number about to be taken has no row yet. Held to the end of the
        // transaction, so the next caller's max() below runs after this one's share is committed.
        $this->connection->executeStatement('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [
            \sprintf('rvw_round_number:%s:%s', $organizationId->toString(), $ticketId->toString()),
        ]);

        return self::integer($this->connection->fetchOne(
            'SELECT COALESCE(MAX(number), 0) + 1 FROM rvw_round WHERE organization_id = ? AND ticket_id = ?',
            [$organizationId->toString(), $ticketId->toString()],
        ), 'next number');
    }

    public function save(Round $round, \DateTimeImmutable $savedAt): void
    {
        $organizationId = $this->requireTransactionAndTenant()->toString();

        // A savepoint, as in add(): a refused change leaves the caller's transaction usable and nothing of itself.
        $this->connection->transactional(function () use ($round, $organizationId, $savedAt): void {
            $roundId = $round->id()->toString();
            try {
                // The UPDATE also locks the row, so a second save of the round waits here and then finds the version moved.
                $updated = $this->connection->executeStatement(
                    'UPDATE rvw_round SET number = ?, shared_at = ?, state = ?, version = ?, deleted_at = ?, decision = ?, decided_at = ?'
                    .' WHERE organization_id = ? AND id = ? AND version = ?',
                    [
                        $round->number(),
                        $round->sharedAt()?->format(self::TIMESTAMP),
                        $round->state()->value,
                        $round->version(),
                        $round->deletedAt()?->format(self::TIMESTAMP),
                        $round->decision()?->value,
                        $round->decidedAt()?->format(self::TIMESTAMP),
                        $organizationId,
                        $roundId,
                        $round->savedVersion(),
                    ],
                );
            } catch (UniqueConstraintViolationException $taken) {
                throw new RoundSlotTaken('The ticket has this round already.', 0, $taken);
            }
            if (1 !== $updated) {
                throw new RoundChanged('The round changed since it was read.');
            }

            $this->syncScreenshotsAndPins($organizationId, $round, $savedAt->format(self::TIMESTAMP));
        });
    }

    /**
     * Removes first, so a new row never meets one that is going; then updates, then adds.
     */
    private function syncScreenshotsAndPins(string $organizationId, Round $round, string $savedAt): void
    {
        $roundId = $round->id()->toString();
        $storedScreenshots = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT id, media_id, position, variant_label, verdict, title, summary FROM rvw_screenshot WHERE organization_id = ? AND round_id = ?',
            [$organizationId, $roundId],
        ) as $screenshotRow) {
            $storedScreenshots[self::text($screenshotRow['id'], 'id')] = [
                self::text($screenshotRow['media_id'], 'media_id'),
                self::integer($screenshotRow['position'], 'position'),
                self::textOrNull($screenshotRow['variant_label'], 'variant_label'),
                self::textOrNull($screenshotRow['verdict'], 'verdict'),
                self::textOrNull($screenshotRow['title'], 'title'),
                self::textOrNull($screenshotRow['summary'], 'summary'),
            ];
        }
        $storedPins = [];
        foreach ($this->pinRowsOfRound($organizationId, $roundId) as $pinRow) {
            $storedPins[self::text($pinRow['id'], 'id')] = self::pinFields($pinRow);
        }

        $keptScreenshotIds = [];
        $changedScreenshots = [];
        $newScreenshots = [];
        $keptPinIds = [];
        $changedPins = [];
        $newPinRows = [];
        foreach ($round->screenshots() as $screenshot) {
            $screenshotId = $screenshot->id()->toString();
            $keptScreenshotIds[$screenshotId] = true;
            $stored = $storedScreenshots[$screenshotId] ?? null;
            if (null === $stored) {
                $newScreenshots[] = $this->screenshotRow($organizationId, $roundId, $screenshot, $savedAt);
            } elseif ([$stored[0], $stored[1]] !== [$screenshot->mediaId(), $screenshot->position()]) {
                // Only the UPDATE below changes a stored row, and it leaves both alone; a move would be lost silently.
                throw new \LogicException(\sprintf('Screenshot %s keeps its picture and position once stored.', $screenshotId));
            } elseif (\array_slice($stored, 2) !== [$screenshot->variantLabel()?->value, $screenshot->verdict()?->value, $screenshot->title(), $screenshot->summary()]) {
                $changedScreenshots[] = $screenshot;
            }
            foreach ($screenshot->pins() as $pin) {
                $pinId = $pin->id()->toString();
                $keptPinIds[$pinId] = true;
                if (!isset($storedPins[$pinId])) {
                    $newPinRows[] = $this->pinRow($organizationId, $screenshotId, $pin, $savedAt);
                } elseif ($storedPins[$pinId] !== [$screenshotId, ...self::pinValues($pin)]) {
                    $changedPins[] = [$screenshotId, $pin];
                }
            }
        }

        // The pins of a removed screenshot go with it (ON DELETE CASCADE).
        $this->deleteByIds('rvw_pin', $organizationId, array_keys(array_diff_key($storedPins, $keptPinIds)));
        $this->deleteByIds('rvw_screenshot', $organizationId, array_keys(array_diff_key($storedScreenshots, $keptScreenshotIds)));
        foreach ($changedScreenshots as $screenshot) {
            $this->connection->executeStatement(
                'UPDATE rvw_screenshot SET verdict = ?, title = ?, summary = ?, variant_label = ? WHERE organization_id = ? AND id = ?',
                [$screenshot->verdict()?->value, $screenshot->title(), $screenshot->summary(), $screenshot->variantLabel()?->value, $organizationId, $screenshot->id()->toString()],
            );
        }
        foreach ($changedPins as [$screenshotId, $pin]) {
            $this->connection->executeStatement(
                'UPDATE rvw_pin SET screenshot_id = ?, number = ?, x_pct = ?, y_pct = ?, note = ?, verdict = ?, source = ?, ai_state = ?, author_id = ? WHERE organization_id = ? AND id = ?',
                [$screenshotId, ...self::pinValues($pin), $organizationId, $pin->id()->toString()],
            );
        }
        $this->insertRows('rvw_screenshot', self::SCREENSHOT_COLUMNS, $newScreenshots);
        $this->insertRows('rvw_pin', self::PIN_COLUMNS, $newPinRows);
    }

    /**
     * @param list<string> $ids
     */
    private function deleteByIds(string $table, string $organizationId, array $ids): void
    {
        if ([] === $ids) {
            return;
        }
        // pdo_pgsql binds no arrays, so the ids travel as one uuid[] literal; a uuid holds no comma or brace.
        $this->connection->executeStatement(
            \sprintf('DELETE FROM %s WHERE organization_id = ? AND id = ANY(CAST(? AS uuid[]))', $table),
            [$organizationId, '{'.implode(',', $ids).'}'],
        );
    }

    /**
     * @param array<string, mixed> $roundRow
     */
    private function roundOf(OrganizationId $organizationId, array $roundRow): Round
    {
        $roundId = self::text($roundRow['id'], 'id');
        $pinsByScreenshot = [];
        foreach ($this->pinRowsOfRound($organizationId->toString(), $roundId) as $pinRow) {
            $pinsByScreenshot[self::text($pinRow['screenshot_id'], 'screenshot_id')][] = Pin::restored(
                new PinId(self::text($pinRow['id'], 'id')),
                self::integer($pinRow['number'], 'number'),
                // NUMERIC(5, 2) comes back as its decimal text.
                (float) self::text($pinRow['x_pct'], 'x_pct'),
                (float) self::text($pinRow['y_pct'], 'y_pct'),
                self::text($pinRow['note'], 'note'),
                new UserId(self::text($pinRow['author_id'], 'author_id')),
                PinSource::from(self::text($pinRow['source'], 'source')),
                self::enumOrNull(AiPinState::class, $pinRow['ai_state'], 'ai_state'),
                self::enumOrNull(PinVerdict::class, $pinRow['verdict'], 'verdict'),
            );
        }
        $screenshots = array_map(static function (array $screenshotRow) use ($pinsByScreenshot): Screenshot {
            $screenshotId = self::text($screenshotRow['id'], 'id');

            return Screenshot::restored(
                new ScreenshotId($screenshotId),
                self::text($screenshotRow['media_id'], 'media_id'),
                self::integer($screenshotRow['position'], 'position'),
                self::textOrNull($screenshotRow['title'], 'title'),
                self::textOrNull($screenshotRow['summary'], 'summary'),
                $pinsByScreenshot[$screenshotId] ?? [],
                self::enumOrNull(VariantLabel::class, $screenshotRow['variant_label'], 'variant_label'),
                self::enumOrNull(ScreenshotVerdict::class, $screenshotRow['verdict'], 'verdict'),
            );
        }, $this->connection->fetchAllAssociative(
            'SELECT id, media_id, position, variant_label, verdict, title, summary FROM rvw_screenshot WHERE organization_id = ? AND round_id = ? ORDER BY position',
            [$organizationId->toString(), $roundId],
        ));

        return Round::restored(
            new RoundId($roundId),
            new OrganizationId(self::text($roundRow['organization_id'], 'organization_id')),
            new TicketId(self::text($roundRow['ticket_id'], 'ticket_id')),
            RoundKind::from(self::text($roundRow['kind'], 'kind')),
            new UserId(self::text($roundRow['owner_id'], 'owner_id')),
            self::integerOrNull($roundRow['number'], 'number'),
            self::timeOrNull($roundRow['shared_at'], 'shared_at'),
            RoundState::from(self::text($roundRow['state'], 'state')),
            self::integer($roundRow['version'], 'version'),
            $screenshots,
            self::timeOrNull($roundRow['created_at'], 'created_at') ?? throw new \LogicException('A round created_at from the database is null.'),
            self::timeOrNull($roundRow['deleted_at'], 'deleted_at'),
            self::enumOrNull(DraftDecision::class, $roundRow['decision'], 'decision'),
            self::timeOrNull($roundRow['decided_at'], 'decided_at'),
        );
    }

    /**
     * Served by rvw_screenshot_round_id_position_key and rvw_pin_screenshot_id_number_key.
     *
     * @return list<array<string, mixed>>
     */
    private function pinRowsOfRound(string $organizationId, string $roundId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT pin.id, pin.screenshot_id, pin.number, pin.x_pct, pin.y_pct, pin.note, pin.verdict, pin.source, pin.ai_state, pin.author_id'
            .' FROM rvw_pin AS pin JOIN rvw_screenshot AS screenshot ON screenshot.organization_id = pin.organization_id AND screenshot.id = pin.screenshot_id'
            .' WHERE screenshot.organization_id = ? AND screenshot.round_id = ? ORDER BY pin.number',
            [$organizationId, $roundId],
        );
    }

    /**
     * @return list<string|int|null>
     */
    private function screenshotRow(string $organizationId, string $roundId, Screenshot $screenshot, string $createdAt): array
    {
        return [
            $organizationId,
            $screenshot->id()->toString(),
            $roundId,
            $screenshot->mediaId(),
            $screenshot->position(),
            $screenshot->variantLabel()?->value,
            $screenshot->verdict()?->value,
            $screenshot->title(),
            $screenshot->summary(),
            $createdAt,
        ];
    }

    /**
     * @param list<Screenshot> $screenshots
     *
     * @return list<list<string|int|null>>
     */
    private function pinRowsOf(string $organizationId, array $screenshots, string $createdAt): array
    {
        $pinRows = [];
        foreach ($screenshots as $screenshot) {
            foreach ($screenshot->pins() as $pin) {
                $pinRows[] = $this->pinRow($organizationId, $screenshot->id()->toString(), $pin, $createdAt);
            }
        }

        return $pinRows;
    }

    /**
     * @return list<string|int|null>
     */
    private function pinRow(string $organizationId, string $screenshotId, Pin $pin, string $createdAt): array
    {
        return [$organizationId, $pin->id()->toString(), $screenshotId, ...self::pinValues($pin), $createdAt];
    }

    /**
     * The pin's columns from number to author_id, in PIN_COLUMNS order, as they are stored.
     *
     * @return list<string|int|null>
     */
    private static function pinValues(Pin $pin): array
    {
        return [
            $pin->number(),
            // A string, so the numeric column gets the two decimals as they are, never a float's tail.
            number_format($pin->xPercent(), 2, '.', ''),
            number_format($pin->yPercent(), 2, '.', ''),
            $pin->note(),
            $pin->verdict()?->value,
            $pin->source()->value,
            $pin->aiState()?->value,
            $pin->authorId()->toString(),
        ];
    }

    /**
     * @param array<string, mixed> $pinRow
     *
     * @return list<string|int|null> the screenshot id, then the values pinValues gives
     */
    private static function pinFields(array $pinRow): array
    {
        return [
            self::text($pinRow['screenshot_id'], 'screenshot_id'),
            self::integer($pinRow['number'], 'number'),
            self::text($pinRow['x_pct'], 'x_pct'),
            self::text($pinRow['y_pct'], 'y_pct'),
            self::text($pinRow['note'], 'note'),
            self::textOrNull($pinRow['verdict'], 'verdict'),
            self::text($pinRow['source'], 'source'),
            self::textOrNull($pinRow['ai_state'], 'ai_state'),
            self::text($pinRow['author_id'], 'author_id'),
        ];
    }

    /**
     * @param list<string>                      $columns
     * @param list<list<string|int|float|null>> $rows    each with a value per column, in the columns' order
     */
    private function insertRows(string $table, array $columns, array $rows): void
    {
        if ([] === $rows) {
            return;
        }
        $rowPlaceholders = '('.implode(', ', array_fill(0, \count($columns), '?')).')';
        $this->connection->executeStatement(
            \sprintf('INSERT INTO %s (%s) VALUES %s', $table, implode(', ', $columns), implode(', ', array_fill(0, \count($rows), $rowPlaceholders))),
            array_merge(...$rows),
        );
    }

    private function requireTransactionAndTenant(): OrganizationId
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Rounds are read and saved in a transaction under the organization as tenant; none is open.');
        }

        return $this->tenantContext->require();
    }

    private static function text(mixed $value, string $column): string
    {
        return \is_string($value) ? $value : throw new \LogicException(\sprintf('A round %s from the database is not a string.', $column));
    }

    private static function textOrNull(mixed $value, string $column): ?string
    {
        return null === $value ? null : self::text($value, $column);
    }

    private static function integer(mixed $value, string $column): int
    {
        return \is_int($value) ? $value : throw new \LogicException(\sprintf('A round %s from the database is not an integer.', $column));
    }

    private static function integerOrNull(mixed $value, string $column): ?int
    {
        return null === $value ? null : self::integer($value, $column);
    }

    private static function timeOrNull(mixed $value, string $column): ?\DateTimeImmutable
    {
        return null === $value ? null : new \DateTimeImmutable(self::text($value, $column));
    }

    /**
     * @template T of \BackedEnum
     *
     * @param class-string<T> $enum
     *
     * @return T|null
     *
     * @throws \ValueError when the stored value is none of the enum's
     */
    private static function enumOrNull(string $enum, mixed $value, string $column): ?\BackedEnum
    {
        $text = self::textOrNull($value, $column);

        return null === $text ? null : $enum::from($text);
    }
}
