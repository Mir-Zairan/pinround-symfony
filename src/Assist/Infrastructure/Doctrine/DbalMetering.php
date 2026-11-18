<?php

declare(strict_types=1);

namespace App\Assist\Infrastructure\Doctrine;

use App\Assist\Application\AiCallUsage;
use App\Assist\Application\Metering;
use App\Assist\Domain\AiFeature;
use App\Organization\Api\ActorContext;
use App\SharedKernel\Application\TenantContext;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * Metering on ast_usage, which pinround_app may only select and insert. Names the tenant's organization_id as well as
 * row-level security, so a row can only land in the tenant's organization.
 */
final readonly class DbalMetering implements Metering
{
    private const string INSERT = 'INSERT INTO ast_usage (organization_id, id, user_id, feature, status, provider,'
        .' model, input_tokens, output_tokens, duration_ms, cost_estimate, at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?)';

    public function __construct(
        private Connection $connection,
        private TenantContext $tenantContext,
        private ClockInterface $clock,
    ) {
    }

    public function record(AiCallUsage $usage, AiFeature $feature, ActorContext $caller): void
    {
        $organizationId = $this->tenantContext->require();
        if (!$caller->organizationId()->equals($organizationId)) {
            throw new \LogicException('An AI call is metered in the organization of the transaction\'s tenant; the caller acted in another one.');
        }

        // The id carries the same instant as at, so ordering by id and by at agree.
        $id = new UuidV7(UuidV7::generate($this->clock->now()));
        // TenantMiddleware sets the tenant when a transaction begins; outside one the policy would refuse the row.
        $this->connection->transactional(static fn (Connection $connection): int|string => $connection->executeStatement(self::INSERT, [
            $organizationId->toString(),
            $id->toRfc4122(),
            $caller->userId()->toString(),
            $feature->value,
            $usage->status->value,
            $usage->provider,
            $usage->model,
            $usage->inputTokens,
            $usage->outputTokens,
            $usage->durationMs,
            $id->getDateTime()->format('Y-m-d H:i:s.uP'),
        ]));
    }
}
