<?php

declare(strict_types=1);

namespace App\Conversation\Api;

/**
 * Messages of the organization of TenantContext, read from cnv_message under row-level security, so it needs an open
 * transaction whose tenant is that organization.
 */
interface MessageQueries
{
    /**
     * Whether the id names a message of the organization, a question or a comment, that is not removed; an answered
     * question or a resolved comment is still present. False for a malformed id and an id that names no message of the
     * organization. One query; it takes no lock, so a caller that must not race a removal serializes with it itself.
     *
     * @throws \LogicException when no transaction is open or no tenant is set, before any query
     */
    public function isPresent(string $messageId): bool;
}
