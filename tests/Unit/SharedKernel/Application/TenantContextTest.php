<?php

declare(strict_types=1);

namespace App\Tests\Unit\SharedKernel\Application;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantNotSet;
use App\SharedKernel\Domain\OrganizationId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TenantContextTest extends TestCase
{
    private const string ORG_A = '0197a3c0-0000-7000-8000-00000000000a';
    private const string ORG_B = '0197a3c0-0000-7000-8000-00000000000b';

    #[Test]
    public function itStartsWithoutATenant(): void
    {
        self::assertNull(new TenantContext()->get());
    }

    #[Test]
    public function itReturnsTheTenantLastSet(): void
    {
        $tenantContext = new TenantContext();
        $tenantContext->set(new OrganizationId(self::ORG_A));
        $tenantContext->set(new OrganizationId(self::ORG_B));

        self::assertSame(self::ORG_B, $tenantContext->get()?->toString());
        self::assertSame(self::ORG_B, $tenantContext->require()->toString());
    }

    #[Test]
    public function requireThrowsWhenNoTenantIsSet(): void
    {
        $this->expectException(TenantNotSet::class);

        new TenantContext()->require();
    }

    #[Test]
    public function clearRemovesTheTenant(): void
    {
        $tenantContext = new TenantContext();
        $tenantContext->set(new OrganizationId(self::ORG_A));

        $tenantContext->clear();

        self::assertNull($tenantContext->get());
    }

    #[Test]
    public function resetRemovesTheTenant(): void
    {
        $tenantContext = new TenantContext();
        $tenantContext->set(new OrganizationId(self::ORG_A));

        $tenantContext->reset();

        self::assertNull($tenantContext->get());
    }
}
