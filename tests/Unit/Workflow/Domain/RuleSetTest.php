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

    #[Test]
    public function withRuleChangesOnlyTheNamedRule(): void
    {
        $rules = new RuleSet(testerMovesDone: true, noSelfReview: false, reviewBeforeTest: true);

        self::assertEquals(new RuleSet(testerMovesDone: false, noSelfReview: false, reviewBeforeTest: true), $rules->withRule('tester_moves_done', false));
        self::assertEquals(new RuleSet(testerMovesDone: true, noSelfReview: true, reviewBeforeTest: true), $rules->withRule('no_self_review', true));
        self::assertEquals(new RuleSet(testerMovesDone: true, noSelfReview: false, reviewBeforeTest: false), $rules->withRule('review_before_test', false));
        self::assertEquals(new RuleSet(testerMovesDone: true, noSelfReview: false, reviewBeforeTest: true), $rules, 'The rule set itself does not change.');
    }

    #[Test]
    public function everyRuleCodeIsOneWithRuleKnows(): void
    {
        foreach (RuleSet::RULE_CODES as $ruleCode) {
            self::assertNotEquals(RuleSet::defaults(), RuleSet::defaults()->withRule($ruleCode, false), $ruleCode);
        }
    }

    #[Test]
    public function withRuleRefusesAnUnknownRule(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RuleSet::defaults()->withRule('lead_moves_done', false);
    }
}
