<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\Api;

use App\Review\Api\ReportDraftCursor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReportDraftCursorTest extends TestCase
{
    private const string DRAFT_ID = '0197a3c0-0000-7000-8000-00000000d401';

    #[Test]
    public function aCanonicalLowercaseDraftIdMakesACursor(): void
    {
        $updatedAt = new \DateTimeImmutable('2026-03-02 10:00:00.123456+00:00');

        $cursor = new ReportDraftCursor($updatedAt, self::DRAFT_ID);

        self::assertSame([$updatedAt, self::DRAFT_ID], [$cursor->updatedAt, $cursor->draftId]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function draftIdsThatAreNotCanonical(): iterable
    {
        yield 'uppercase' => [strtoupper(self::DRAFT_ID)];
        yield 'not a uuid' => ['not-a-uuid'];
        yield 'trailing newline' => [self::DRAFT_ID."\n"];
        yield 'trailing CRLF' => [self::DRAFT_ID."\r\n"];
        yield 'no hyphens' => [str_replace('-', '', self::DRAFT_ID)];
        yield 'empty' => [''];
    }

    #[Test]
    #[DataProvider('draftIdsThatAreNotCanonical')]
    public function aDraftIdThatIsNotACanonicalLowercaseUuidIsRefused(string $draftId): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A report draft cursor\'s draft id must be a canonical lowercase uuid.');

        new ReportDraftCursor(new \DateTimeImmutable('2026-03-02 10:00:00+00:00'), $draftId);
    }
}
