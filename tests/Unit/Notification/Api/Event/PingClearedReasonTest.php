<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Api\Event;

use App\Notification\Api\Event\PingClearedReason;
use App\Notification\Domain\PingClearedReason as DomainPingClearedReason;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PingClearedReasonTest extends TestCase
{
    #[Test]
    public function theEventReasonHasTheSameCasesAndValuesAsTheDomainReason(): void
    {
        $eventReasons = self::valuesByName(PingClearedReason::cases());
        $domainReasons = self::valuesByName(DomainPingClearedReason::cases());

        self::assertSame(['Acted' => 'acted', 'Done' => 'done'], $eventReasons);
        self::assertSame($domainReasons, $eventReasons);
    }

    /**
     * @param list<\BackedEnum> $cases
     *
     * @return array<string, int|string>
     */
    private static function valuesByName(array $cases): array
    {
        $valuesByName = [];
        foreach ($cases as $case) {
            $valuesByName[$case->name] = $case->value;
        }
        ksort($valuesByName);

        return $valuesByName;
    }
}
