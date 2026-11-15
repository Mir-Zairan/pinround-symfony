<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

/**
 * The screenshot part of the new ticket form breaks a rule, so none of it was saved. The message is the reason to show
 * under the screenshot field (422).
 */
final class NewTicketReportRefused extends \DomainException
{
}
