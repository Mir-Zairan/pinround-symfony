<?php

declare(strict_types=1);

namespace App\Tests\Integration\Workflow;

use App\Tests\Unit\Workflow\Application\Transition\GuardFixtures;
use App\Workflow\Api\MoveTarget;
use App\Workflow\Api\TransitionView;
use App\Workflow\Application\Transition\CatalogueTransitionPolicy;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The Api's TransitionPolicy as the container gives it to another module, with the catalogue and every guard wired.
 */
final class TransitionPolicyWiringTest extends KernelTestCase
{
    #[Test]
    public function theApiResolvesToTheCataloguePolicyAndOffersLenaPlanItInBacklog(): void
    {
        $policy = self::getContainer()->get('test.workflow_transition_policy');
        self::assertInstanceOf(CatalogueTransitionPolicy::class, $policy);

        $availability = $policy->available(GuardFixtures::ticket('backlog', 'todo'), GuardFixtures::lena(), GuardFixtures::rules());

        self::assertEquals([
            new TransitionView('plan_it', 'Plan it', true, MoveTarget::coreStep('todo')),
            new TransitionView('give_it_to', 'Give it to…', false, MoveTarget::coreStep('todo')),
            new TransitionView('put_on_hold', 'Put on hold', false, MoveTarget::coreStep('hold')),
        ], $availability->transitions);
        self::assertNull($availability->ruleNote);
    }
}
