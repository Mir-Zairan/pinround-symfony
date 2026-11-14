<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Scheduler;

use App\Notification\Application\Digest\SendDigest;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\SharedKernel\Infrastructure\Messenger\TenantStamp;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

/**
 * Every 15 minutes, sends one SendDigest per person with a ping or question due in an email digest (FR-PNG-09). It
 * runs with no tenant set, so it finds them through the resolver digest_recipients (data-model.md); each SendDigest
 * carries its organization's TenantStamp and SendDigestHandler decides under that organization's policy whether a
 * digest goes out. The resolver offers a person again every run while their items wait unread, so the order is
 * shuffled per run: past the cap, the people left out this run are not the same ones every run.
 */
#[AsPeriodicTask(frequency: '15 minutes')]
final readonly class QueueDigests
{
    /**
     * Most messages stop at SendDigestHandler's first indexed read, so worker-notify gets through this many well within
     * the 15 minutes before the next run.
     */
    public const int MAX_DIGESTS_PER_RUN = 10_000;

    /**
     * @param int $maxDigestsPerRun MAX_DIGESTS_PER_RUN; lower only in tests
     */
    public function __construct(
        private Connection $connection,
        #[Autowire(service: 'command.bus')]
        private MessageBusInterface $commandBus,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private int $maxDigestsPerRun = self::MAX_DIGESTS_PER_RUN,
    ) {
    }

    /**
     * @return list<SendDigest> the messages it sent, at most $maxDigestsPerRun; empty when no one has anything due
     */
    public function __invoke(): array
    {
        $now = $this->clock->now()->format('Y-m-d H:i:sP');
        $recipients = $this->connection->fetchAllNumeric(
            'SELECT organization_id::text, user_id::text FROM digest_recipients(CAST(? AS timestamptz))'
            .' ORDER BY md5(organization_id::text || user_id::text || ?), organization_id, user_id LIMIT ?',
            [$now, $now, $this->maxDigestsPerRun + 1],
            [2 => ParameterType::INTEGER],
        );
        if (\count($recipients) > $this->maxDigestsPerRun) {
            $recipients = \array_slice($recipients, 0, $this->maxDigestsPerRun);
            $this->logger->warning(
                'The digest job found more than {cap} people to email; it queued {cap} of them and leaves the rest to a later run.',
                ['cap' => $this->maxDigestsPerRun],
            );
        }

        $sent = [];
        foreach ($recipients as [$organizationId, $userId]) {
            if (!\is_string($organizationId) || !\is_string($userId)) {
                throw new \LogicException('digest_recipients returned a value that is not a uuid.');
            }
            $organization = new OrganizationId($organizationId);
            $sendDigest = new SendDigest($organization->toString(), new UserId($userId)->toString());
            $this->commandBus->dispatch($sendDigest, [new TenantStamp($organization)]);
            $sent[] = $sendDigest;
        }

        return $sent;
    }
}
