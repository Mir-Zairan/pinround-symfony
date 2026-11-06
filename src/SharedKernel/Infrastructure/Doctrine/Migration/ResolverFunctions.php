<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Doctrine\Migration;

/**
 * The SECURITY DEFINER lookups that run before the tenant is known, the only row-level security
 * exceptions (ADR-0005, data-model.md › Lookups before the tenant is known). Each is owned by
 * pinround_resolver, returns ids only, and only pinround_app may execute it.
 */
final class ResolverFunctions
{
    /**
     * Every resolver function in schema public. A new one is added here and in data-model.md, and gets
     * its own migration: a past migration never reads this list, so adding a name cannot change it.
     */
    public const array NAMES = [
        'resolve_api_token',
        'resolve_user_orgs',
        'resolve_invitation',
        'resolve_sso_provider',
        'resolve_jira_connection',
        'resolve_laptop_package',
    ];
}
