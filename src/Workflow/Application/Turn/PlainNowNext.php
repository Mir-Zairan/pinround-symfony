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
 * sentence never disagrees with Home. Next names the reviewer and tester stored on the ticket, else the ones the team
 * would pick (the same choice the turn rules make), and starts in lowercase, as the documented sentences do.
 */
final readonly class PlainNowNext implements NowNext
{
    public function __construct(
        private ReviewerChoice $reviewerChoice,
        private TesterChoice $testerChoice,
    ) {
    }

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
                : $words->action($ticket->pendingAssigneeId, 'someone', 'get', ' it');
        }

        $reviewerId = $ticket->reviewerId ?? $this->reviewerChoice->choose($ticket->assigneeId, $context->team)->userId;
        $testerId = $ticket->testerId ?? $this->testerChoice->choose($context->team)->userId;
        $review = $words->action($reviewerId, 'a reviewer', 'review', '');
        $reviewTheCode = $words->action($reviewerId, 'a reviewer', 'review', ' the code');
        $test = $words->action($testerId, 'a tester', 'test', '');
        $testIt = $words->action($testerId, 'a tester', 'test', ' it');

        return match ($step) {
            null, CoreStep::Done => null,
            CoreStep::Backlog, CoreStep::Todo => $words->action($ticket->assigneeId, 'a developer', 'build', ' it')
                .($context->isReviewBeforeTest || null !== $ticket->reviewerId ? ', '.$review : '')
                .', '.$test,
            CoreStep::Progress => $context->isReviewBeforeTest ? $reviewTheCode.', then '.$testIt : $testIt,
            CoreStep::Review => $testIt,
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
