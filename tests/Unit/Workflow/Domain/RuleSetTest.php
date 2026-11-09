<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow\Domain;

use App\Workflow\Domain\RuleSet;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RuleSetTest extends TestCase
{
    #[Test]
    public function defaultsTurnAllThreeRulesOn(): void
    {
        $rules = RuleSet::defaults();

        self::assertTrue($rules->testerMovesDone);
        self::assertTrue($rules->noSelfReview);
        self::assertTrue($rules->reviewBeforeTest);
    }

    #[Test]
    public function eachRuleIsKeptSeparately(): void
    {
        $rules = new RuleSet(testerMovesDone: false, noSelfReview: true, reviewBeforeTest: false);

        self::assertFalse($rules->testerMovesDone);
        self::assertTrue($rules->noSelfReview);
        self::assertFalse($rules->reviewBeforeTest);
    }
}
