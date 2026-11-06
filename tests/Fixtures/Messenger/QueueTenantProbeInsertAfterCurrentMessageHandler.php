<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Messenger;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Infrastructure\Messenger\TenantStamp;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

#[AsMessageHandler]
final readonly class QueueTenantProbeInsertAfterCurrentMessageHandler
{
    public function __construct(private MessageBusInterface $bus)
    {
    }

    public function __invoke(QueueTenantProbeInsertAfterCurrentMessage $message): void
    {
        $this->bus->dispatch(new InsertTenantProbeLabel($message->organizationId, $message->label), [
            new TenantStamp(new OrganizationId($message->organizationId)),
            new DispatchAfterCurrentBusStamp(),
        ]);
    }
}
