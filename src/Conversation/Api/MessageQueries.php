<?php

declare(strict_types=1);

namespace App\Conversation\Api;

/**
 * Messages of the organization of TenantContext, read from cnv_message under row-level security, so each read needs an
 * open transaction whose tenant is that organization. Each read is one query and takes no lock, so a caller that must
 * not race an answer, a withdrawal or a removal serializes with that change itself.
 */
interface MessageQueries
{
    /**
     * Whether the id names a question of the organization that is neither answered nor withdrawn. False for a malformed
     * id, a comment, and an id that names no message of the organization.
     *
     * @throws \LogicException when no transaction is open or no tenant is set, before any query
     */
    public function isOpenQuestion(string $messageId): bool;

    /**
     * Whether the id names a message of the organization, a question or a comment, that is not removed; an answered
     * question or a resolved comment still is. False for a malformed id and an id that names no message of the
     * organization.
     *
     * @throws \LogicException when no transaction is open or no tenant is set, before any query
     */
    public function isPresent(string $messageId): bool;
}
