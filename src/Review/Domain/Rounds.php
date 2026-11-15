<?php

declare(strict_types=1);

namespace App\Review\Domain;

/**
 * The rounds of tickets with their screenshots and pins. Each method that writes needs an open transaction under the
 * round's organization.
 */
interface Rounds
{
    public function nextIdentity(): RoundId;

    public function nextScreenshotIdentity(): ScreenshotId;

    public function nextPinIdentity(): PinId;

    /**
     * Writes the round with its screenshots and pins, all or nothing.
     *
     * @throws RoundSlotTaken  when the ticket has a round with the same number, or the owner an open draft on it
     * @throws \LogicException when no transaction is open, or the round id was added before (a bug or a redelivered
     *                         message, not a slot)
     */
    public function add(Round $round): void;
}
