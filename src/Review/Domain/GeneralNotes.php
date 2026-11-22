<?php

declare(strict_types=1);

namespace App\Review\Domain;

/**
 * The general notes on shared rounds (GeneralNote). Each method needs an open transaction under the round's
 * organization as tenant, and reads or writes that organization's rows only.
 */
interface GeneralNotes
{
    public function nextId(): GeneralNoteId;

    /**
     * Locks the round's row until the transaction ends, so notes on one round are added one after the other and the
     * cap is counted on a list nobody else is adding to.
     *
     * @return ReviewedRound|null null when the tenant has no such round
     *
     * @throws \LogicException when no transaction is open or no tenant is set
     */
    public function lockRound(RoundId $roundId): ?ReviewedRound;

    /**
     * @throws \LogicException when no transaction is open or no tenant is set
     */
    public function countOn(RoundId $roundId): int;

    /**
     * @throws \LogicException when no transaction is open or no tenant is set; a round of another organization fails
     *                         the database's foreign key, so the caller adds only to a round lockRound found
     */
    public function add(GeneralNote $note): void;
}
