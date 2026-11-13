<?php

declare(strict_types=1);

namespace App\Ticketing\UI;

/**
 * The toasts that answer a move on the ticket page (ticket-page.md, Your next step box): one for each verb the page
 * offers, "Moved to <step>." for the lead's Move to another step, and the refusals. {person} is the first name of whose
 * turn it is after the move, {step} the name of the step the ticket is in after it.
 */
final class MoveToasts
{
    /**
     * By Workflow verb code, every verb but give_it_to, which names its person and has its own route and GIVEN_TO;
     * TicketMoveControllerTest keeps the codes the same as Workflow's.
     */
    public const array VERB_TOASTS = [
        'plan_it' => 'Planned. It is in To do now.',
        'start_work' => 'Started. Status is In progress.',
        'send_to_code_review' => 'Sent to code review. {person}’s turn now.',
        'send_to_testing' => 'Sent to testing. {person}’s turn now.',
        'approve_code' => 'Code approved. {person}’s turn to test.',
        'ask_for_changes' => 'Sent back to {person}.',
        'pass_it' => 'Passed. The ticket is Done.',
        'needs_changes' => 'Sent back to {person} with your notes.',
        'input_given' => 'Back to work.',
        'go_on' => 'Back in progress.',
        'reopen' => 'Reopened. Back in progress.',
        'put_on_hold' => 'On hold. It waits on you.',
        'move_on' => self::MOVED_TO,
    ];

    public const string MOVED_TO = 'Moved to {step}.';
    public const string GIVEN_TO = 'Given to {person}.';
    public const string CHANGED_MEANWHILE = 'Someone changed this ticket. The page is updated.';
    public const string FORM_EXPIRED = 'The page was open too long. Try again.';
    /** For a refusal whose rule has no note, or that no rule makes, such as a step that is the ticket's step now. */
    public const string MOVE_REFUSED = 'You can’t make this move now.';
    public const string WAITING_ON_REFUSED = 'Name one person, or write a label of one line and 100 characters or fewer.';
    /** The move forms' data-failed-toast: assets/app.js shows it after an answer with no Turbo Stream reloads the page. */
    public const string MOVE_FAILED = 'Could not move the ticket. Try again.';

    /** For a turn whose person Identity does not know any more, so the toast never fails on a missing name. */
    public const string SOMEONE = 'someone';

    private const string PERSON = '{person}';
    private const string STEP = '{step}';

    public static function namesPerson(string $verb): bool
    {
        return str_contains(self::templateOf($verb), self::PERSON);
    }

    public static function namesStep(string $verb): bool
    {
        return str_contains(self::templateOf($verb), self::STEP);
    }

    /**
     * @param string|null $personFirstName null when the turn after the move is nobody Identity knows; the toast then
     *                                     says SOMEONE, capitalized where it starts a sentence
     * @param string|null $stepName        the step after the move, for a verb whose toast names it
     *
     * @throws \LogicException for a verb with no toast, or a toast that names the step with no step given
     */
    public static function ofVerb(string $verb, ?string $personFirstName, ?string $stepName): string
    {
        $template = self::templateOf($verb);
        if (str_contains($template, self::STEP) && null === $stepName) {
            throw new \LogicException(\sprintf('The toast of the verb "%s" names the step after the move.', $verb));
        }
        $person = $personFirstName ?? self::SOMEONE;

        // strtr tries the longer key first, so a name that starts a sentence is capitalized; a step or person's name
        // that holds a placeholder is not filled in again.
        return strtr($template, ['. '.self::PERSON => '. '.ucfirst($person), self::PERSON => $person, self::STEP => (string) $stepName]);
    }

    public static function movedTo(string $stepName): string
    {
        return strtr(self::MOVED_TO, [self::STEP => $stepName]);
    }

    /**
     * @param string|null $personFirstName null for a person Identity does not know; the toast then says SOMEONE
     */
    public static function givenTo(?string $personFirstName): string
    {
        return strtr(self::GIVEN_TO, [self::PERSON => $personFirstName ?? self::SOMEONE]);
    }

    /**
     * @throws \LogicException for a verb with no toast
     */
    private static function templateOf(string $verb): string
    {
        return self::VERB_TOASTS[$verb] ?? throw new \LogicException(\sprintf('The verb "%s" has no toast.', $verb));
    }
}
