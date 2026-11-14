<?php

declare(strict_types=1);

namespace App\Conversation\Api;

/**
 * The message was not removed: it is already removed, it is someone else's, or it is not a comment. The message is
 * the reason to show the person.
 */
final class MessageRemovalRefused extends \DomainException
{
}
