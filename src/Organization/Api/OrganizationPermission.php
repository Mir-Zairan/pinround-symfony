<?php

declare(strict_types=1);

namespace App\Organization\Api;

/**
 * The voter attributes for organizations (ADR-0013), in the Api so the UI of any module may ask them.
 * OrganizationMemberVoter decides CHOOSE and ENTER, OrganizationVoter decides VIEW, ADMIN and INVITE.
 */
final class OrganizationPermission
{
    /** Open the picker. Its subject is null: the list is always the logged-in user's own organizations. */
    public const string CHOOSE = 'ORGANIZATION_CHOOSE';

    /** Work in an organization: choose it in the picker. Its subject is the OrganizationId. */
    public const string ENTER = 'ORGANIZATION_ENTER';

    /** See the organization the request works in. Its subject is the OrganizationId. */
    public const string VIEW = 'ORG_VIEW';

    /**
     * Be an Admin of the organization. Its subject is the OrganizationId. It checks the role only, not how the person
     * logged in, so admin pages and admin actions ask AdminPermission::AREA instead.
     */
    public const string ADMIN = 'ORG_ADMIN';

    /**
     * Invite people to the organization. Its subject is the OrganizationId. Only an Admin may; like ADMIN it checks the
     * role only, so the admin pages that invite also ask AdminPermission::AREA.
     */
    public const string INVITE = 'ORG_INVITE';
}
