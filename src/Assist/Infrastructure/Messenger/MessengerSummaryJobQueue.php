<?php

declare(strict_types=1);

namespace App\Assist\Infrastructure\Messenger;

use App\Assist\Application\Summaries\GenerateSummary;
use App\Assist\Application\Summaries\SummaryJobQueue;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Infrastructure\Messenger\TenantStamp;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Dispatches on command.bus, which routes GenerateSummary straight to async_ai (messenger.yaml), not through the
 * outbox: the request that queues it writes no row the job could miss. Stamped with the job's organization, which
 * the handler runs under and refuses to differ from.
 */
final readonly class MessengerSummaryJobQueue implements SummaryJobQueue
{
    public function __construct(
        #[Autowire(service: 'command.bus')]
        private MessageBusInterface $commandBus,
    ) {
    }

    public function queue(GenerateSummary $generateSummary): void
    {
        $this->commandBus->dispatch(new Envelope($generateSummary, [new TenantStamp(new OrganizationId($generateSummary->organizationId))]));
    }
}
