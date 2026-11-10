<?php

declare(strict_types=1);

namespace App\Workflow\Domain;

/**
 * A move a person makes with one button (workflow-engine.md, "Transition catalogue"). The values are stable: other
 * modules and forms send them back.
 */
enum Verb: string
{
    case PlanIt = 'plan_it';
    case GiveItTo = 'give_it_to';
    case StartWork = 'start_work';
    case SendToCodeReview = 'send_to_code_review';
    case ApproveCode = 'approve_code';
    case AskForChanges = 'ask_for_changes';
    case PassIt = 'pass_it';
    case NeedsChanges = 'needs_changes';
}
