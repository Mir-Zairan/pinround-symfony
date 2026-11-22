<?php

declare(strict_types=1);

namespace App\Web\UI\MyReports;

/**
 * One report as My reports shows it (my-reports.md, Card).
 */
final readonly class ReportCard
{
    /**
     * @param string      $url             the ticket page
     * @param string      $sideText        "no change for N days", "changed recently" or "updated 5 Nov"
     * @param bool        $isStuck         whether the side text is a Stuck report's, shown in the waiting colour
     * @param bool        $isWaiting       whether the ticket waits at its stage: on hold, or on an open question
     * @param string|null $avatarName      the full name the avatar shows: the asker's when the Now line quotes their
     *                                     question, else the person's whose turn it is; null when it is a label's
     *                                     turn, nobody's, the ticket is done, or the person left
     * @param string      $nowText         what happens now, for example "Omar builds it"
     * @param bool        $isAnswerOffered whether the card offers "Answer" (Needs you)
     * @param string|null $pinProgressText for example "1 of 2 pins OK"; null when the ticket has no checklist
     * @param string|null $pingFirstName   the first name of the person whose turn it is, whom the card offers to ping
     *                                     ("Ping Omar") on a Stuck or Moving report; null on a Needs you or done
     *                                     report, or when it is the viewer's turn, a label's, nobody's, or the person
     *                                     left
     */
    public function __construct(
        public string $key,
        public string $title,
        public string $url,
        public string $sideText,
        public bool $isStuck,
        public ReportStage $stage,
        public bool $isWaiting,
        public ?string $avatarName,
        public string $nowText,
        public bool $isAnswerOffered,
        public ?string $pinProgressText,
        public ?string $pingFirstName,
    ) {
    }
}
