<?php

declare(strict_types=1);

use App\Organization\Api\AdminPermission;
use App\Organization\Api\OrganizationPermission;
use App\Organization\Api\TeamPermission;

/*
 * The rows VoterMatrixTest decides through the real access decision manager. Add rows here, not test code.
 *
 * source:    text of docs/00-product/personas-and-roles.md the row comes from (a Permission matrix action, a heading
 *            or a sentence), copied exactly; VoterMatrixTest fails when the doc no longer has it.
 * actor:     a person of VoterMatrixTest::PEOPLE, deciding in their own organization.
 * subject:   an organization (Acme Foods, Beta Bakery) or a team (Shop, Mobile, Support of Acme Foods; Checkout of Beta
 *            Bakery).
 * expected:  granted or denied.
 * skipAdminMfa: optional, true to decide with APP_HACKATHON_SKIP_ADMIN_MFA on (ADR-0012, Hackathon exception); every
 *            person's token is a password session without a confirmed two-step login, which nobody has before P01.T6.
 *
 * A row proves a team-role rule only when its actor has no org role that grants the same: Lena and Ben are Admins and
 * Raj is a Manager, so their TEAM_VIEW rows cite the org-role text; Noor (Lead) and Mia (Member, Viewer) carry the
 * team-role rules.
 */

$isAUser = 'Every person in an organization is a **user** of it.';
$deniedUnlessARuleAllows = 'Everything is denied unless a rule allows it';
$adminPages = 'Admin pages (all)';
$teamRoles = 'Team roles (per team)';
$leadRights = 'Everything a member can do, plus: plan, give tickets to people';
$memberRights = 'Report, comment, ask questions, work on tickets given to them';
$viewerRights = 'Read and comment. Cannot move tickets or be given tickets.';
$teamsAtAGlance = 'Teams at a glance';
$managerSeesAllTeams = '"Teams at a glance" for all teams';
$managerAssigns = 'Give it to… (assign)';
$adminWithoutTeamRole = 'An admin with no team role may see every ticket in the org';

return [
    ['source' => $isAUser, 'attribute' => OrganizationPermission::VIEW, 'actor' => 'Lena', 'subject' => 'Acme Foods', 'expected' => 'granted'],
    ['source' => $isAUser, 'attribute' => OrganizationPermission::VIEW, 'actor' => 'Omar', 'subject' => 'Acme Foods', 'expected' => 'granted'],
    ['source' => $isAUser, 'attribute' => OrganizationPermission::VIEW, 'actor' => 'Mia', 'subject' => 'Acme Foods', 'expected' => 'granted'],
    ['source' => $isAUser, 'attribute' => OrganizationPermission::VIEW, 'actor' => 'Raj', 'subject' => 'Acme Foods', 'expected' => 'granted'],
    ['source' => $isAUser, 'attribute' => OrganizationPermission::VIEW, 'actor' => 'Sara', 'subject' => 'Acme Foods', 'expected' => 'granted'],
    ['source' => $isAUser, 'attribute' => OrganizationPermission::VIEW, 'actor' => 'Noor', 'subject' => 'Acme Foods', 'expected' => 'granted'],
    ['source' => $isAUser, 'attribute' => OrganizationPermission::VIEW, 'actor' => 'Ben', 'subject' => 'Beta Bakery', 'expected' => 'granted'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => OrganizationPermission::VIEW, 'actor' => 'Lena', 'subject' => 'Beta Bakery', 'expected' => 'denied'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => OrganizationPermission::VIEW, 'actor' => 'Omar', 'subject' => 'Beta Bakery', 'expected' => 'denied'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => OrganizationPermission::VIEW, 'actor' => 'Mia', 'subject' => 'Beta Bakery', 'expected' => 'denied'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => OrganizationPermission::VIEW, 'actor' => 'Raj', 'subject' => 'Beta Bakery', 'expected' => 'denied'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => OrganizationPermission::VIEW, 'actor' => 'Sara', 'subject' => 'Beta Bakery', 'expected' => 'denied'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => OrganizationPermission::VIEW, 'actor' => 'Noor', 'subject' => 'Beta Bakery', 'expected' => 'denied'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => OrganizationPermission::VIEW, 'actor' => 'Ben', 'subject' => 'Acme Foods', 'expected' => 'denied'],

    ['source' => $adminPages, 'attribute' => OrganizationPermission::ADMIN, 'actor' => 'Lena', 'subject' => 'Acme Foods', 'expected' => 'granted'],
    ['source' => $adminPages, 'attribute' => OrganizationPermission::ADMIN, 'actor' => 'Sara', 'subject' => 'Acme Foods', 'expected' => 'granted'],
    ['source' => $adminPages, 'attribute' => OrganizationPermission::ADMIN, 'actor' => 'Omar', 'subject' => 'Acme Foods', 'expected' => 'denied'],
    ['source' => $adminPages, 'attribute' => OrganizationPermission::ADMIN, 'actor' => 'Mia', 'subject' => 'Acme Foods', 'expected' => 'denied'],
    ['source' => $adminPages, 'attribute' => OrganizationPermission::ADMIN, 'actor' => 'Raj', 'subject' => 'Acme Foods', 'expected' => 'denied'],
    ['source' => $adminPages, 'attribute' => OrganizationPermission::ADMIN, 'actor' => 'Noor', 'subject' => 'Acme Foods', 'expected' => 'denied'],
    ['source' => $adminPages, 'attribute' => OrganizationPermission::ADMIN, 'actor' => 'Ben', 'subject' => 'Beta Bakery', 'expected' => 'granted'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => OrganizationPermission::ADMIN, 'actor' => 'Lena', 'subject' => 'Beta Bakery', 'expected' => 'denied'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => OrganizationPermission::ADMIN, 'actor' => 'Sara', 'subject' => 'Beta Bakery', 'expected' => 'denied'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => OrganizationPermission::ADMIN, 'actor' => 'Ben', 'subject' => 'Acme Foods', 'expected' => 'denied'],

    ['source' => $adminPages, 'attribute' => AdminPermission::AREA, 'actor' => 'Lena', 'subject' => 'Acme Foods', 'expected' => 'granted', 'skipAdminMfa' => true],
    ['source' => $adminPages, 'attribute' => AdminPermission::AREA, 'actor' => 'Sara', 'subject' => 'Acme Foods', 'expected' => 'granted', 'skipAdminMfa' => true],
    ['source' => $adminPages, 'attribute' => AdminPermission::AREA, 'actor' => 'Ben', 'subject' => 'Beta Bakery', 'expected' => 'granted', 'skipAdminMfa' => true],
    ['source' => $adminPages, 'attribute' => AdminPermission::AREA, 'actor' => 'Omar', 'subject' => 'Acme Foods', 'expected' => 'denied', 'skipAdminMfa' => true],
    ['source' => $adminPages, 'attribute' => AdminPermission::AREA, 'actor' => 'Mia', 'subject' => 'Acme Foods', 'expected' => 'denied', 'skipAdminMfa' => true],
    ['source' => $adminPages, 'attribute' => AdminPermission::AREA, 'actor' => 'Raj', 'subject' => 'Acme Foods', 'expected' => 'denied', 'skipAdminMfa' => true],
    ['source' => $adminPages, 'attribute' => AdminPermission::AREA, 'actor' => 'Noor', 'subject' => 'Acme Foods', 'expected' => 'denied', 'skipAdminMfa' => true],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => AdminPermission::AREA, 'actor' => 'Lena', 'subject' => 'Beta Bakery', 'expected' => 'denied', 'skipAdminMfa' => true],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => AdminPermission::AREA, 'actor' => 'Ben', 'subject' => 'Acme Foods', 'expected' => 'denied', 'skipAdminMfa' => true],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => AdminPermission::AREA, 'actor' => 'Lena', 'subject' => 'Acme Foods', 'expected' => 'denied'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => AdminPermission::AREA, 'actor' => 'Sara', 'subject' => 'Acme Foods', 'expected' => 'denied'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => AdminPermission::AREA, 'actor' => 'Ben', 'subject' => 'Beta Bakery', 'expected' => 'denied'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => AdminPermission::AREA, 'actor' => 'Raj', 'subject' => 'Acme Foods', 'expected' => 'denied'],

    ['source' => $teamsAtAGlance, 'attribute' => TeamPermission::VIEW, 'actor' => 'Lena', 'subject' => 'Shop', 'expected' => 'granted'],
    ['source' => $teamsAtAGlance, 'attribute' => TeamPermission::VIEW, 'actor' => 'Lena', 'subject' => 'Mobile', 'expected' => 'granted'],
    ['source' => $leadRights, 'attribute' => TeamPermission::VIEW, 'actor' => 'Noor', 'subject' => 'Support', 'expected' => 'granted'],
    ['source' => $teamRoles, 'attribute' => TeamPermission::VIEW, 'actor' => 'Noor', 'subject' => 'Shop', 'expected' => 'denied'],
    ['source' => $teamRoles, 'attribute' => TeamPermission::VIEW, 'actor' => 'Noor', 'subject' => 'Mobile', 'expected' => 'denied'],
    ['source' => $memberRights, 'attribute' => TeamPermission::VIEW, 'actor' => 'Omar', 'subject' => 'Shop', 'expected' => 'granted'],
    ['source' => $memberRights, 'attribute' => TeamPermission::VIEW, 'actor' => 'Omar', 'subject' => 'Mobile', 'expected' => 'granted'],
    ['source' => $memberRights, 'attribute' => TeamPermission::VIEW, 'actor' => 'Mia', 'subject' => 'Shop', 'expected' => 'granted'],
    ['source' => $teamRoles, 'attribute' => TeamPermission::VIEW, 'actor' => 'Mia', 'subject' => 'Mobile', 'expected' => 'denied'],
    ['source' => $viewerRights, 'attribute' => TeamPermission::VIEW, 'actor' => 'Mia', 'subject' => 'Support', 'expected' => 'granted'],
    ['source' => $teamRoles, 'attribute' => TeamPermission::VIEW, 'actor' => 'Omar', 'subject' => 'Support', 'expected' => 'denied'],
    ['source' => $managerSeesAllTeams, 'attribute' => TeamPermission::VIEW, 'actor' => 'Raj', 'subject' => 'Shop', 'expected' => 'granted'],
    ['source' => $managerSeesAllTeams, 'attribute' => TeamPermission::VIEW, 'actor' => 'Raj', 'subject' => 'Support', 'expected' => 'granted'],
    ['source' => $managerSeesAllTeams, 'attribute' => TeamPermission::VIEW, 'actor' => 'Raj', 'subject' => 'Mobile', 'expected' => 'granted'],
    ['source' => $teamsAtAGlance, 'attribute' => TeamPermission::VIEW, 'actor' => 'Sara', 'subject' => 'Shop', 'expected' => 'granted'],
    ['source' => $teamsAtAGlance, 'attribute' => TeamPermission::VIEW, 'actor' => 'Sara', 'subject' => 'Mobile', 'expected' => 'granted'],
    ['source' => $teamsAtAGlance, 'attribute' => TeamPermission::VIEW, 'actor' => 'Ben', 'subject' => 'Checkout', 'expected' => 'granted'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => TeamPermission::VIEW, 'actor' => 'Lena', 'subject' => 'Checkout', 'expected' => 'denied'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => TeamPermission::VIEW, 'actor' => 'Omar', 'subject' => 'Checkout', 'expected' => 'denied'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => TeamPermission::VIEW, 'actor' => 'Mia', 'subject' => 'Checkout', 'expected' => 'denied'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => TeamPermission::VIEW, 'actor' => 'Raj', 'subject' => 'Checkout', 'expected' => 'denied'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => TeamPermission::VIEW, 'actor' => 'Sara', 'subject' => 'Checkout', 'expected' => 'denied'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => TeamPermission::VIEW, 'actor' => 'Noor', 'subject' => 'Checkout', 'expected' => 'denied'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => TeamPermission::VIEW, 'actor' => 'Ben', 'subject' => 'Shop', 'expected' => 'denied'],

    ['source' => $leadRights, 'attribute' => TeamPermission::LEAD, 'actor' => 'Lena', 'subject' => 'Shop', 'expected' => 'granted'],
    ['source' => $leadRights, 'attribute' => TeamPermission::LEAD, 'actor' => 'Lena', 'subject' => 'Mobile', 'expected' => 'granted'],
    ['source' => $teamRoles, 'attribute' => TeamPermission::LEAD, 'actor' => 'Omar', 'subject' => 'Shop', 'expected' => 'denied'],
    ['source' => $teamRoles, 'attribute' => TeamPermission::LEAD, 'actor' => 'Omar', 'subject' => 'Mobile', 'expected' => 'denied'],
    ['source' => $teamRoles, 'attribute' => TeamPermission::LEAD, 'actor' => 'Mia', 'subject' => 'Shop', 'expected' => 'denied'],
    ['source' => $teamRoles, 'attribute' => TeamPermission::LEAD, 'actor' => 'Mia', 'subject' => 'Mobile', 'expected' => 'denied'],
    ['source' => $teamRoles, 'attribute' => TeamPermission::LEAD, 'actor' => 'Mia', 'subject' => 'Support', 'expected' => 'denied'],
    ['source' => $leadRights, 'attribute' => TeamPermission::LEAD, 'actor' => 'Noor', 'subject' => 'Support', 'expected' => 'granted'],
    ['source' => $managerAssigns, 'attribute' => TeamPermission::LEAD, 'actor' => 'Raj', 'subject' => 'Shop', 'expected' => 'denied'],
    ['source' => $managerAssigns, 'attribute' => TeamPermission::LEAD, 'actor' => 'Raj', 'subject' => 'Mobile', 'expected' => 'denied'],
    ['source' => $adminWithoutTeamRole, 'attribute' => TeamPermission::LEAD, 'actor' => 'Sara', 'subject' => 'Shop', 'expected' => 'denied'],
    ['source' => $adminWithoutTeamRole, 'attribute' => TeamPermission::LEAD, 'actor' => 'Sara', 'subject' => 'Mobile', 'expected' => 'denied'],
    ['source' => $teamRoles, 'attribute' => TeamPermission::LEAD, 'actor' => 'Noor', 'subject' => 'Shop', 'expected' => 'denied'],
    ['source' => $teamRoles, 'attribute' => TeamPermission::LEAD, 'actor' => 'Noor', 'subject' => 'Mobile', 'expected' => 'denied'],
    ['source' => $leadRights, 'attribute' => TeamPermission::LEAD, 'actor' => 'Ben', 'subject' => 'Checkout', 'expected' => 'granted'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => TeamPermission::LEAD, 'actor' => 'Lena', 'subject' => 'Checkout', 'expected' => 'denied'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => TeamPermission::LEAD, 'actor' => 'Sara', 'subject' => 'Checkout', 'expected' => 'denied'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => TeamPermission::LEAD, 'actor' => 'Ben', 'subject' => 'Shop', 'expected' => 'denied'],
];
