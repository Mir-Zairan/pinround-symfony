<?php

declare(strict_types=1);

namespace App\Review\Api;

use App\SharedKernel\Domain\UserId;

/**
 * The general notes on a shared round (ADR-0048, note of 2026-11-22): a note for the whole round, something its
 * screenshots do not show or a change for all of it, with an optional Pass, Needs fix or Question. Everyone who may
 * see the round reads them; RoundPermission ADD_GENERAL_NOTE says who adds one, which the caller must have been
 * granted on the round. A note is never changed, and it moves nothing.
 */
interface GeneralNoteCommands
{
    /** VerdictCommands' area verdicts, the chips a note may carry. */
    public const string VERDICT_PASS = VerdictCommands::AREA_PASS;
    public const string VERDICT_NEEDS_FIX = VerdictCommands::AREA_NEEDS_FIX;
    public const string VERDICT_QUESTION = VerdictCommands::AREA_QUESTION;
    /** In characters, after trimming. */
    public const int NOTE_MAX_LENGTH = 2000;
    /** The notes one round holds. */
    public const int MAX_PER_ROUND = 200;

    /**
     * Runs in the caller's open transaction under the actor's organization as tenant, and locks the round first.
     *
     * @param string|null $verdict VERDICT_PASS, VERDICT_NEEDS_FIX or VERDICT_QUESTION; null or '' for none
     * @param string      $note    trimmed; 1 to NOTE_MAX_LENGTH characters
     *
     * @throws GeneralNoteRefused NOT_FOUND (answer 404) for a malformed or unknown round id, another organization's,
     *                            the checklist, or an unshared or deleted draft; otherwise the reason to show, such as
     *                            a closed round, an empty or too long note, an unknown verdict or a full round; nothing
     *                            is saved
     * @throws \LogicException    when no transaction is open or no tenant is set
     */
    public function add(string $roundId, UserId $actor, ?string $verdict, string $note, \DateTimeImmutable $at): void;
}
