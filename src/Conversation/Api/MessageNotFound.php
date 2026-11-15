<?php

declare(strict_types=1);

namespace App\Conversation\Api;

/**
 * The ticket has no message with that id in the organization, or the id is malformed; the caller answers it as not
 * found (404).
 */
final class MessageNotFound extends \DomainException
{
}
