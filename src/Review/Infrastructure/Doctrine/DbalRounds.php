<?php

declare(strict_types=1);

namespace App\Review\Infrastructure\Doctrine;

use App\Review\Domain\PinId;
use App\Review\Domain\Round;
use App\Review\Domain\RoundId;
use App\Review\Domain\Rounds;
use App\Review\Domain\RoundSlotTaken;
use App\Review\Domain\ScreenshotId;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Rounds on rvw_round, rvw_screenshot and rvw_pin through DBAL; no entity maps them (doctrine.yaml schema_filter).
 * Adding a round takes three statements whatever its size: the round, all its screenshots, all their pins. At
 * Round::MAX_SCREENSHOTS times Screenshot::MAX_PINS pins the last one binds 60,000 values, under PostgreSQL's 65,535.
 */
final readonly class DbalRounds implements Rounds
{
    private const string TIMESTAMP = 'Y-m-d H:i:sP';

    public function __construct(private Connection $connection)
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
                'INSERT INTO rvw_round (organization_id, id, ticket_id, number, kind, owner_id, shared_at, state, version, created_at)'
                .' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT DO NOTHING',
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

            $screenshotRows = [];
            $pinRows = [];
            foreach ($round->screenshots() as $screenshot) {
                $screenshotRows[] = [
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
                foreach ($screenshot->pins() as $pin) {
                    $pinRows[] = [
                        $organizationId,
                        $pin->id()->toString(),
                        $screenshot->id()->toString(),
                        $pin->number(),
                        // A string, so the numeric column gets the two decimals as they are, never a float's tail.
                        number_format($pin->xPercent(), 2, '.', ''),
                        number_format($pin->yPercent(), 2, '.', ''),
                        $pin->note(),
                        $pin->verdict()?->value,
                        $pin->source()->value,
                        $pin->aiState()?->value,
                        $pin->authorId()->toString(),
                        $createdAt,
                    ];
                }
            }
            $this->insertRows('rvw_screenshot', ['organization_id', 'id', 'round_id', 'media_id', 'position', 'variant_label', 'verdict', 'title', 'summary', 'created_at'], $screenshotRows);
            $this->insertRows('rvw_pin', ['organization_id', 'id', 'screenshot_id', 'number', 'x_pct', 'y_pct', 'note', 'verdict', 'source', 'ai_state', 'author_id', 'created_at'], $pinRows);
        });
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
}
