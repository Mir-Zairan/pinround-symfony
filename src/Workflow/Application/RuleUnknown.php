<?php

declare(strict_types=1);

namespace App\Workflow\Application;

/**
 * The rule code is not one of tester_moves_done, no_self_review or review_before_test.
 */
final class RuleUnknown extends \InvalidArgumentException
{
}
