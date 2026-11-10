<?php

declare(strict_types=1);

namespace App\Workflow\Application\Turn;

use App\Workflow\Api\NeedsYouKind;
use App\Workflow\Api\NowNext;
use App\Workflow\Api\NowNextContext;
use App\Workflow\Api\TicketState;
use App\Workflow\Api\Turn;
use App\Workflow\Domain\CoreStep;

/**
 * The sentences of workflow-engine.md, "Now and Next sentences". The person is the one the turn rules chose, so the
 * sentence never disagrees with Home.
 */
final readonly class PlainNowNext implements NowNext
{
    public function now(TicketState $ticket, Turn $turn, NowNextContext $context): string
    {
        $words = new SentenceWords($context);
        $step = $this->coreStepOf($ticket);

        if (NeedsYouKind::Answer === $turn->kind) {
            return ucfirst($words->action($turn->userId, 'someone', 'answer', ' '.$words->possessive($context->askerId, 'the').' question'));
        }

        if (NeedsYouKind::Check === $turn->kind) {
            return ucfirst($words->action($turn->userId, 'someone', 'take', ' a quick look at the new ticket'));
        }

        if (null === $turn->kind && null === $turn->userId && null === $turn->label) {
            return 'Done. Nobody needs to act.';
        }

        if (null === $step) {
            return $context->stepName.' · '.$words->who($turn->userId, $turn->label);
        }

        return match ($step) {
            CoreStep::Backlog => ucfirst($words->action($turn->userId, 'someone', 'plan', ' it')),
            CoreStep::Todo => ucfirst(NeedsYouKind::Assign === $turn->kind
                ? $words->action($turn->userId, 'someone', 'pick', ' a developer')
                : $words->action($turn->userId, 'someone', 'start', ' building')),
            CoreStep::Progress => ucfirst($words->action($turn->userId, 'someone', 'build', ' it')),
            CoreStep::Review => ucfirst($words->action($turn->userId, 'someone', 'review', ' the code')),
            CoreStep::Test => ucfirst($words->action($turn->userId, 'someone', 'test', ' it')),
            CoreStep::Input => 'Waiting for input from '.$words->who($turn->userId, $turn->label),
            CoreStep::Hold => ucfirst($words->action($turn->userId, 'someone', 'decide', ' if it goes on')),
            CoreStep::Done => 'Done. Nobody needs to act.',
        };
    }

    public function next(TicketState $ticket, Turn $turn, NowNextContext $context): ?string
    {
        $words = new SentenceWords($context);
        $step = $this->coreStepOf($ticket);

        if (NeedsYouKind::Answer === $turn->kind) {
            return 'it goes back to '.$context->stepName;
        }

        if (NeedsYouKind::Check === $turn->kind) {
            return null === $ticket->pendingAssigneeId
                ? 'it is planned like any ticket'
                : ucfirst($words->action($ticket->pendingAssigneeId, 'someone', 'get', ' it'));
        }

        $tester = $words->action($ticket->testerId, 'a tester', 'test', ' it');
        $hasReviewPart = null !== $ticket->reviewerId || $context->isReviewBeforeTest;

        return match ($step) {
            null, CoreStep::Done => null,
            CoreStep::Backlog, CoreStep::Todo => ucfirst($words->action($ticket->assigneeId, 'a developer', 'build', ' it')
                .($hasReviewPart ? ', '.$words->action($ticket->reviewerId, 'a reviewer', 'review', '') : '')
                .', '.$words->action($ticket->testerId, 'a tester', 'test', '')),
            CoreStep::Progress => ucfirst($hasReviewPart
                ? $words->action($ticket->reviewerId, 'a reviewer', 'review', ' the code').', then '.$tester
                : $tester),
            CoreStep::Review => ucfirst($tester),
            CoreStep::Test => 'Done',
            CoreStep::Input, CoreStep::Hold => 'it goes back to work',
        };
    }

    private function coreStepOf(TicketState $ticket): ?CoreStep
    {
        if (null === $ticket->coreCode) {
            return null;
        }

        return CoreStep::tryFrom($ticket->coreCode)
            ?? throw new \InvalidArgumentException('A ticket in a core step must have one of the eight core codes.');
    }
}
