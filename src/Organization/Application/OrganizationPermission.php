<?php

declare(strict_types=1);

namespace App\Organization\Application;

/**
 * The voter attributes for organizations (ADR-0013). OrganizationMemberVoter decides them.
 */
final class OrganizationPermission
{
    /** Open the picker. Its subject is null: the list is always the logged-in user's own organizations. */
    public const string CHOOSE = 'ORGANIZATION_CHOOSE';

    /** Work in an organization: choose it in the picker. Its subject is the OrganizationId. */
    public const string ENTER = 'ORGANIZATION_ENTER';
}
