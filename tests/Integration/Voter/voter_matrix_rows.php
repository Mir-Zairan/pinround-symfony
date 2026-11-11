<?php

declare(strict_types=1);

use App\Organization\Api\AdminPermission;
use App\Organization\Api\OrganizationPermission;
use App\Organization\Api\TeamPermission;
use App\Ticketing\Api\ProjectPermission;
use App\Ticketing\Api\TicketPermission;
use App\Workflow\Api\WorkflowPermission;

/*
 * The rows VoterMatrixTest decides through the real access decision manager. Add rows here, not test code.
 *
 * source:    text of docs/00-product/personas-and-roles.md the row comes from (a Permission matrix action, a heading
 *            or a sentence), copied exactly; VoterMatrixTest fails when the doc no longer has it.
 * actor:     a person of VoterMatrixTest::PEOPLE, deciding in their own organization.
 * subject:   an organization (Acme Foods, Beta Bakery), a team (Shop, Mobile, Support of Acme Foods; Checkout of Beta
 *            Bakery) or an open invitation link to Acme Foods ("Invitation of Omar" for Omar's email; "Invitation of a
 *            new email" for an email nobody has an account with). Nobody-logged-in cases are in InvitationVoterTest.
 *            Or a team-only ticket of Acme Foods: "Team-only Shop ticket", or "Team-only ticket with no team"; both
 *            reported by someone who is no actor here.
 * expected:  granted or denied.
 * twoStepProof: optional; decides for a person whose two-step login is on. true: they typed a code in this session 30
 *            minutes ago, within the 1 hour admin pages allow; an int: the code is that many seconds old; 'noCode': no
 *            code in this session. Without the option the person has no two-step login and no session. Every person's
 *            token is a password session.
 *
 * A row proves a team-role rule only when its actor has no org role that grants the same: Lena and Ben are Admins and
 * Raj is a Manager, so their TEAM_VIEW rows cite the org-role text; Noor (Lead) and Mia (Member, Viewer) carry the
 * team-role rules.
 *
 * TICKET_CREATE is the team ticket form's, which needs a team role on the team (ADR-0038), so its rows cite the
 * team-role text and the matrix row "New ticket form", not "Report a problem", which lets every role report through
 * the intake.
 *
 * Acme Foods' settings row holds only its triager, Noor, and Beta Bakery has none, so both have the default step policy,
 * extend: the STEP_CHANGE_TEAM and RULES_CHANGE_TEAM rows hold under extend only. WorkflowVoterTest has the locked and
 * team cases.
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
$leadLinksProjects = 'link projects.';
$leadLinksAndUnlinksProjects = '| Link / unlink Jira projects to the team | — | — | ✅ | — | — | — |';
$leadChangesTeamSteps = '| Add / remove team steps | — | — | ⚠️ policy extend or team | — | — | — |';
$leadChangesTeamRules = '| Change team rules | — | — | ⚠️ policy team | — | — | — |';
$adminWithTeamRole = 'An admin who is also a member or lead of the team gets that team role\'s rights too.';
$newTicketFormNeedsTeamRole = '| New ticket form (create ticket on the team\'s board) ⁷ | ⚠️ only with a role on the team | ⚠️ only with a role on the team | ✅ | ✅ | ✅ | — |';
$adminMayNotEditTickets = 'may not move tickets or edit ticket fields';
$teamOnlyTickets = 'visible to members of the ticket\'s team, to admins, and to the people working on that ticket';
$triagerOfANoTeamTicket = 'and to the triager while it has no team';
$managersOutsideTheTeam = 'Not to managers outside the team.';
$invitationLinks = 'an invitation is accepted only by the account with the invited email, logged in, or by a logged-out person when nobody has an account with that email yet.';

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

    ['source' => $adminPages, 'attribute' => OrganizationPermission::INVITE, 'actor' => 'Lena', 'subject' => 'Acme Foods', 'expected' => 'granted'],
    ['source' => $adminPages, 'attribute' => OrganizationPermission::INVITE, 'actor' => 'Sara', 'subject' => 'Acme Foods', 'expected' => 'granted'],
    ['source' => $adminPages, 'attribute' => OrganizationPermission::INVITE, 'actor' => 'Omar', 'subject' => 'Acme Foods', 'expected' => 'denied'],
    ['source' => $adminPages, 'attribute' => OrganizationPermission::INVITE, 'actor' => 'Mia', 'subject' => 'Acme Foods', 'expected' => 'denied'],
    ['source' => $adminPages, 'attribute' => OrganizationPermission::INVITE, 'actor' => 'Raj', 'subject' => 'Acme Foods', 'expected' => 'denied'],
    ['source' => $adminPages, 'attribute' => OrganizationPermission::INVITE, 'actor' => 'Noor', 'subject' => 'Acme Foods', 'expected' => 'denied'],
    ['source' => $adminPages, 'attribute' => OrganizationPermission::INVITE, 'actor' => 'Ben', 'subject' => 'Beta Bakery', 'expected' => 'granted'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => OrganizationPermission::INVITE, 'actor' => 'Lena', 'subject' => 'Beta Bakery', 'expected' => 'denied'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => OrganizationPermission::INVITE, 'actor' => 'Sara', 'subject' => 'Beta Bakery', 'expected' => 'denied'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => OrganizationPermission::INVITE, 'actor' => 'Ben', 'subject' => 'Acme Foods', 'expected' => 'denied'],

    ['source' => $adminPages, 'attribute' => AdminPermission::AREA, 'actor' => 'Lena', 'subject' => 'Acme Foods', 'expected' => 'granted', 'twoStepProof' => true],
    ['source' => $adminPages, 'attribute' => AdminPermission::AREA, 'actor' => 'Sara', 'subject' => 'Acme Foods', 'expected' => 'granted', 'twoStepProof' => true],
    ['source' => $adminPages, 'attribute' => AdminPermission::AREA, 'actor' => 'Ben', 'subject' => 'Beta Bakery', 'expected' => 'granted', 'twoStepProof' => true],
    ['source' => $adminPages, 'attribute' => AdminPermission::AREA, 'actor' => 'Omar', 'subject' => 'Acme Foods', 'expected' => 'denied', 'twoStepProof' => true],
    ['source' => $adminPages, 'attribute' => AdminPermission::AREA, 'actor' => 'Mia', 'subject' => 'Acme Foods', 'expected' => 'denied', 'twoStepProof' => true],
    ['source' => $adminPages, 'attribute' => AdminPermission::AREA, 'actor' => 'Raj', 'subject' => 'Acme Foods', 'expected' => 'denied', 'twoStepProof' => true],
    ['source' => $adminPages, 'attribute' => AdminPermission::AREA, 'actor' => 'Noor', 'subject' => 'Acme Foods', 'expected' => 'denied', 'twoStepProof' => true],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => AdminPermission::AREA, 'actor' => 'Lena', 'subject' => 'Beta Bakery', 'expected' => 'denied', 'twoStepProof' => true],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => AdminPermission::AREA, 'actor' => 'Ben', 'subject' => 'Acme Foods', 'expected' => 'denied', 'twoStepProof' => true],
    ['source' => $adminPages, 'attribute' => AdminPermission::AREA, 'actor' => 'Sara', 'subject' => 'Acme Foods', 'expected' => 'granted', 'twoStepProof' => 3600],
    ['source' => $adminPages, 'attribute' => AdminPermission::AREA, 'actor' => 'Lena', 'subject' => 'Acme Foods', 'expected' => 'denied', 'twoStepProof' => 3601],
    ['source' => $adminPages, 'attribute' => AdminPermission::AREA, 'actor' => 'Sara', 'subject' => 'Acme Foods', 'expected' => 'denied', 'twoStepProof' => 3601],
    ['source' => $adminPages, 'attribute' => AdminPermission::AREA, 'actor' => 'Lena', 'subject' => 'Acme Foods', 'expected' => 'denied', 'twoStepProof' => 'noCode'],
    ['source' => $adminPages, 'attribute' => AdminPermission::AREA, 'actor' => 'Sara', 'subject' => 'Acme Foods', 'expected' => 'denied', 'twoStepProof' => 'noCode'],
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
    ['source' => $invitationLinks, 'attribute' => OrganizationPermission::ACCEPT_INVITATION, 'actor' => 'Omar', 'subject' => 'Invitation of Omar', 'expected' => 'granted'],
    ['source' => $invitationLinks, 'attribute' => OrganizationPermission::ACCEPT_INVITATION, 'actor' => 'Mia', 'subject' => 'Invitation of Omar', 'expected' => 'denied'],
    ['source' => $invitationLinks, 'attribute' => OrganizationPermission::ACCEPT_INVITATION, 'actor' => 'Sara', 'subject' => 'Invitation of Omar', 'expected' => 'denied'],
    ['source' => $invitationLinks, 'attribute' => OrganizationPermission::ACCEPT_INVITATION, 'actor' => 'Ben', 'subject' => 'Invitation of Omar', 'expected' => 'denied'],
    ['source' => $invitationLinks, 'attribute' => OrganizationPermission::ACCEPT_INVITATION, 'actor' => 'Lena', 'subject' => 'Invitation of a new email', 'expected' => 'denied'],
    ['source' => $invitationLinks, 'attribute' => OrganizationPermission::ACCEPT_INVITATION, 'actor' => 'Sara', 'subject' => 'Invitation of a new email', 'expected' => 'denied'],

    ['source' => $leadLinksProjects, 'attribute' => ProjectPermission::CREATE, 'actor' => 'Noor', 'subject' => 'Support', 'expected' => 'granted'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => ProjectPermission::CREATE, 'actor' => 'Noor', 'subject' => 'Shop', 'expected' => 'denied'],
    ['source' => $adminPages, 'attribute' => ProjectPermission::CREATE, 'actor' => 'Sara', 'subject' => 'Shop', 'expected' => 'granted'],
    ['source' => $adminPages, 'attribute' => ProjectPermission::CREATE, 'actor' => 'Sara', 'subject' => 'Support', 'expected' => 'granted'],
    ['source' => $adminPages, 'attribute' => ProjectPermission::CREATE, 'actor' => 'Lena', 'subject' => 'Support', 'expected' => 'granted'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => ProjectPermission::CREATE, 'actor' => 'Omar', 'subject' => 'Shop', 'expected' => 'denied'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => ProjectPermission::CREATE, 'actor' => 'Mia', 'subject' => 'Shop', 'expected' => 'denied'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => ProjectPermission::CREATE, 'actor' => 'Raj', 'subject' => 'Shop', 'expected' => 'denied'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => ProjectPermission::CREATE, 'actor' => 'Ben', 'subject' => 'Shop', 'expected' => 'denied'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => ProjectPermission::CREATE, 'actor' => 'Lena', 'subject' => 'Checkout', 'expected' => 'denied'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => ProjectPermission::CREATE, 'actor' => 'Sara', 'subject' => 'Checkout', 'expected' => 'denied'],

    ['source' => $leadLinksAndUnlinksProjects, 'attribute' => ProjectPermission::UNLINK, 'actor' => 'Lena', 'subject' => 'Shop', 'expected' => 'granted'],
    ['source' => $leadLinksAndUnlinksProjects, 'attribute' => ProjectPermission::UNLINK, 'actor' => 'Noor', 'subject' => 'Support', 'expected' => 'granted'],
    ['source' => $leadLinksAndUnlinksProjects, 'attribute' => ProjectPermission::UNLINK, 'actor' => 'Sara', 'subject' => 'Shop', 'expected' => 'denied'],
    ['source' => $leadLinksAndUnlinksProjects, 'attribute' => ProjectPermission::UNLINK, 'actor' => 'Omar', 'subject' => 'Shop', 'expected' => 'denied'],
    ['source' => $leadLinksAndUnlinksProjects, 'attribute' => ProjectPermission::UNLINK, 'actor' => 'Mia', 'subject' => 'Shop', 'expected' => 'denied'],
    ['source' => $leadLinksAndUnlinksProjects, 'attribute' => ProjectPermission::UNLINK, 'actor' => 'Mia', 'subject' => 'Support', 'expected' => 'denied'],
    ['source' => $leadLinksAndUnlinksProjects, 'attribute' => ProjectPermission::UNLINK, 'actor' => 'Raj', 'subject' => 'Shop', 'expected' => 'denied'],
    ['source' => $leadLinksAndUnlinksProjects, 'attribute' => ProjectPermission::UNLINK, 'actor' => 'Noor', 'subject' => 'Shop', 'expected' => 'denied'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => ProjectPermission::UNLINK, 'actor' => 'Ben', 'subject' => 'Shop', 'expected' => 'denied'],

    ['source' => $leadLinksAndUnlinksProjects, 'attribute' => ProjectPermission::LINK, 'actor' => 'Lena', 'subject' => 'Shop', 'expected' => 'granted'],
    ['source' => $adminPages, 'attribute' => ProjectPermission::LINK, 'actor' => 'Sara', 'subject' => 'Shop', 'expected' => 'granted'],
    ['source' => $leadLinksAndUnlinksProjects, 'attribute' => ProjectPermission::LINK, 'actor' => 'Omar', 'subject' => 'Shop', 'expected' => 'denied'],
    ['source' => $leadLinksAndUnlinksProjects, 'attribute' => ProjectPermission::LINK, 'actor' => 'Mia', 'subject' => 'Shop', 'expected' => 'denied'],
    ['source' => $leadLinksAndUnlinksProjects, 'attribute' => ProjectPermission::LINK, 'actor' => 'Mia', 'subject' => 'Support', 'expected' => 'denied'],
    ['source' => $leadLinksAndUnlinksProjects, 'attribute' => ProjectPermission::LINK, 'actor' => 'Raj', 'subject' => 'Shop', 'expected' => 'denied'],
    ['source' => $leadLinksAndUnlinksProjects, 'attribute' => ProjectPermission::LINK, 'actor' => 'Noor', 'subject' => 'Shop', 'expected' => 'denied'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => ProjectPermission::LINK, 'actor' => 'Ben', 'subject' => 'Shop', 'expected' => 'denied'],

    ['source' => $leadChangesTeamSteps, 'attribute' => WorkflowPermission::STEP_CHANGE_TEAM, 'actor' => 'Noor', 'subject' => 'Support', 'expected' => 'granted'],
    ['source' => $leadChangesTeamSteps, 'attribute' => WorkflowPermission::STEP_CHANGE_TEAM, 'actor' => 'Lena', 'subject' => 'Shop', 'expected' => 'granted'],
    ['source' => $leadChangesTeamSteps, 'attribute' => WorkflowPermission::STEP_CHANGE_TEAM, 'actor' => 'Ben', 'subject' => 'Checkout', 'expected' => 'granted'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => WorkflowPermission::STEP_CHANGE_TEAM, 'actor' => 'Noor', 'subject' => 'Shop', 'expected' => 'denied'],
    ['source' => $leadChangesTeamSteps, 'attribute' => WorkflowPermission::STEP_CHANGE_TEAM, 'actor' => 'Omar', 'subject' => 'Shop', 'expected' => 'denied'],
    ['source' => $leadChangesTeamSteps, 'attribute' => WorkflowPermission::STEP_CHANGE_TEAM, 'actor' => 'Mia', 'subject' => 'Shop', 'expected' => 'denied'],
    ['source' => $leadChangesTeamSteps, 'attribute' => WorkflowPermission::STEP_CHANGE_TEAM, 'actor' => 'Mia', 'subject' => 'Support', 'expected' => 'denied'],
    ['source' => $leadChangesTeamSteps, 'attribute' => WorkflowPermission::STEP_CHANGE_TEAM, 'actor' => 'Raj', 'subject' => 'Shop', 'expected' => 'denied'],
    ['source' => $leadChangesTeamSteps, 'attribute' => WorkflowPermission::STEP_CHANGE_TEAM, 'actor' => 'Sara', 'subject' => 'Shop', 'expected' => 'denied'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => WorkflowPermission::STEP_CHANGE_TEAM, 'actor' => 'Ben', 'subject' => 'Shop', 'expected' => 'denied'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => WorkflowPermission::STEP_CHANGE_TEAM, 'actor' => 'Lena', 'subject' => 'Checkout', 'expected' => 'denied'],

    ['source' => $leadChangesTeamRules, 'attribute' => WorkflowPermission::RULES_CHANGE_TEAM, 'actor' => 'Noor', 'subject' => 'Support', 'expected' => 'denied'],
    ['source' => $leadChangesTeamRules, 'attribute' => WorkflowPermission::RULES_CHANGE_TEAM, 'actor' => 'Lena', 'subject' => 'Shop', 'expected' => 'denied'],
    ['source' => $leadChangesTeamRules, 'attribute' => WorkflowPermission::RULES_CHANGE_TEAM, 'actor' => 'Ben', 'subject' => 'Checkout', 'expected' => 'denied'],
    ['source' => $leadChangesTeamRules, 'attribute' => WorkflowPermission::RULES_CHANGE_TEAM, 'actor' => 'Omar', 'subject' => 'Shop', 'expected' => 'denied'],
    ['source' => $leadChangesTeamRules, 'attribute' => WorkflowPermission::RULES_CHANGE_TEAM, 'actor' => 'Mia', 'subject' => 'Shop', 'expected' => 'denied'],
    ['source' => $leadChangesTeamRules, 'attribute' => WorkflowPermission::RULES_CHANGE_TEAM, 'actor' => 'Raj', 'subject' => 'Shop', 'expected' => 'denied'],
    ['source' => $leadChangesTeamRules, 'attribute' => WorkflowPermission::RULES_CHANGE_TEAM, 'actor' => 'Sara', 'subject' => 'Shop', 'expected' => 'denied'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => WorkflowPermission::RULES_CHANGE_TEAM, 'actor' => 'Ben', 'subject' => 'Shop', 'expected' => 'denied'],

    ['source' => $memberRights, 'attribute' => TicketPermission::CREATE, 'actor' => 'Omar', 'subject' => 'Shop', 'expected' => 'granted'],
    ['source' => $leadRights, 'attribute' => TicketPermission::CREATE, 'actor' => 'Noor', 'subject' => 'Support', 'expected' => 'granted'],
    ['source' => $teamRoles, 'attribute' => TicketPermission::CREATE, 'actor' => 'Mia', 'subject' => 'Support', 'expected' => 'granted'],
    ['source' => $teamRoles, 'attribute' => TicketPermission::CREATE, 'actor' => 'Raj', 'subject' => 'Shop', 'expected' => 'granted'],
    ['source' => $adminWithTeamRole, 'attribute' => TicketPermission::CREATE, 'actor' => 'Lena', 'subject' => 'Shop', 'expected' => 'granted'],
    ['source' => $adminWithTeamRole, 'attribute' => TicketPermission::CREATE, 'actor' => 'Ben', 'subject' => 'Checkout', 'expected' => 'granted'],
    ['source' => $newTicketFormNeedsTeamRole, 'attribute' => TicketPermission::CREATE, 'actor' => 'Raj', 'subject' => 'Mobile', 'expected' => 'denied'],
    ['source' => $newTicketFormNeedsTeamRole, 'attribute' => TicketPermission::CREATE, 'actor' => 'Sara', 'subject' => 'Shop', 'expected' => 'denied'],
    ['source' => $newTicketFormNeedsTeamRole, 'attribute' => TicketPermission::CREATE, 'actor' => 'Sara', 'subject' => 'Support', 'expected' => 'denied'],
    ['source' => $newTicketFormNeedsTeamRole, 'attribute' => TicketPermission::CREATE, 'actor' => 'Noor', 'subject' => 'Shop', 'expected' => 'denied'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => TicketPermission::CREATE, 'actor' => 'Ben', 'subject' => 'Shop', 'expected' => 'denied'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => TicketPermission::CREATE, 'actor' => 'Lena', 'subject' => 'Checkout', 'expected' => 'denied'],

    ['source' => $teamOnlyTickets, 'attribute' => TicketPermission::VIEW, 'actor' => 'Omar', 'subject' => 'Team-only Shop ticket', 'expected' => 'granted'],
    ['source' => $teamOnlyTickets, 'attribute' => TicketPermission::VIEW, 'actor' => 'Raj', 'subject' => 'Team-only Shop ticket', 'expected' => 'granted'],
    ['source' => $adminWithoutTeamRole, 'attribute' => TicketPermission::VIEW, 'actor' => 'Sara', 'subject' => 'Team-only Shop ticket', 'expected' => 'granted'],
    ['source' => $triagerOfANoTeamTicket, 'attribute' => TicketPermission::VIEW, 'actor' => 'Noor', 'subject' => 'Team-only Shop ticket', 'expected' => 'denied'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => TicketPermission::VIEW, 'actor' => 'Ben', 'subject' => 'Team-only Shop ticket', 'expected' => 'denied'],
    ['source' => $triagerOfANoTeamTicket, 'attribute' => TicketPermission::VIEW, 'actor' => 'Noor', 'subject' => 'Team-only ticket with no team', 'expected' => 'granted'],
    ['source' => $triagerOfANoTeamTicket, 'attribute' => TicketPermission::VIEW, 'actor' => 'Omar', 'subject' => 'Team-only ticket with no team', 'expected' => 'denied'],
    ['source' => $managersOutsideTheTeam, 'attribute' => TicketPermission::VIEW, 'actor' => 'Raj', 'subject' => 'Team-only ticket with no team', 'expected' => 'denied'],

    ['source' => $memberRights, 'attribute' => TicketPermission::EDIT, 'actor' => 'Omar', 'subject' => 'Team-only Shop ticket', 'expected' => 'granted'],
    ['source' => $leadRights, 'attribute' => TicketPermission::EDIT, 'actor' => 'Lena', 'subject' => 'Team-only Shop ticket', 'expected' => 'granted'],
    ['source' => $adminMayNotEditTickets, 'attribute' => TicketPermission::EDIT, 'actor' => 'Sara', 'subject' => 'Team-only Shop ticket', 'expected' => 'denied'],
    ['source' => $viewerRights, 'attribute' => TicketPermission::EDIT, 'actor' => 'Raj', 'subject' => 'Team-only Shop ticket', 'expected' => 'denied'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => TicketPermission::EDIT, 'actor' => 'Lena', 'subject' => 'Team-only ticket with no team', 'expected' => 'denied'],

    ['source' => $memberRights, 'attribute' => TicketPermission::MOVE, 'actor' => 'Omar', 'subject' => 'Team-only Shop ticket', 'expected' => 'granted'],
    ['source' => $adminMayNotEditTickets, 'attribute' => TicketPermission::MOVE, 'actor' => 'Sara', 'subject' => 'Team-only Shop ticket', 'expected' => 'denied'],
    ['source' => $viewerRights, 'attribute' => TicketPermission::MOVE, 'actor' => 'Raj', 'subject' => 'Team-only Shop ticket', 'expected' => 'denied'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => TicketPermission::MOVE, 'actor' => 'Ben', 'subject' => 'Team-only Shop ticket', 'expected' => 'denied'],

    ['source' => $leadRights, 'attribute' => TicketPermission::ASSIGN, 'actor' => 'Lena', 'subject' => 'Team-only Shop ticket', 'expected' => 'granted'],
    ['source' => $managerAssigns, 'attribute' => TicketPermission::ASSIGN, 'actor' => 'Omar', 'subject' => 'Team-only Shop ticket', 'expected' => 'denied'],
    ['source' => $managerAssigns, 'attribute' => TicketPermission::ASSIGN, 'actor' => 'Raj', 'subject' => 'Team-only Shop ticket', 'expected' => 'denied'],
    ['source' => $managerAssigns, 'attribute' => TicketPermission::ASSIGN, 'actor' => 'Sara', 'subject' => 'Team-only Shop ticket', 'expected' => 'denied'],
];
