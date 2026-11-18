<?php

declare(strict_types=1);

namespace App\Tests\Unit\Assist\UI;

use App\Assist\UI\SummarySourceNote;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketSummaryView;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SummarySourceNoteTest extends TestCase
{
    private const string NOW = '2026-03-10 09:00:00 UTC';
    private const string OMAR_ID = '0196a1b2-0000-7000-8000-000000000001';

    /**
     * @return iterable<string, array{TicketSummaryView, string|null, string|null}>
     */
    public static function summaries(): iterable
    {
        yield 'asked 59 seconds ago' => [self::summary('ai', '2026-03-10 08:59:01 UTC'), 'Omar Haddad', 'written just now at Omar’s request'];
        yield 'asked exactly a minute ago' => [self::summary('ai', '2026-03-10 08:59:00 UTC'), 'Omar Haddad', 'written on 10 Mar at Omar’s request'];
        yield 'asked last year, late in the viewer’s day' => [self::summary('ai', '2025-12-31 23:30:00 UTC'), 'Omar', 'written on 1 Jan at Omar’s request'];
        yield 'asked by someone Identity no longer knows' => [self::summary('ai', '2026-03-10 08:59:30 UTC'), null, 'written just now at someone’s request'];
        yield 'written on sync' => [self::summary('sync', '2026-03-01 08:00:00 UTC', null), null, 'written from the Jira text when the ticket was synced'];
        yield 'written by a person' => [self::summary('human', '2026-03-10 08:59:30 UTC'), 'Omar Haddad', null];
        yield 'an explanation made the main summary' => [self::summary('explanation', '2026-03-10 08:59:30 UTC'), 'Omar Haddad', null];
    }

    #[Test]
    #[DataProvider('summaries')]
    public function aSummarySaysWhoWroteItInTheViewersTimeZone(TicketSummaryView $summary, ?string $authorName, ?string $expectedNote): void
    {
        self::assertSame($expectedNote, SummarySourceNote::of($summary, $authorName, new \DateTimeImmutable(self::NOW), new \DateTimeZone('Europe/Berlin')));
    }

    #[Test]
    public function onlyAPersonsOwnSummaryHasNoAiChip(): void
    {
        $chipBySource = [];
        foreach (['ai', 'sync', 'explanation', 'human'] as $source) {
            $chipBySource[$source] = SummarySourceNote::isWrittenByAi(self::summary($source, self::NOW));
        }

        self::assertSame(['ai' => true, 'sync' => true, 'explanation' => true, 'human' => false], $chipBySource);
    }

    private static function summary(string $source, string $createdAt, ?string $authorId = self::OMAR_ID): TicketSummaryView
    {
        return new TicketSummaryView(
            '0196a1b2-0000-7000-8000-0000000000aa',
            'Pay does nothing on the last step.',
            $source,
            null === $authorId ? null : new UserId($authorId),
            'ai' === $source ? 'gpt-test' : null,
            'ai' === $source ? 'summary.v1' : null,
            new \DateTimeImmutable($createdAt),
        );
    }
}
