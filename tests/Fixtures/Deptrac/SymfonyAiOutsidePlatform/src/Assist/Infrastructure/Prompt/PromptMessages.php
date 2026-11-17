<?php

declare(strict_types=1);

namespace App\Assist\Infrastructure\Prompt;

use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\UserMessage;

final readonly class PromptMessages
{
    public function userMessage(string $content): UserMessage
    {
        return Message::ofUser($content);
    }
}
