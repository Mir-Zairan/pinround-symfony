<?php

declare(strict_types=1);

namespace App\Ticketing\Application;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\GeneratedSummaryRefused;
use App\Ticketing\Api\TicketSummaryCommands;
use App\Ticketing\Domain\Summary;
use App\Ticketing\Domain\SummaryRefused;
use App\Ticketing\Domain\TicketSummaries;

/**
 * TicketSummaryCommands, each summary checked against the Domain's summary rules before TicketSummaries stores it; the
 * contract is on {@see TicketSummaryCommands}.
 */
final readonly class CheckedTicketSummaryCommands implements TicketSummaryCommands
{
    public function __construct(private TenantContext $tenantContext, private TicketSummaries $ticketSummaries)
    {
    }

    public function storeAiSummary(
        OrganizationId $organizationId,
        TicketId $ticketId,
        string $text,
        UserId $requesterId,
        string $model,
        string $promptVersion,
        \DateTimeImmutable $generatedAt,
    ): void {
        if (!$this->tenantContext->require()->equals($organizationId)) {
            throw new \LogicException('An AI summary is stored under its own organization; another one is set.');
        }

        try {
            $summary = Summary::generatedByAi($ticketId, $text, $requesterId, $model, $promptVersion, $generatedAt);
        } catch (SummaryRefused $refused) {
            throw new GeneratedSummaryRefused($refused->getMessage(), $refused);
        }
        $this->ticketSummaries->storeCurrent($summary);
    }
}
