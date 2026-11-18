<?php

declare(strict_types=1);

namespace App\Ticketing\Infrastructure;

use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\SystemMessage;

final readonly class SummaryMessages
{
    public function instructions(string $text): SystemMessage
    {
        return Message::forSystem($text);
    }
}
