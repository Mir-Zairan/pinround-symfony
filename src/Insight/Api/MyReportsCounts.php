<?php

declare(strict_types=1);

namespace App\Insight\Api;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * Counts of My reports over all its pages, of the organization of TenantContext under row-level security, so they need
 * an open transaction whose tenant is that organization.
 */
interface MyReportsCounts
{
    /**
     * The menu's badge: the reporter's open reports with a question that waits on their answer (ReportGroup::NeedsYou).
     * One query on ins_ticket_turn by its index on the turn person. It applies no visibility predicate, as a reporter
     * may always see the tickets they reported (TicketVisibilitySql).
     *
     * @throws \LogicException when no transaction is open, no tenant is set, or $organizationId is not the tenant,
     *                         before any query
     */
    public function questionsWaitingOn(OrganizationId $organizationId, UserId $reporterId): int;

    /**
     * My reports' intro line: the same tickets as HomeQueries::myReports, on every page. One query.
     *
     * @throws \LogicException when no transaction is open, no tenant is set, or the viewer is of another organization
     *                         than the tenant, before any query
     */
    public function totalsOf(TicketViewer $viewer): MyReportsTotals;
}
