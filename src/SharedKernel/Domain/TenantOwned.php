<?php

declare(strict_types=1);

namespace App\SharedKernel\Domain;

/**
 * Marks an entity whose table carries `organization_id` and row-level security (ADR-0005). The tenant SQL
 * filter adds the organization of the current transaction to every ORM query on it. Put it on the root
 * class of an inheritance tree.
 */
interface TenantOwned
{
}
