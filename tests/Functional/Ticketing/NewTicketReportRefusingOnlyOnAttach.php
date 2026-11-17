<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ticketing;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\NewTicketReport;
use App\Ticketing\Api\NewTicketReportRefused;

/**
 * The real NewTicketReport whose up-front check lets everything through, as when a picture is refused only once the
 * ticket is made: refusalOf still runs the real one, with its tenant check and reads, but answers null; attach is the
 * real one, so a report it refuses throws NewTicketReportRefused after the ticket exists. Counts those refusals.
 */
final class NewTicketReportRefusingOnlyOnAttach implements NewTicketReport
{
    public int $attachRefusalCount = 0;

    public function __construct(private readonly NewTicketReport $realReport)
    {
    }

    public function refusalOf(OrganizationId $organizationId, UserId $reporterId, array $postedReport): ?string
    {
        $this->realReport->refusalOf($organizationId, $reporterId, $postedReport);

        return null;
    }

    public function attach(OrganizationId $organizationId, UserId $reporterId, TicketId $ticketId, array $postedReport, \DateTimeImmutable $reportedAt): void
    {
        try {
            $this->realReport->attach($organizationId, $reporterId, $ticketId, $postedReport, $reportedAt);
        } catch (NewTicketReportRefused $refused) {
            ++$this->attachRefusalCount;

            throw $refused;
        }
    }
}
