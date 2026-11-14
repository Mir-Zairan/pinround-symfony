<?php

declare(strict_types=1);

namespace App\Conversation\Api;

/**
 * The reply was not posted: the comment or question it answers was removed. Nothing was saved. The message is the
 * reason to show the person.
 */
final class ReplyRefused extends \DomainException
{
}
