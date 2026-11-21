<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\Api;

use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\UndecidedDraft;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UndecidedDraftTest extends TestCase
{
    private const string ROUND = '0197a3c0-0000-7000-8000-000000003e01';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000003b02';

    #[Test]
    public function aPersonNamedForNoOpenRoundIsRefused(): void
    {
        try {
            new UndecidedDraft(self::ROUND, 1, 3, null, new UserId(self::OMAR));
            self::fail('A draft named a person whose verdict no open round waits for.');
        } catch (\InvalidArgumentException $refused) {
            self::assertSame('Only an open round that blocks the share waits for a person.', $refused->getMessage());
        }
    }

    #[Test]
    public function aPersonTheRoundIsNotReadyForNamedForNoOpenRoundIsRefused(): void
    {
        try {
            new UndecidedDraft(self::ROUND, 1, 3, null, null, new UserId(self::OMAR));
            self::fail('A draft named a person no open round is not ready for.');
        } catch (\InvalidArgumentException $refused) {
            self::assertSame('Only an open round that blocks the share waits for a person.', $refused->getMessage());
        }
    }

    #[Test]
    public function aBlockedShareMayNameWhomItsOpenRoundIsNotReadyFor(): void
    {
        $draft = new UndecidedDraft(self::ROUND, 1, 3, 2, null, new UserId(self::OMAR));

        self::assertSame([2, null, self::OMAR], [$draft->openRoundNumber, $draft->openRoundReadyForUserId, $draft->openRoundNotReadyForUserId?->toString()]);
    }

    #[Test]
    public function aBlockedShareMayNameWhoseVerdictItsOpenRoundWaitsFor(): void
    {
        $draft = new UndecidedDraft(self::ROUND, 1, 3, 2, new UserId(self::OMAR));

        self::assertTrue($draft->isSharingBlocked());
        self::assertSame([2, self::OMAR], [$draft->openRoundNumber, $draft->openRoundReadyForUserId?->toString()]);
    }

    #[Test]
    public function aDraftWithNoOpenRoundNamesNobodyAndMayBeShared(): void
    {
        $draft = new UndecidedDraft(self::ROUND, 1, 3);

        self::assertFalse($draft->isSharingBlocked());
        self::assertNull($draft->openRoundReadyForUserId);
        self::assertNull($draft->openRoundNotReadyForUserId);
    }
}
