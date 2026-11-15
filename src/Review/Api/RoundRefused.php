<?php

declare(strict_types=1);

namespace App\Review\Api;

/**
 * The round's content breaks a rule, such as a title over 120 characters, or the ticket has that round already, so
 * nothing was saved. The message is the reason to show the person (422).
 */
final class RoundRefused extends \DomainException
{
}
