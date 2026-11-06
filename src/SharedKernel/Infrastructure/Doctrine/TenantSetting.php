<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Doctrine;

/**
 * The PostgreSQL setting that carries the organization of the current transaction (ADR-0005). TenantConnection
 * sets it; the tenant_isolation policy and TenantFilter read it, so a rename here moves all three together.
 */
final class TenantSetting
{
    public const string NAME = 'app.org_id';

    /**
     * The organization as uuid, or NULL when the setting is unset (NULL) or reset on a pooled connection ('').
     */
    public const string CURRENT_ORG_SQL = "NULLIF(current_setting('".self::NAME."', true), '')::uuid";
}
