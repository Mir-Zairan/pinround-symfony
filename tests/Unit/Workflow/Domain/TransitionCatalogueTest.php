<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow\Domain;

use App\Workflow\Domain\CoreStep;
use App\Workflow\Domain\Mover;
use App\Workflow\Domain\Transition;
use App\Workflow\Domain\TransitionCatalogue;
use App\Workflow\Domain\Verb;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TransitionCatalogueTest extends TestCase
{
    #[Test]
    public function theRowsMatchTheCatalogueTableInButtonOrder(): void
    {
        $expected = [
            ['plan_it', 'Plan it', ['backlog'], 'todo', ['lead'], true, 'any'],
            ['give_it_to', 'Give it to…', ['todo', 'backlog'], 'todo', ['lead', 'checker'], true, 'nobody'],
            ['start_work', 'Start work', ['todo'], 'progress', ['assignee'], true, 'someone'],
            ['send_to_code_review', 'Send to code review', ['progress'], 'review', ['assignee', 'lead_when_unassigned'], true, 'any'],
            ['send_to_testing', 'Send to testing', ['progress'], 'test', ['assignee'], false, 'any'],
            ['approve_code', 'Approve code', ['review'], 'test', ['reviewer'], true, 'any'],
            ['ask_for_changes', 'Ask for changes', ['review'], 'progress', ['reviewer'], false, 'any'],
            ['pass_it', 'Pass it', ['test'], 'done', ['tester', 'lead'], true, 'any'],
            ['needs_changes', 'Needs changes', ['test'], 'progress', ['tester'], false, 'any'],
            ['input_given', 'Input given · go on', ['input'], 'todo', ['waited_on', 'lead'], true, 'any'],
            ['go_on', 'Go on', ['hold'], 'progress', ['lead'], true, 'any'],
            ['reopen', 'Reopen', ['done'], 'progress', ['lead', 'tester'], false, 'any'],
            ['put_on_hold', 'Put on hold', ['backlog', 'todo', 'progress', 'review', 'test', 'input'], 'hold', ['lead'], false, 'any'],
            ['move_on', 'Move on to…', [], null, ['assignee', 'lead'], true, 'any'],
        ];

        $actual = array_map(static fn (Transition $transition): array => [
            $transition->verb->value,
            $transition->label,
            array_map(static fn (CoreStep $step): string => $step->value, $transition->from),
            $transition->to?->value,
            array_map(static fn (Mover $mover): string => $mover->value, $transition->movers),
            $transition->isPrimary,
            $transition->assigneeInTodo->value,
        ], new TransitionCatalogue()->all());

        self::assertSame($expected, $actual);
    }

    #[Test]
    public function everyVerbHasExactlyOneRowAndForFindsIt(): void
    {
        $catalogue = new TransitionCatalogue();
        $verbs = array_map(static fn (Transition $transition): Verb => $transition->verb, $catalogue->all());

        self::assertEqualsCanonicalizing(Verb::cases(), $verbs);
        self::assertCount(\count(Verb::cases()), $verbs);
        foreach (Verb::cases() as $verb) {
            self::assertSame($verb, $catalogue->for($verb)->verb);
        }
    }

    #[Test]
    public function forGivesTheWholeRowOfTheVerb(): void
    {
        self::assertEquals(new TransitionCatalogue()->all()[7], new TransitionCatalogue()->for(Verb::PassIt));
    }
}
