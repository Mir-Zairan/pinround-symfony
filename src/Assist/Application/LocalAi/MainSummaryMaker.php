<?php

declare(strict_types=1);

namespace App\Assist\Application\LocalAi;

use App\Assist\Api\LocalPackageId;
use App\Assist\Api\MainSummaryRefused;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * The write of "Use as main summary": marks the ticket's proposal made the main summary and stores it through
 * Ticketing, as one step of the caller's open transaction that undoes its own writes when it is refused.
 */
interface MainSummaryMaker
{
    /**
     * The mark comes before Ticketing checks the text, so both run in a savepoint: on a refusal the mark is rolled
     * back to it and the caller's transaction stays usable, so a caller that catches the refusal and commits keeps the
     * result a proposal.
     *
     * @param \DateTimeImmutable|null $currentSummaryCreatedAt when the ticket's current summary was made, as the page
     *                                                         read it; null when it has none
     *
     * @throws MainSummaryRefused not_a_proposal when $packageId is not the ticket's proposal or the ticket's current
     *                            summary is no longer the one made at $currentSummaryCreatedAt; summary_rejected when
     *                            Ticketing refuses the text. Nothing changed either way.
     * @throws \LogicException    when no transaction is open; nothing is read or written
     */
    public function makeMain(LocalPackageId $packageId, TicketId $ticketId, ?\DateTimeImmutable $currentSummaryCreatedAt, UserId $chooserId, OrganizationId $organizationId): void;
}
