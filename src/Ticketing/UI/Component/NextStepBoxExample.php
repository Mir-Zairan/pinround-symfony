<?php

declare(strict_types=1);

namespace App\Ticketing\UI\Component;

use App\Ticketing\UI\NextStep;
use App\Ticketing\UI\NextStepChoices;
use App\Ticketing\UI\PersonToWaitOn;
use App\Workflow\Api\MoveChoice;
use App\Workflow\Api\MoveChoiceGroup;
use App\Workflow\Api\MoveTarget;
use App\Workflow\Api\StepView;
use App\Workflow\Api\TransitionView;
use Symfony\Component\DependencyInjection\Attribute\WhenNot;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The component gallery's "Your next step" box: Lena, the lead, on SHOP-12 in Testing with the rule "only the tester
 * moves a ticket to Done" on, her move list open and its Needs input chip's dialog behind it. The gallery lives in Web,
 * which may not build Ticketing's UI models, so this one builds them. Its forms post to the real move routes, which
 * refuse them: no ticket SHOP-12 exists for the gallery.
 */
#[WhenNot('prod')]
#[AsTwigComponent(name: 'NextStepBoxExample', template: '@Ticketing/components/NextStepBoxExample.html.twig')]
final class NextStepBoxExample
{
    public const string TICKET_KEY = 'SHOP-12';
    public const int VERSION = 3;

    public function nextStep(): NextStep
    {
        $choice = static fn (string $id, string $name, string $groupCode, string $groupLabel, int $position, string $coreCode, bool $isCurrent = false): MoveChoice => new MoveChoice(
            new StepView($id, $name, $groupCode, $groupLabel, $position, 'core', $coreCode),
            $isCurrent,
        );

        return new NextStep(
            \sprintf(NextStepChoices::NOTHING_FOR_YOU_NOW, 'Mia'),
            [new TransitionView('put_on_hold', 'Put on hold', false, MoveTarget::coreStep('hold'))],
            'Team rule: only the tester moves a ticket to Done.',
            [
                new MoveChoiceGroup('todo', 'To do', [
                    $choice('00000000-0000-4000-8000-0000000000b1', 'Backlog', 'todo', 'To do', 0, 'backlog'),
                    $choice('00000000-0000-4000-8000-0000000000b2', 'To do', 'todo', 'To do', 1, 'todo'),
                ]),
                new MoveChoiceGroup('doing', 'Doing', [$choice('00000000-0000-4000-8000-0000000000b3', 'In progress', 'doing', 'Doing', 2, 'progress')]),
                new MoveChoiceGroup('check', 'Checking', [
                    $choice('00000000-0000-4000-8000-0000000000b4', 'Code review', 'check', 'Checking', 3, 'review'),
                    $choice('00000000-0000-4000-8000-0000000000b5', 'Testing', 'check', 'Checking', 4, 'test', isCurrent: true),
                ]),
                new MoveChoiceGroup('wait', 'Waiting', [
                    $choice('00000000-0000-4000-8000-0000000000b6', 'Needs input', 'wait', 'Waiting', 5, 'input'),
                    $choice('00000000-0000-4000-8000-0000000000b7', 'On hold', 'wait', 'Waiting', 6, 'hold'),
                ]),
                new MoveChoiceGroup('done', 'Done', [$choice('00000000-0000-4000-8000-0000000000b8', 'Done', 'done', 'Done', 7, 'done')]),
            ],
            [
                new PersonToWaitOn('00000000-0000-4000-8000-0000000000c1', 'Sara', 'Reporter'),
                new PersonToWaitOn('00000000-0000-4000-8000-0000000000c2', 'Omar', 'Assignee'),
                new PersonToWaitOn('00000000-0000-4000-8000-0000000000c3', 'Lena', 'Code reviewer'),
                new PersonToWaitOn('00000000-0000-4000-8000-0000000000c4', 'Mia', 'Tester'),
            ],
        );
    }
}
