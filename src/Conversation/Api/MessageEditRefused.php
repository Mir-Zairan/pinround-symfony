<?php

declare(strict_types=1);

namespace App\Conversation\Api;

/**
 * The message was not edited: it is removed, it is someone else's, it is not a comment, or the 15 minutes after
 * posting have passed (FR-CNV-06). The message is the reason to show the person.
 */
final class MessageEditRefused extends \DomainException
{
}
