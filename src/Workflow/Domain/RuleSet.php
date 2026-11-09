<?php

declare(strict_types=1);

namespace App\Workflow\Domain;

/**
 * The three rules that guard moves (workflow-engine.md, "Rules"). True means the rule is on.
 */
final readonly class RuleSet
{
    public function __construct(
        public bool $testerMovesDone,
        public bool $noSelfReview,
        public bool $reviewBeforeTest,
    ) {
    }

    /** The codes of the three rules, as stored in wf_rule_set's columns. */
    public const array RULE_CODES = ['tester_moves_done', 'no_self_review', 'review_before_test'];

    public static function defaults(): self
    {
        return new self(testerMovesDone: true, noSelfReview: true, reviewBeforeTest: true);
    }

    /**
     * A copy with one rule turned on or off; the other two stay as they are.
     *
     * @param string $ruleCode one of RULE_CODES
     *
     * @throws \InvalidArgumentException when $ruleCode is not one of RULE_CODES
     */
    public function withRule(string $ruleCode, bool $isOn): self
    {
        return match ($ruleCode) {
            'tester_moves_done' => new self($isOn, $this->noSelfReview, $this->reviewBeforeTest),
            'no_self_review' => new self($this->testerMovesDone, $isOn, $this->reviewBeforeTest),
            'review_before_test' => new self($this->testerMovesDone, $this->noSelfReview, $isOn),
            default => throw new \InvalidArgumentException(\sprintf('There is no rule "%s".', $ruleCode)),
        };
    }
}
