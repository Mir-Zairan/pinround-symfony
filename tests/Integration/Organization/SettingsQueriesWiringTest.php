<?php

declare(strict_types=1);

namespace App\Tests\Integration\Organization;

use App\Organization\Api\SettingsQueries;
use App\Organization\Api\StepPolicy;
use App\Organization\Infrastructure\Doctrine\DbalSettingsQueries;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Readers get the step policy and the triager read once per request (ADR-0008 reset between requests). AddStep and SetRule read it
 * fresh in their transaction, which TeamWorkflowControllerTest proves with a policy changed after the voter read it.
 */
final class SettingsQueriesWiringTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000001ca1';

    #[Test]
    public function theAutowiredSettingsQueriesReadOncePerRequestAndAgainAfterTheKernelReset(): void
    {
        $container = self::getContainer();
        $database = new class implements SettingsQueries {
            public int $reads = 0;

            public function stepPolicyOf(OrganizationId $organizationId): StepPolicy
            {
                ++$this->reads;

                return StepPolicy::Team;
            }

            public function triagerOf(OrganizationId $organizationId): ?UserId
            {
                return null;
            }
        };
        $container->set(DbalSettingsQueries::class, $database);
        $settingsQueries = $container->get(SettingsQueries::class);

        $settingsQueries->stepPolicyOf(new OrganizationId(self::ACME_FOODS));
        $settingsQueries->stepPolicyOf(new OrganizationId(self::ACME_FOODS));
        self::assertSame(1, $database->reads);

        $container->get('services_resetter')->reset();
        $settingsQueries->stepPolicyOf(new OrganizationId(self::ACME_FOODS));
        self::assertSame(2, $database->reads);
    }

    #[Test]
    public function theAutowiredSettingsQueriesReadNoTriagerOncePerRequestAndAgainAfterTheKernelReset(): void
    {
        $container = self::getContainer();
        $database = new class implements SettingsQueries {
            public int $triagerReads = 0;

            public function stepPolicyOf(OrganizationId $organizationId): StepPolicy
            {
                return StepPolicy::Team;
            }

            public function triagerOf(OrganizationId $organizationId): ?UserId
            {
                ++$this->triagerReads;

                return null;
            }
        };
        $container->set(DbalSettingsQueries::class, $database);
        $settingsQueries = $container->get(SettingsQueries::class);

        self::assertNull($settingsQueries->triagerOf(new OrganizationId(self::ACME_FOODS)));
        $settingsQueries->triagerOf(new OrganizationId(self::ACME_FOODS));
        self::assertSame(1, $database->triagerReads);

        $container->get('services_resetter')->reset();
        $settingsQueries->triagerOf(new OrganizationId(self::ACME_FOODS));
        self::assertSame(2, $database->triagerReads);
    }
}
