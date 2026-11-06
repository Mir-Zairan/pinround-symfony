<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Entity;

use App\SharedKernel\Domain\TenantOwned;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A row of the test-only table zz_tenant_probe (see TenantProbeTable), mapped for the test environment only.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'zz_tenant_probe')]
final class TenantProbe implements TenantOwned
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(type: Types::GUID)]
    private string $organizationId;

    #[ORM\Column(type: Types::TEXT)]
    private string $label;

    public function __construct(string $id, string $organizationId, string $label)
    {
        $this->id = $id;
        $this->organizationId = $organizationId;
        $this->label = $label;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function organizationId(): string
    {
        return $this->organizationId;
    }

    public function label(): string
    {
        return $this->label;
    }
}
