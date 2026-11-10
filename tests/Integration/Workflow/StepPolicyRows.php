<?php

declare(strict_types=1);

namespace App\Tests\Integration\Workflow;

use App\Organization\Api\StepPolicy;
use App\Organization\Application\ChangeStepPolicy;
use App\Organization\Domain\OrgSettings;
use App\Organization\Infrastructure\Doctrine\DoctrineOrgSettingsStore;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Commits an organization's settings row with a chosen step policy, as the app would. The caller removes the row with
 * Organization's OrganizationRows.
 */
final readonly class StepPolicyRows
{
    private const string SARA = '0197a3c0-0000-7000-8000-000000001dc1';

    public function __construct(private EntityManagerInterface $entityManager, private TenantTransactions $transactions)
    {
    }

    public function set(string $organizationId, StepPolicy $stepPolicy): void
    {
        $store = new DoctrineOrgSettingsStore($this->entityManager);
        $this->transactions->committedAs($organizationId, static fn () => $store->add(
            new OrgSettings(new OrganizationId($organizationId), new UserId(self::SARA), new \DateTimeImmutable('2027-03-15 10:00:00 UTC')),
        ));
        $this->transactions->committedAs($organizationId, static fn () => new ChangeStepPolicy($store)(
            new OrganizationId($organizationId),
            $stepPolicy,
            new UserId(self::SARA),
            new \DateTimeImmutable('2027-03-16 09:00:00 UTC'),
        ));
    }
}
