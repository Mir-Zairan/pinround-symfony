<?php

declare(strict_types=1);

namespace App\Conversation\Api;

use App\SharedKernel\Domain\TicketId;

/**
 * A ticket's thread for its page. It reads cnv_message of the organization of TenantContext under row-level security,
 * so it needs an open transaction whose tenant is that organization.
 */
interface ThreadQueries
{
    /**
     * One query. It does not check visibility: pass only a ticket the viewer may see.
     *
     * @throws \LogicException when no transaction is open or no tenant is set, before any query
     */
    public function threadOf(TicketId $ticketId): ThreadView;
}
