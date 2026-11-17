<?php

declare(strict_types=1);

namespace App\Review\UI\RoundPage;

/**
 * The side panel of the selected area (review-round.md, Side panel): the earlier pins to check and the area's verdict,
 * with the forms to give them for a person who may, and the verb to offer once every area has a verdict.
 */
final readonly class RoundPanel
{
    /**
     * Ticketing's Your next step box and Conversation's question composer, which Review's UI may not import (deptrac);
     * VerdictControllerTest follows both links and finds them.
     */
    public const string TICKET_NEXT_STEP_ID = 'ticket-next-step';
    public const string THREAD_ROUTE = 'conversation_thread';
    public const string QUESTION_COMPOSER_ID_PREFIX = 'thread-composer-';
    /** The thread page's query parameter, with a user id, that thread-composer reads to start a question to them. */
    public const string ASK_PARAMETER = 'ask';

    /** With the pin's id; the ids of the controls a verdict's stream gives focus to, in place of the one clicked. */
    public const string UNDO_BUTTON_ID_PREFIX = 'round-panel-undo-';
    /** With the pin's id. */
    public const string FIXED_BUTTON_ID_PREFIX = 'round-panel-fixed-';
    public const string SAVE_BUTTON_ID = 'round-panel-save';

    /**
     * @param int                 $areaPosition       from 0, the area's tab
     * @param string|null         $earlierPinsHeading for example "Pins from round 1"; null when the area has no earlier picture
     * @param list<EarlierPinRow> $earlierPins        the pins on the "before" picture, by number
     * @param string|null         $verdict            pass, needs_fix or question, the chip chosen; null before a reviewer decides
     * @param string|null         $verdictLabel       Pass, Needs fix or Question; null exactly when $verdict is
     * @param string|null         $note               the reviewer's note with the verdict
     * @param string|null         $decidedByName      who saved the verdict; null without one or when the name is not known
     * @param bool                $mayGiveVerdicts    true shows the forms: VerdictPermission SET is granted on an open round
     *                                                of a ticket with a team lead
     * @param string|null         $leadName           the team lead, who sees the verdict next; never null when $mayGiveVerdicts
     * @param NextVerbOffer|null  $nextVerb           only for the ticket's tester while they may give verdicts, once every
     *                                                area has one
     */
    public function __construct(
        public string $areaId,
        public int $areaPosition,
        public string $areaName,
        public ?string $earlierPinsHeading,
        public array $earlierPins,
        public ?string $verdict,
        public ?string $verdictLabel,
        public ?string $note,
        public ?string $decidedByName,
        public bool $mayGiveVerdicts,
        public ?string $leadName,
        public ?NextVerbOffer $nextVerb,
    ) {
    }
}
