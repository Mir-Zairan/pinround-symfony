<?php

declare(strict_types=1);

namespace App\Review\Api;

use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * A ticket's checklist round (FR-RND-04, P05.T6): one item per pin of Round 0, which the assignee marks fixed and the
 * tester then confirms OK or sends back as not fixed.
 *
 * Each method runs in the caller's open transaction under the organization as tenant. Who may mark an item is
 * ChecklistPermission's to decide: the caller must have been granted MARK_FIXED or CHECK on the item before calling.
 */
interface ChecklistCommands
{
    public const string STATE_OPEN = 'open';
    public const string STATE_FIXED = 'fixed';
    public const string STATE_OK = 'ok';
    public const string STATE_NOT_FIXED = 'not_fixed';

    /**
     * Makes the ticket's checklist round from the pins of its Round 0, shared at once as the next free round number and
     * owned by whoever reported Round 0. A ticket gets one: a second call makes nothing. No event is published.
     *
     * @return string|null the new round's id; null when the ticket has no Round 0, Round 0 has no pins, or the ticket
     *                     has a checklist round already, and then nothing is written
     *
     * @throws \LogicException when no transaction is open or no tenant is set
     */
    public function createFromReport(TicketId $ticketId, \DateTimeImmutable $at): ?string;

    /**
     * Marks an open or not-fixed item fixed, so the tester checks it; publishes ChecklistItemChanged and clears the
     * actor's pings on the ticket (FR-PNG-05).
     *
     * @param string $itemId the item's id as the request carried it
     *
     * @throws ChecklistRefused NOT_FOUND (answer 404); otherwise the reason to show, such as an item marked fixed
     *                          already or confirmed OK; nothing is saved, published or cleared
     * @throws \LogicException  when no transaction is open or no tenant is set
     */
    public function markFixed(string $itemId, UserId $actor, \DateTimeImmutable $at): CheckItemChange;

    /**
     * Confirms an item marked fixed as OK, which is final; publishes and clears pings as markFixed.
     *
     * @throws ChecklistRefused NOT_FOUND (answer 404); otherwise the reason to show, such as an item not marked fixed;
     *                          nothing is saved, published or cleared
     * @throws \LogicException  when no transaction is open or no tenant is set
     */
    public function markOk(string $itemId, UserId $actor, \DateTimeImmutable $at): CheckItemChange;

    /**
     * Sends an item marked fixed back as not fixed, so the assignee can mark it fixed again; publishes and clears pings
     * as markFixed.
     *
     * @throws ChecklistRefused NOT_FOUND (answer 404); otherwise the reason to show, such as an item not marked fixed;
     *                          nothing is saved, published or cleared
     * @throws \LogicException  when no transaction is open or no tenant is set
     */
    public function markNotFixed(string $itemId, UserId $actor, \DateTimeImmutable $at): CheckItemChange;
}
