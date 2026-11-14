<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Infrastructure\Mail;

use App\Notification\Application\Digest\DigestItem;
use App\Notification\Application\Notify\NotificationKind;
use App\Notification\Infrastructure\Mail\DigestMail;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DigestMailTest extends TestCase
{
    #[Test]
    public function oneItemIsOneThingThatWaits(): void
    {
        self::assertSame('1 thing waits on you in pinround', DigestMail::subjectFor(1));
    }

    #[Test]
    public function threeItemsAreThreeThingsThatWait(): void
    {
        self::assertSame('3 things wait on you in pinround', DigestMail::subjectFor(3));
    }

    #[Test]
    public function aPingAndAQuestionHaveTheirOwnWords(): void
    {
        self::assertSame('pinged you', DigestMail::actionOf(NotificationKind::Ping));
        self::assertSame('asked you a question', DigestMail::actionOf(NotificationKind::Question));
    }

    #[Test]
    public function aKindNoDigestListsHasNoWords(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('A digest has no words for the kind answer.');

        DigestMail::actionOf(NotificationKind::Answer);
    }

    #[Test]
    public function aTicketForEveryoneIsNamedByItsTitle(): void
    {
        self::assertSame('Checkout button is hidden', DigestMail::labelOf(self::itemOf('Checkout button is hidden', 'Shop')));
    }

    #[Test]
    public function aRestrictedTicketIsNamedByItsTeamOnly(): void
    {
        self::assertSame('a restricted ticket in team Shop', DigestMail::labelOf(self::itemOf(null, 'Shop')));
    }

    #[Test]
    public function aRestrictedTicketWithNoTeamIsNamedByNothingElse(): void
    {
        self::assertSame('a restricted ticket', DigestMail::labelOf(self::itemOf(null, null)));
    }

    private static function itemOf(?string $ticketTitle, ?string $teamName): DigestItem
    {
        return new DigestItem('Raj', NotificationKind::Ping, 'SHOP-12', $ticketTitle, $teamName, new \DateTimeImmutable('2027-03-20 08:00:00+00:00'));
    }
}
