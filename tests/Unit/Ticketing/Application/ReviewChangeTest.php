<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\Application;

use App\Ticketing\Application\ReviewChange;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReviewChangeTest extends TestCase
{
    #[Test]
    public function aChangeOfAKnownKindKeepsItsOutcomeAndLabels(): void
    {
        $change = new ReviewChange(ReviewChange::PIN_VERDICT_SET, 'fixed', 2, 'Per product');

        self::assertSame([ReviewChange::PIN_VERDICT_SET, 'fixed', 2, 'Per product'], [$change->kind, $change->outcome, $change->pinNumber, $change->areaName]);
    }

    #[Test]
    public function anUnknownKindIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"moved" is not a kind of review change.');

        new ReviewChange('moved', 'fixed', 2, null);
    }

    #[Test]
    public function anEmptyOutcomeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A review change has an outcome.');

        new ReviewChange(ReviewChange::AREA_VERDICT_SAVED, '', null, 'Totals');
    }
}
