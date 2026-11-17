<?php

declare(strict_types=1);

namespace App\Ticketing\Infrastructure\Messenger;

use App\SharedKernel\Application\MessageForAnotherTenantRefused;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\Event\SummaryGenerated;
use App\Ticketing\Domain\Summary;
use App\Ticketing\Domain\TicketSummaries;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Stores an AI summary as its ticket's current one, in the transaction of the job that dispatched it on command.bus.
 * A ticket the organization does not have gets nothing: it may have been deleted while the AI wrote.
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class StoreSummaryHandler
{
    public function __construct(private TenantContext $tenantContext, private TicketSummaries $ticketSummaries)
    {
    }

    /**
     * @throws \App\SharedKernel\Application\TenantNotSet when no organization is set, before any read
     * @throws MessageForAnotherTenantRefused             when the event names another organization than the one set
     * @throws \App\Ticketing\Domain\SummaryRefused       when the text, model or prompt version cannot be stored
     */
    public function __invoke(SummaryGenerated $summaryGenerated): void
    {
        if (!$this->tenantContext->require()->equals(new OrganizationId($summaryGenerated->organizationId))) {
            throw new MessageForAnotherTenantRefused();
        }

        $this->ticketSummaries->storeCurrent(Summary::generatedByAi(
            new TicketId($summaryGenerated->ticketId),
            $summaryGenerated->text,
            new UserId($summaryGenerated->requesterId),
            $summaryGenerated->model,
            $summaryGenerated->promptVersion,
            $summaryGenerated->generatedAt,
        ));
    }
}
