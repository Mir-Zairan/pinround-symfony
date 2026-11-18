<?php

declare(strict_types=1);

namespace App\Assist\Infrastructure\Platform;

use App\Assist\Application\AiPrompt;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Contracts\Service\ResetInterface;

final class PlatformMessages implements ResetInterface
{
    public function messagesFor(AiPrompt $prompt): MessageBag
    {
        return new MessageBag(Message::forSystem($prompt->instructions), Message::ofUser($prompt->content));
    }

    public function reset(): void
    {
    }
}
