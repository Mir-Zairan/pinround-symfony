<?php

declare(strict_types=1);

namespace App\Conversation\Application;

use App\Conversation\Api\Event\ConversationEvent;

interface ConversationEvents
{
    /**
     * Writes the event to the outbox in the open transaction, so it is sent only when the change commits.
     *
     * @throws \LogicException when no transaction is open
     */
    public function publish(ConversationEvent $event): void;
}
