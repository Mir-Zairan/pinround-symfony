<?php

declare(strict_types=1);

namespace App\Conversation\Api;

/**
 * The comment was not resolved: the message is a question (only its answer closes it), the comment is removed or
 * already resolved, or the person is neither its author nor the lead of the ticket's team. Nothing was saved. The
 * message is the reason to show the person.
 */
final class ResolveRefused extends \DomainException
{
}
