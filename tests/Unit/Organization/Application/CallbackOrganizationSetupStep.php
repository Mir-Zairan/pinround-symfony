<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Application;

use App\SharedKernel\Application\OrganizationSetupStep;
use App\SharedKernel\Domain\OrganizationId;

final readonly class CallbackOrganizationSetupStep implements OrganizationSetupStep
{
    /**
     * @param \Closure(OrganizationId): void $onSetUp
     */
    public function __construct(private \Closure $onSetUp)
    {
    }

    public function setUp(OrganizationId $organizationId): void
    {
        ($this->onSetUp)($organizationId);
    }
}
