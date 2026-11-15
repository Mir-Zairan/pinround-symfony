<?php

declare(strict_types=1);

namespace App\Conversation\Api;

/**
 * The trimmed body is empty or longer than 10,000 characters, so nothing was posted or changed. The message is the
 * reason to show the person.
 */
final class MessageBodyRefused extends \DomainException
{
}
