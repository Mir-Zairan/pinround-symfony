<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Messenger;

/**
 * Test-only message: its handler persists one TenantProbe entity and leaves the flush to doctrine_transaction, as a
 * product handler does. Saving a second probe with the same id fails that flush, which closes the entity manager.
 */
final readonly class SaveTenantProbe
{
    public function __construct(public string $probeId, public string $organizationId, public string $label)
    {
    }
}
