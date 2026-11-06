<?php

declare(strict_types=1);

namespace App\Tests\Unit\SharedKernel\Application;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantNotSet;
use App\SharedKernel\Application\TenantSwitchRefused;
use App\SharedKernel\Domain\OrganizationId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TenantContextTest extends TestCase
{
    private const string ORG_A = '0197a3c0-0000-7000-8000-00000000000a';
    private const string ORG_B = '0197a3c0-0000-7000-8000-00000000000b';

    private RecordingTenantSwitchGuard $switchGuard;
    private TenantContext $tenantContext;

    protected function setUp(): void
    {
        $this->switchGuard = new RecordingTenantSwitchGuard();
        $this->tenantContext = new TenantContext($this->switchGuard);
    }

    #[Test]
    public function itStartsWithoutATenant(): void
    {
        self::assertNull($this->tenantContext->get());
    }

    #[Test]
    public function itReturnsTheTenantLastSet(): void
    {
        $this->tenantContext->set(new OrganizationId(self::ORG_A));
        $this->tenantContext->set(new OrganizationId(self::ORG_B));

        self::assertSame(self::ORG_B, $this->tenantContext->get()?->toString());
        self::assertSame(self::ORG_B, $this->tenantContext->require()->toString());
    }

    #[Test]
    public function requireThrowsWhenNoTenantIsSet(): void
    {
        $this->expectException(TenantNotSet::class);

        $this->tenantContext->require();
    }

    #[Test]
    public function clearRemovesTheTenant(): void
    {
        $this->tenantContext->set(new OrganizationId(self::ORG_A));

        $this->tenantContext->clear();

        self::assertNull($this->tenantContext->get());
    }

    #[Test]
    public function resetRemovesTheTenantWithoutTheGuard(): void
    {
        $this->tenantContext->set(new OrganizationId(self::ORG_A));
        $this->switchGuard->isRefusing = true;

        $this->tenantContext->reset();

        self::assertNull($this->tenantContext->get());
        self::assertSame(0, $this->switchGuard->switchCount);
    }

    #[Test]
    public function theFirstSetAndSettingTheSameOrganizationAgainAreNotASwitch(): void
    {
        $this->tenantContext->set(new OrganizationId(self::ORG_A));
        $this->tenantContext->set(new OrganizationId(self::ORG_A));

        self::assertSame(0, $this->switchGuard->switchCount);
    }

    #[Test]
    public function settingAnotherOrganizationOrClearingOneIsASwitch(): void
    {
        $this->tenantContext->set(new OrganizationId(self::ORG_A));
        $this->tenantContext->set(new OrganizationId(self::ORG_B));
        $this->tenantContext->clear();
        $this->tenantContext->clear();

        self::assertSame(2, $this->switchGuard->switchCount);
    }

    #[Test]
    public function aRefusedSwitchToAnotherOrganizationKeepsTheCurrentOne(): void
    {
        $this->tenantContext->set(new OrganizationId(self::ORG_A));
        $this->switchGuard->isRefusing = true;

        try {
            $this->tenantContext->set(new OrganizationId(self::ORG_B));
            self::fail('The switch was not refused.');
        } catch (TenantSwitchRefused) {
        }

        self::assertSame(self::ORG_A, $this->tenantContext->get()?->toString());
    }

    #[Test]
    public function aRefusedClearKeepsTheCurrentOrganization(): void
    {
        $this->tenantContext->set(new OrganizationId(self::ORG_A));
        $this->switchGuard->isRefusing = true;

        try {
            $this->tenantContext->clear();
            self::fail('The switch was not refused.');
        } catch (TenantSwitchRefused) {
        }

        self::assertSame(self::ORG_A, $this->tenantContext->get()?->toString());
    }
}
