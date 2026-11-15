<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Scheduler;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Infrastructure\Messenger\TenantStamp;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

/**
 * Every 5 minutes, sends one ReleaseDueNotifications per organization holding a notification for quiet hours until
 * now or earlier. It runs with no tenant set, so it finds those organizations through the resolver
 * organizations_with_due_notifications (data-model.md) and each release runs under its own organization's policy.
 * The bell already shows a due row on its next read; the release is what pushes it to open pages, at most 5 minutes
 * late. Safe to run twice at once: each release is one UPDATE, so a row is released and pushed once.
 */
#[AsPeriodicTask(frequency: '5 minutes')]
final readonly class ReleaseHeldNotifications
{
    public function __construct(
        private Connection $connection,
        #[Autowire(service: 'command.bus')]
        private MessageBusInterface $commandBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<string> the organizations it sent a release to, in id order; empty when nothing was due
     */
    public function __invoke(): array
    {
        $organizationIds = [];
        $dueOrganizations = $this->connection->fetchFirstColumn(
            'SELECT organization_id FROM organizations_with_due_notifications(?)',
            [$this->clock->now()->format('Y-m-d H:i:sP')],
        );
        foreach ($dueOrganizations as $organizationId) {
            if (!\is_string($organizationId)) {
                throw new \LogicException('organizations_with_due_notifications returned a value that is not a uuid.');
            }
            $organization = new OrganizationId($organizationId);
            $this->commandBus->dispatch(new ReleaseDueNotifications($organization->toString()), [new TenantStamp($organization)]);
            $organizationIds[] = $organization->toString();
        }

        return $organizationIds;
    }
}
