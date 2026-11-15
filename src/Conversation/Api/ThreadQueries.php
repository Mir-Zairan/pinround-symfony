<?php

declare(strict_types=1);

namespace App\Conversation\Api;

use App\SharedKernel\Domain\TicketId;

/**
 * A ticket's thread for its page. It reads cnv_message and cnv_reply of the organization of TenantContext under
 * row-level security, so it needs an open transaction whose tenant is that organization.
 */
interface ThreadQueries
{
    /**
     * One query, and one more for the answers when a listed question is answered. It does not check visibility: pass
     * only a ticket the viewer may see.
     *
     * @throws \LogicException when no transaction is open or no tenant is set, before any query
     */
    public function threadOf(TicketId $ticketId): ThreadView;
}
