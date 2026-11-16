<?php

declare(strict_types=1);

namespace App\Review\Api;

use App\SharedKernel\Domain\UserId;

/**
 * The reviewer's verdicts in a review round (FR-RND-05): Fixed or Still wrong on each pin of the earlier round, and
 * Pass, Needs fix or Question with an optional note on each screen area. No verdict moves the ticket; the round page
 * offers the matching verb once every area has one (phase 5 design notes).
 *
 * Each method runs in the caller's open transaction under the actor's organization as tenant, locks the round first,
 * and publishes one ReviewEvent with the change. Who may give verdicts is VerdictPermission::SET's to decide: the
 * caller must have been granted it on the round before calling.
 */
interface VerdictCommands
{
    public const string PIN_FIXED = 'fixed';
    public const string PIN_STILL_WRONG = 'still_wrong';
    public const string AREA_PASS = 'pass';
    public const string AREA_NEEDS_FIX = 'needs_fix';
    public const string AREA_QUESTION = 'question';

    /**
     * @param string $roundId the review round's id as the request carried it
     * @param string $pinId   a pin on the "before" picture of one of the round's areas
     * @param string $verdict PIN_FIXED or PIN_STILL_WRONG
     *
     * @throws VerdictRefused  NOT_FOUND or PIN_NOT_FOUND (answer 404); otherwise the reason to show, such as a pin that
     *                         has a verdict already, an AI suggestion nobody kept or a closed round; nothing is saved
     * @throws \LogicException when no transaction is open or no tenant is set
     */
    public function setPinVerdict(string $roundId, string $pinId, UserId $actor, string $verdict, \DateTimeImmutable $at): void;

    /**
     * Clears the pin's verdict, so it can be given again.
     *
     * @throws VerdictRefused  NOT_FOUND or PIN_NOT_FOUND (answer 404); otherwise the reason to show, such as a pin with
     *                         no verdict; nothing is saved
     * @throws \LogicException when no transaction is open or no tenant is set
     */
    public function undoPinVerdict(string $roundId, string $pinId, UserId $actor, \DateTimeImmutable $at): void;

    /**
     * Saves the area's verdict and note in place of any earlier one.
     *
     * @param string|null $verdict AREA_PASS, AREA_NEEDS_FIX or AREA_QUESTION; null when no chip was chosen
     * @param string|null $note    trimmed; a blank one is none
     *
     * @throws VerdictRefused  NOT_FOUND or AREA_NOT_FOUND (answer 404); PICK_A_VERDICT with no or an unknown verdict;
     *                         otherwise the reason to show, such as a note over 1,000 characters; nothing is saved
     * @throws \LogicException when no transaction is open or no tenant is set
     */
    public function saveAreaVerdict(string $roundId, string $areaId, UserId $actor, ?string $verdict, ?string $note, \DateTimeImmutable $at): void;
}
