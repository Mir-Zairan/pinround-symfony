<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\Infrastructure\Messenger;

use App\Review\Api\Event\ReviewEvent;
use App\Tests\Unit\Notification\Application\Notify\OnceOnlyHandlingDouble;
use App\Tests\Unit\Ticketing\Application\RecordedTicketWrites;
use App\Ticketing\Api\ReviewChangeLabels;
use App\Ticketing\Api\ReviewedPin;
use App\Ticketing\Infrastructure\Messenger\RecordReviewChangesHandler;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What the handler refuses; tests/Integration/Ticketing/RecordReviewChangesHandlerTest.php runs each event kind on the
 * real tables.
 */
final class RecordReviewChangesHandlerTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000f9f01';
    private const string TICKET = '0197a3c0-0000-7000-8000-0000000f9f02';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000f9f03';

    #[Test]
    public function aReviewEventWithNoKindOfChangeIsRefusedAndRecordsNothing(): void
    {
        $onceOnlyHandling = new OnceOnlyHandlingDouble();
        $onceOnlyHandling->messageId = 'message-1';
        $history = new RecordedTicketWrites(self::TICKET);
        $handler = new RecordReviewChangesHandler($onceOnlyHandling, self::noLabels(), $history);
        $unknownEvent = new readonly class(self::ACME_FOODS, self::TICKET, self::MIA, new \DateTimeImmutable('2027-03-16 09:30:00+00:00')) implements ReviewEvent {
            public function __construct(
                public string $organizationId,
                public string $ticketId,
                public string $actorId,
                public \DateTimeImmutable $occurredAt,
            ) {
            }
        };

        try {
            $handler($unknownEvent);
            self::fail('An unknown Review event must be refused.');
        } catch (\LogicException $refused) {
            self::assertStringContainsString('has no kind of review change', $refused->getMessage());
        }
        self::assertSame([], $history->writes);
    }

    private static function noLabels(): ReviewChangeLabels
    {
        return new class implements ReviewChangeLabels {
            public function checkItemPinNumberOf(string $itemId): ?int
            {
                return null;
            }

            public function reviewedPinOf(string $roundId, string $pinId): ?ReviewedPin
            {
                return null;
            }

            public function areaNameOf(string $areaId): ?string
            {
                return null;
            }
        };
    }
}
