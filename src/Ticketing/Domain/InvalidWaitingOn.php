<?php

declare(strict_types=1);

namespace App\Ticketing\Domain;

/**
 * Who a ticket waits on does not fit the step it moves to, or the label is blank or too long.
 */
final class InvalidWaitingOn extends \InvalidArgumentException
{
}
