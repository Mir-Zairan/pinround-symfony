<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Scheduler;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Infrastructure\Messenger\TenantStamp;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

/**
 * Once a day, sends one SendApiTokenExpiryWarnings per organization with a live token that expires in about a week and
 * was not warned yet (P09.T1). It runs with no tenant set, so it finds those organizations through the resolver
 * organizations_with_expiring_api_tokens (data-model.md); each message carries its organization's TenantStamp, and
 * SendApiTokenExpiryWarningsHandler claims and emails under that organization's policy. Past the cap the order is
 * shuffled per run, so the organizations left out are not the same ones every run.
 */
#[AsPeriodicTask(frequency: '1 day')]
final readonly class QueueApiTokenExpiryWarnings
{
    /**
     * Each message claims at most a page of tokens and sends at most a page of emails, so worker-notify gets through
     * this many well within the day.
     */
    public const int MAX_ORGANIZATIONS_PER_RUN = 10_000;

    /**
     * @param int $maxOrganizationsPerRun MAX_ORGANIZATIONS_PER_RUN; lower only in tests
     */
    public function __construct(
        private Connection $connection,
        #[Autowire(service: 'command.bus')]
        private MessageBusInterface $commandBus,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private int $maxOrganizationsPerRun = self::MAX_ORGANIZATIONS_PER_RUN,
    ) {
    }

    /**
     * @return list<SendApiTokenExpiryWarnings> the messages it sent, at most $maxOrganizationsPerRun; empty when no
     *                                          token expires in the range
     */
    public function __invoke(): array
    {
        // Whole seconds, as the message carries it, so every page of a run claims from this range.
        $ranAt = $this->clock->now()->format(\DATE_ATOM);
        [$expiresFrom, $expiresBefore] = SendApiTokenExpiryWarnings::expiryRangeAfter(new \DateTimeImmutable($ranAt));
        $organizationIds = $this->connection->fetchFirstColumn(
            'SELECT organization_id::text FROM organizations_with_expiring_api_tokens(CAST(? AS timestamptz), CAST(? AS timestamptz))'
            .' ORDER BY md5(organization_id::text || ?), organization_id LIMIT ?',
            [$expiresFrom->format(\DATE_ATOM), $expiresBefore->format(\DATE_ATOM), $ranAt, $this->maxOrganizationsPerRun + 1],
            [3 => ParameterType::INTEGER],
        );
        if (\count($organizationIds) > $this->maxOrganizationsPerRun) {
            $organizationIds = \array_slice($organizationIds, 0, $this->maxOrganizationsPerRun);
            $this->logger->warning(
                'The API token expiry warning job found more than {cap} organizations to warn; it queued {cap} of them and leaves the rest unwarned.',
                ['cap' => $this->maxOrganizationsPerRun],
            );
        }

        $sent = [];
        foreach ($organizationIds as $organizationId) {
            if (!\is_string($organizationId)) {
                throw new \LogicException('organizations_with_expiring_api_tokens returned a value that is not a uuid.');
            }
            $organization = new OrganizationId($organizationId);
            $warnings = new SendApiTokenExpiryWarnings($organization->toString(), $ranAt);
            $this->commandBus->dispatch($warnings, [new TenantStamp($organization)]);
            $sent[] = $warnings;
        }

        return $sent;
    }
}
