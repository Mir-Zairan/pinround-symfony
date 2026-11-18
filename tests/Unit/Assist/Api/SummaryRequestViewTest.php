<?php

declare(strict_types=1);

namespace App\Tests\Unit\Assist\Api;

use App\Assist\Api\SummaryRequestView;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SummaryRequestViewTest extends TestCase
{
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000c7e11';

    #[Test]
    public function aQueuedRequestCarriesWhoAskedAndWhenWithNoSentence(): void
    {
        $requestedAt = new \DateTimeImmutable('2027-03-15 10:00:00 UTC');

        $queued = SummaryRequestView::queued(new UserId(self::OMAR), $requestedAt);

        self::assertSame([SummaryRequestView::QUEUED, self::OMAR, $requestedAt, null], [$queued->status, $queued->requesterId->toString(), $queued->at, $queued->sentence]);
        self::assertTrue($queued->isQueued());
    }

    #[Test]
    public function aFailedRequestCarriesItsSentenceAndIsNotQueued(): void
    {
        $failedAt = new \DateTimeImmutable('2027-03-15 10:02:00 UTC');

        $failed = SummaryRequestView::failed(new UserId(self::OMAR), $failedAt, 'The AI answer failed. Try again.');

        self::assertSame([SummaryRequestView::FAILED, self::OMAR, $failedAt, 'The AI answer failed. Try again.'], [$failed->status, $failed->requesterId->toString(), $failed->at, $failed->sentence]);
        self::assertFalse($failed->isQueued());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blankSentences(): iterable
    {
        yield 'empty' => [''];
        yield 'spaces' => ['   '];
        yield 'a line break' => ["\r\n"];
    }

    #[Test]
    #[DataProvider('blankSentences')]
    public function aFailedRequestWithoutASentenceIsRefused(string $blankSentence): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A failed summary request says why in a sentence.');

        SummaryRequestView::failed(new UserId(self::OMAR), new \DateTimeImmutable('2027-03-15 10:02:00 UTC'), $blankSentence);
    }
}
