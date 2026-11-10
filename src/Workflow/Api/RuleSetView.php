<?php

declare(strict_types=1);

namespace App\Workflow\Api;

/**
 * The three rules that guard moves, as they apply to one team. True means the rule is on.
 */
final readonly class RuleSetView
{
    public function __construct(
        public bool $testerMovesDone,
        public bool $noSelfReview,
        public bool $reviewBeforeTest,
    ) {
    }
}
