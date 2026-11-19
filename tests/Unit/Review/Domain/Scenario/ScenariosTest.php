<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\Domain\Scenario;

use App\Review\Domain\Scenario\Scenario;
use App\Review\Domain\Scenario\ScenarioRefused;
use App\Review\Domain\Scenario\ScenarioRules;
use App\Review\Domain\Scenario\Scenarios;
use App\Review\Domain\Scenario\ScenarioStep;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScenariosTest extends TestCase
{
    #[Test]
    public function noScenariosAndTwentyAreKept(): void
    {
        self::assertSame([], new Scenarios([])->all());
        self::assertCount(ScenarioRules::MAX_SCENARIOS, new Scenarios(self::scenarios(20))->all());
    }

    /**
     * Built without ScenarioRules::parse, as a notebook change can be, so the count is the constructor's own rule.
     */
    #[Test]
    public function aTwentyFirstScenarioIsRefusedWithThePlainReason(): void
    {
        try {
            new Scenarios(self::scenarios(21));
            self::fail('21 scenarios are refused.');
        } catch (ScenarioRefused $refused) {
            self::assertSame(ScenarioRefused::TOO_MANY_SCENARIOS, $refused->reason);
        }
    }

    /**
     * @return list<Scenario>
     */
    private static function scenarios(int $count): array
    {
        return array_fill(0, $count, new Scenario('Out of stock', 'OrderLine::warn() runs when stock is 0.', '', 'http://localhost:8000/orders', [new ScenarioStep('pause', 'Wait.')]));
    }
}
