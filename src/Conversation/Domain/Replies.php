<?php

declare(strict_types=1);

namespace App\Conversation\Domain;

/**
 * The replies under tickets' comments and questions. Each method needs an open transaction under the reply's
 * organization.
 */
interface Replies
{
    public function nextIdentity(): ReplyId;

    /**
     * Saves the new reply at once. Row-level security refuses a reply of another organization than the tenant's with
     * the database's error, and the caller rolls back.
     */
    public function add(Reply $reply): void;
}
