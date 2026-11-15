<?php

declare(strict_types=1);

namespace App\Tests\Unit\Conversation\Application;

use App\Conversation\Api\Event\ConversationEvent;
use App\Conversation\Application\ConversationEvents;

final class RecordingConversationEvents implements ConversationEvents
{
    /** @var list<ConversationEvent> */
    private array $published = [];

    public function publish(ConversationEvent $event): void
    {
        $this->published[] = $event;
    }

    /**
     * @return list<ConversationEvent>
     */
    public function published(): array
    {
        return $this->published;
    }
}
