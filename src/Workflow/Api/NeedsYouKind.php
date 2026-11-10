<?php

declare(strict_types=1);

namespace App\Workflow\Api;

/**
 * Why a ticket is on someone's Home (workflow-engine.md, "Needs-you kinds and score"); one per item.
 */
enum NeedsYouKind: string
{
    case Answer = 'answer';
    case Check = 'check';
    case Review = 'review';
    case Test = 'test';
    case Assign = 'assign';
    case Input = 'input';
    case Decide = 'decide';
    case Build = 'build';
}
