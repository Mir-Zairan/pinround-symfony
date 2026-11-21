<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Doctrine\Migration;

/**
 * The SECURITY DEFINER functions, the only row-level security exceptions (ADR-0005, data-model.md ›
 * Lookups before the tenant is known): the lookups that run before the tenant is known, the
 * ticket search, which must reach the GIN indexes the planner skips under a policy (ADR-0022), and
 * the organizations or people a scheduled job with no tenant must visit, such as the release of held
 * notifications, the email digest and the expiry warning of API tokens. Each
 * is owned by pinround_resolver, returns ids only, and only pinround_app may execute it.
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
        'search_ticket_ids',
        'organizations_with_due_notifications',
        'digest_recipients',
        'organizations_with_expiring_api_tokens',
        'resolve_oauth_authorization_code',
    ];
}
