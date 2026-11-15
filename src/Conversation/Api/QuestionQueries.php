<?php

declare(strict_types=1);

namespace App\Conversation\Api;

/**
 * Questions of the organization of TenantContext, read from cnv_message under row-level security, so it needs an open
 * transaction whose tenant is that organization.
 */
interface QuestionQueries
{
    /**
     * Whether the message is a question that is neither answered nor withdrawn. False for a malformed id, a comment,
     * and an id that names no message of the organization. One query; it takes no lock, so a caller that must not
     * race an answer or a withdrawal serializes with that close itself.
     *
     * @throws \LogicException when no transaction is open or no tenant is set, before any query
     */
    public function isOpen(string $questionId): bool;
}
