<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\Application;

use App\Review\Domain\Round;
use App\Review\Domain\RoundSlotTaken;
use App\Review\Domain\Screenshot;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The double is only worth using while it behaves like DbalRounds, so its three rules are pinned here.
 */
final class InMemoryRoundsTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000000f01';
    private const string TICKET = '0197a3c0-0000-7000-8000-000000001001';
    private const string OTHER_TICKET = '0197a3c0-0000-7000-8000-000000001002';
    private const string LENA = '0197a3c0-0000-7000-8000-000000000b01';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000000b02';
    private const string MEDIA = '0197a3c0-0000-7000-8000-000000000d01';

    #[Test]
    public function aSecondReportOnOneTicketIsASlotTakenAndIsNotStored(): void
    {
        $rounds = new InMemoryRounds();
        $rounds->add(self::report($rounds, self::TICKET));

        try {
            $rounds->add(self::report($rounds, self::TICKET));
            self::fail('A second report was stored.');
        } catch (RoundSlotTaken) {
            self::assertCount(1, $rounds->stored);
        }
    }

    #[Test]
    public function aReportOnAnotherTicketTakesNoSlotOfTheFirst(): void
    {
        $rounds = new InMemoryRounds();
        $rounds->add(self::report($rounds, self::TICKET));
        $rounds->add(self::report($rounds, self::OTHER_TICKET));

        self::assertCount(2, $rounds->stored);
    }

    #[Test]
    public function anOwnerHasOneUnsharedDraftPerTicketAndAnotherOwnerHasTheirOwn(): void
    {
        $rounds = new InMemoryRounds();
        $rounds->add(self::draft($rounds, self::OMAR));
        $rounds->add(self::draft($rounds, self::LENA));

        try {
            $rounds->add(self::draft($rounds, self::OMAR));
            self::fail('A second open draft of one owner was stored.');
        } catch (RoundSlotTaken) {
            self::assertCount(2, $rounds->stored);
        }
    }

    #[Test]
    public function aRoundIdMetAgainIsALogicExceptionNotASlotTaken(): void
    {
        $rounds = new InMemoryRounds();
        $report = self::report($rounds, self::TICKET);
        $rounds->add($report);

        try {
            $rounds->add($report);
            self::fail('A round was added twice.');
        } catch (\LogicException $logic) {
            self::assertNotInstanceOf(RoundSlotTaken::class, $logic);
            self::assertCount(1, $rounds->stored);
        }
    }

    #[Test]
    public function everyIdentityIsNew(): void
    {
        $rounds = new InMemoryRounds();

        $ids = [$rounds->nextIdentity()->toString(), $rounds->nextScreenshotIdentity()->toString(), $rounds->nextPinIdentity()->toString(), $rounds->nextIdentity()->toString()];

        self::assertCount(4, array_unique($ids));
    }

    private static function report(InMemoryRounds $rounds, string $ticketId): Round
    {
        $screenshot = Screenshot::of($rounds->nextScreenshotIdentity(), self::MEDIA, 0, null, null, []);

        return Round::report($rounds->nextIdentity(), new OrganizationId(self::ACME_FOODS), new TicketId($ticketId), new UserId(self::LENA), [$screenshot], new \DateTimeImmutable('2026-03-04 09:30:00+00:00'));
    }

    private static function draft(InMemoryRounds $rounds, string $ownerId): Round
    {
        return Round::draft($rounds->nextIdentity(), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), new UserId($ownerId), new \DateTimeImmutable('2026-03-04 10:00:00+00:00'));
    }
}
