<?php

declare(strict_types=1);

namespace App\Organization\Api;

/**
 * The voter attributes for the admin pages (ADR-0013), in the Api so the UI of any module may ask them.
 * AdminAreaVoter decides AREA and AiSettingsVoter decides AI.
 */
final class AdminPermission
{
    /**
     * Change the organization's AI settings (Admin › AI): an Admin there, with the login AREA needs. The tab asks AREA
     * too, and its provider key action is marked RequiresRecentAuth. Its subject is the OrganizationId of the request.
     */
    public const string AI = 'ADMIN_AI';

    /**
     * Open the admin pages of an organization: an Admin there, logged in with their password in this session and with a
     * confirmed two-step login. Its subject is the OrganizationId of the request.
     */
    public const string AREA = 'ADMIN_AREA';
}
