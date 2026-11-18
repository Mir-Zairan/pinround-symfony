<?php

declare(strict_types=1);

namespace App\Assist\UI;

use App\Identity\Api\UserView;
use App\SharedKernel\Domain\UserId;
use App\SharedKernel\UI\RelativeTime;
use App\Ticketing\Api\TicketSummaryView;

/**
 * Who wrote a summary, in the words of "The ask" (ticket-page.md), for the current summary and the earlier ones.
 */
final class SummarySourceNote
{
    /** How long an on-demand summary reads as written "just now". */
    public const int JUST_NOW_SECONDS = 60;

    public const string SYNCED = 'written from the Jira text when the ticket was synced';
    public const string WRITTEN_JUST_NOW = 'written just now at %s’s request';
    public const string WRITTEN_ON = 'written on %s at %s’s request';

    /** A laptop's result made the main summary: who posted it, the local model, and who made it the main summary. */
    public const string LAPTOP_MADE_MAIN = 'posted by %s using local model %s (unverified), made the main summary by %s';

    /** Stands for a laptop summary's model should its row have none. */
    public const string UNKNOWN_MODEL = 'unknown';

    /** Stands for a requester Identity no longer knows. */
    public const string UNKNOWN_REQUESTER = 'someone';

    /** The sources whose text an AI wrote, which carry the AI SUMMARY chip. */
    private const array AI_SOURCES = ['ai', 'sync', 'explanation', 'laptop'];

    /**
     * @param string|null $authorDisplayName  the summary author's display name; null when Identity does not know them
     * @param string|null $chooserDisplayName the display name of who made it the main summary (TicketSummaryView's
     *                                        chosenById); null when Identity does not know them or nobody chose it
     *
     * @return string|null null for a source with no note yet: a person's own summary, and an explanation made the main
     *                     summary, which P08.T6 labels
     */
    public static function of(TicketSummaryView $summary, ?string $authorDisplayName, \DateTimeImmutable $now, \DateTimeZone $viewerTimeZone, ?string $chooserDisplayName = null): ?string
    {
        return match ($summary->source) {
            'sync' => self::SYNCED,
            'ai' => self::onDemandNoteOf($summary, $authorDisplayName, $now, $viewerTimeZone),
            'laptop' => \sprintf(
                self::LAPTOP_MADE_MAIN,
                self::firstNameOf($summary->authorId, $authorDisplayName),
                $summary->model ?? self::UNKNOWN_MODEL,
                self::firstNameOf($summary->chosenById, $chooserDisplayName),
            ),
            default => null,
        };
    }

    private static function firstNameOf(?UserId $userId, ?string $displayName): string
    {
        return null === $userId || null === $displayName ? self::UNKNOWN_REQUESTER : new UserView($userId, $displayName)->firstName();
    }

    public static function isWrittenByAi(TicketSummaryView $summary): bool
    {
        return \in_array($summary->source, self::AI_SOURCES, true);
    }

    private static function onDemandNoteOf(TicketSummaryView $summary, ?string $authorDisplayName, \DateTimeImmutable $now, \DateTimeZone $viewerTimeZone): string
    {
        $requester = self::firstNameOf($summary->authorId, $authorDisplayName);
        if ($now->getTimestamp() - $summary->createdAt->getTimestamp() < self::JUST_NOW_SECONDS) {
            return \sprintf(self::WRITTEN_JUST_NOW, $requester);
        }

        return \sprintf(self::WRITTEN_ON, RelativeTime::dateOf($summary->createdAt, $now, $viewerTimeZone), $requester);
    }
}
