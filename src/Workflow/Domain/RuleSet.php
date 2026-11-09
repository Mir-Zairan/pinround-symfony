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

    public static function defaults(): self
    {
        return new self(testerMovesDone: true, noSelfReview: true, reviewBeforeTest: true);
    }
}
