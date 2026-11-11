<?php

declare(strict_types=1);

namespace App\Ticketing\UI;

use App\SharedKernel\Domain\TicketId;
use App\Ticketing\Api\Cursor;

/**
 * A step's next-page Cursor as the "Show more" link carries it: the step's uuid in STEP_QUERY and
 * "<priority rank>.<updated_at in Unix seconds>.<ticket id>" in AFTER_QUERY. It is not signed: it holds only what the
 * viewer already saw on the last row, and a forged one moves where the step's page starts, while the list still
 * reads only the tickets the viewer may see.
 */
final class TicketListCursorParameter
{
    public const string STEP_QUERY = 'step';
    public const string AFTER_QUERY = 'after';

    private const string AFTER = '/^([0-4])\.(\d{1,11})\.([0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})\z/';
    private const string CANONICAL_UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/';

    /**
     * @return array{step: string, after: string} the query parameters of the step's next page
     */
    public static function queryOf(Cursor $cursor): array
    {
        return [
            self::STEP_QUERY => $cursor->stepId,
            self::AFTER_QUERY => \sprintf('%d.%d.%s', $cursor->priorityRank, $cursor->updatedAt->getTimestamp(), $cursor->ticketId->toString()),
        ];
    }

    /**
     * @return Cursor|null null when either value is not one queryOf makes
     */
    public static function cursorOf(string $stepId, string $after): ?Cursor
    {
        if (1 !== preg_match(self::CANONICAL_UUID, $stepId) || 1 !== preg_match(self::AFTER, $after, $parts)) {
            return null;
        }

        return new Cursor($stepId, (int) $parts[1], new \DateTimeImmutable('@'.$parts[2]), new TicketId($parts[3]));
    }
}
