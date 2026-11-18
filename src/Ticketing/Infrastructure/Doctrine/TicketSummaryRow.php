<?php

declare(strict_types=1);

namespace App\Ticketing\Infrastructure\Doctrine;

use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketSummaryView;

/**
 * A tkt_summary row as a page shows it, for the summary history and for the ticket page, which reads the current
 * summary with the ticket under prefixed column names.
 */
final class TicketSummaryRow
{
    /**
     * @param array<string, mixed> $row    the columns id, text, source, author_id, model, prompt_version, chosen_by_id and
     *                                     created_at, each under $prefix
     * @param string               $prefix such as "summary_" for summary_id, summary_text and the rest
     */
    public static function viewOf(array $row, string $prefix = ''): TicketSummaryView
    {
        $authorId = $row[$prefix.'author_id'];
        $model = $row[$prefix.'model'];
        $promptVersion = $row[$prefix.'prompt_version'];
        $chosenById = $row[$prefix.'chosen_by_id'];

        return new TicketSummaryView(
            self::text($row[$prefix.'id'], 'id'),
            self::text($row[$prefix.'text'], 'text'),
            self::text($row[$prefix.'source'], 'source'),
            null === $authorId ? null : new UserId(self::text($authorId, 'author_id')),
            null === $model ? null : self::text($model, 'model'),
            null === $promptVersion ? null : self::text($promptVersion, 'prompt_version'),
            new \DateTimeImmutable(self::text($row[$prefix.'created_at'], 'created_at')),
            null === $chosenById ? null : new UserId(self::text($chosenById, 'chosen_by_id')),
        );
    }

    private static function text(mixed $value, string $column): string
    {
        if (!\is_string($value)) {
            throw new \LogicException(\sprintf('The column %s of a ticket summary is not text.', $column));
        }

        return $value;
    }
}
