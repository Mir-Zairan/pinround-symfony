<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\UI;

use App\Ticketing\UI\MoveToasts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MoveToastsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function toastsOfEachVerb(): iterable
    {
        yield 'plan_it' => ['plan_it', 'Planned. It is in To do now.'];
        yield 'start_work' => ['start_work', 'Started. Status is In progress.'];
        yield 'send_to_code_review' => ['send_to_code_review', 'Sent to code review. Lena’s turn now.'];
        yield 'send_to_testing' => ['send_to_testing', 'Sent to testing. Lena’s turn now.'];
        yield 'approve_code' => ['approve_code', 'Code approved. Lena’s turn to test.'];
        yield 'ask_for_changes' => ['ask_for_changes', 'Sent back to Lena.'];
        yield 'pass_it' => ['pass_it', 'Passed. The ticket is Done.'];
        yield 'needs_changes' => ['needs_changes', 'Sent back to Lena with your notes.'];
        yield 'input_given' => ['input_given', 'Back to work.'];
        yield 'go_on' => ['go_on', 'Back in progress.'];
        yield 'reopen' => ['reopen', 'Reopened. Back in progress.'];
        yield 'put_on_hold' => ['put_on_hold', 'On hold. It waits on you.'];
        yield 'move_on' => ['move_on', 'Moved to Code review.'];
    }

    #[Test]
    #[DataProvider('toastsOfEachVerb')]
    public function eachVerbHasThePrototypesToastWithThePersonAndStepFilledIn(string $verb, string $expectedToast): void
    {
        self::assertSame($expectedToast, MoveToasts::ofVerb($verb, 'Lena', 'Code review'));
    }

    #[Test]
    public function onlyTheToastsThatNameAPersonOrAStepSaySo(): void
    {
        $verbsNamingAPerson = array_values(array_filter(array_keys(MoveToasts::VERB_TOASTS), MoveToasts::namesPerson(...)));
        $verbsNamingAStep = array_values(array_filter(array_keys(MoveToasts::VERB_TOASTS), MoveToasts::namesStep(...)));

        self::assertSame(['send_to_code_review', 'send_to_testing', 'approve_code', 'ask_for_changes', 'needs_changes'], $verbsNamingAPerson);
        self::assertSame(['move_on'], $verbsNamingAStep);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function toastsWithNobodyNamed(): iterable
    {
        yield 'starting a sentence' => ['send_to_code_review', 'Sent to code review. Someone’s turn now.'];
        yield 'inside a sentence' => ['needs_changes', 'Sent back to someone with your notes.'];
    }

    #[Test]
    #[DataProvider('toastsWithNobodyNamed')]
    public function aTurnOfNobodyIdentityKnowsSaysSomeoneCapitalizedOnlyAtASentencesStart(string $verb, string $expectedToast): void
    {
        self::assertSame($expectedToast, MoveToasts::ofVerb($verb, null, null));
    }

    #[Test]
    public function aNameHoldingAPlaceholderIsNotFilledInAgain(): void
    {
        self::assertSame('Moved to {person} desk.', MoveToasts::ofVerb('move_on', 'Lena', '{person} desk'));
        self::assertSame('Moved to {step}.', MoveToasts::movedTo('{step}'));
        self::assertSame('Sent back to {step}.', MoveToasts::ofVerb('ask_for_changes', '{step}', 'Code review'));
    }

    #[Test]
    public function aMoveToANamedStepSaysWhereTo(): void
    {
        self::assertSame('Moved to Needs input.', MoveToasts::movedTo('Needs input'));
    }

    #[Test]
    public function aToastNamingTheStepWithNoStepGivenFails(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The toast of the verb "move_on" names the step after the move.');

        MoveToasts::ofVerb('move_on', 'Lena', null);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function verbsWithNoToast(): iterable
    {
        yield 'give_it_to, which comes with P03.T8' => ['give_it_to'];
        yield 'an unknown verb' => ['jump'];
    }

    #[Test]
    #[DataProvider('verbsWithNoToast')]
    public function aVerbWithNoToastFails(string $verb): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(\sprintf('The verb "%s" has no toast.', $verb));

        MoveToasts::ofVerb($verb, 'Lena', 'To do');
    }
}
