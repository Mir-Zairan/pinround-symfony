<?php

declare(strict_types=1);

namespace App\Assist\Infrastructure\Messenger;

use App\Assist\Application\Intake\GenerateIntakeSuggestion;
use App\Assist\Application\Intake\IntakeJobQueue;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Infrastructure\Messenger\TenantStamp;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Dispatches on command.bus, which routes GenerateIntakeSuggestion straight to async_ai (messenger.yaml), not through
 * the outbox: the request that queues it writes no row the job could miss. Stamped with the job's organization, which
 * the handler runs under and refuses to differ from.
 */
final readonly class MessengerIntakeJobQueue implements IntakeJobQueue
{
    public function __construct(
        #[Autowire(service: 'command.bus')]
        private MessageBusInterface $commandBus,
    ) {
    }

    public function queue(GenerateIntakeSuggestion $generateIntakeSuggestion): void
    {
        $this->commandBus->dispatch(new Envelope($generateIntakeSuggestion, [new TenantStamp(new OrganizationId($generateIntakeSuggestion->organizationId))]));
    }
}
