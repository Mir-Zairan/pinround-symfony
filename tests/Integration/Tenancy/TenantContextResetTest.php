<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * In worker mode (ADR-0008) the kernel resets services between requests; the tenant must not survive that.
 */
final class TenantContextResetTest extends KernelTestCase
{
    #[Test]
    public function theKernelResetBetweenRequestsClearsTheTenant(): void
    {
        $container = self::getContainer();
        $tenantContext = $container->get(TenantContext::class);
        $tenantContext->set(new OrganizationId('0197a3c0-0000-7000-8000-00000000000a'));

        $container->get('services_resetter')->reset();

        self::assertNull($tenantContext->get());
    }
}
