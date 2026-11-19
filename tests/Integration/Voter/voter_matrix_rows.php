<?php

declare(strict_types=1);

use App\Conversation\Api\ConversationPermission;
use App\Identity\Application\AccountPermission;
use App\Identity\UI\Voter\AdminNeedsBrowserSessionVoter;
use App\Identity\UI\Voter\CurrentApiTokenVoter;
use App\Media\Api\MediaPermission;
use App\Notification\Api\MutePermission;
use App\Notification\Api\PingPermission;
use App\Organization\Api\AdminPermission;
use App\Organization\Api\OrganizationPermission;
use App\Organization\Api\TeamPermission;
use App\Review\Api\ReportDraftPermission;
use App\Review\Api\RoundPermission;
use App\Review\UI\Voter\ReportDraftVoter;
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
 *            Or a team-only ticket of Acme Foods: "Team-only Shop ticket", or "Team-only ticket with no team", both
 *            reported by someone who is no actor here; or "Team-only Support ticket reported by Mia" (a Viewer of
 *            Support) or "… reported by Omar" (no role in Support). Or a for-everyone ticket of Shop, where Noor has no
 *            role, so only the reporter exception lets her ask or ping: "For-everyone Shop ticket" (reported by someone
 *            who is no actor here) or "For-everyone Shop ticket reported by Noor". Or a comment on one of those
 *            tickets, "<person>'s comment on <ticket>", which carries the lead of the ticket's team (Shop and Mobile:
 *            Lena; Support: Noor) and none on the ticket with no team.
 *            Or an upload target (MEDIA_UPLOAD): "Upload to a team-only Shop ticket" or "… Support ticket", "Upload to a
 *            ticket nobody has", "Upload to a target of an unknown kind".
 * expected:  granted or denied.
 * twoStepProof: optional; decides for a person whose two-step login is on. true: they typed a code in this session 30
 *            minutes ago, within the 1 hour admin pages allow; an int: the code is that many seconds old; 'noCode': no
 *            code in this session. Without the option the person has no two-step login and no session.
 * login:     optional; 'password session' (the default), or 'personal access token' for a request TokenAuthenticator
 *            logged in with a token of the actor's organization.
 * refusalReason: optional, for a denied row; text the denying votes must give, so the row fails when another voter
 *            denies it in place of the one it is about.
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
 *
 * Or a round (RoundPermission VIEW, EDIT, MOVE_TO_REPORT_DRAFT): "Unshared draft round of Omar" or "Deleted draft round of Omar" on a Shop ticket,
 *            "Shared round of Omar on a team-only Shop ticket" or "… Support ticket", "… on a ticket nobody has", or
 *            "Shared round of another organization" or "Unshared draft round of Omar in another organization". Or a ticket for DRAFT_CREATE: "Unassigned Shop ticket", "Shop ticket
 *            assigned to Omar", "Done Shop ticket assigned to Omar", "Shop ticket in Testing, Mia's turn" (assigned to Omar),
 *            "Ticket nobody has, for a draft round".
 * Or "Report draft of Mia" (ReportDraftPermission), or "No subject" for an attribute whose voter takes none
 *            (REVOKE_CURRENT_TOKEN).
 *
 * The roles doc has no mute row: whoever may see a ticket may mute it for themselves (MuteVoter), so the
 * NOTIFICATION_MUTE rows cite the visibility text.
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
$commentAndReply = '| Comment, reply |';
$pingAPerson = '| Ping a person | ✅ ¹ | ✅ | ✅ | ✅ | — | ⚠️ only as reporter |';
$askAQuestion = '| Ask a question (pauses ticket) | ✅ ¹ | ✅ | ✅ | ✅ | — | ⚠️ only as reporter |';
$resolveAComment = '| Resolve a comment | ⚠️ own | ⚠️ own | ✅ any in team | ⚠️ own | ⚠️ own | ⚠️ own |';
$adminPings = 'An admin with no team role may see every ticket in the org (including team-only ones), comment, ask questions and ping';
$useAsMainSummary = '| Use an explanation as main summary | — | — | ✅ | ⚠️ if reporter | — | ⚠️ if reporter |';
$seeForEveryoneTickets = '| See tickets (visibility "all") | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |';
$invitationLinks = 'an invitation is accepted only by the account with the invited email, logged in, or by a logged-out person when nobody has an account with that email yet.';

$adminNeedsABrowserSession = 'Admin pages and admin actions never accept a token';

$seeADraftRound = '| See a draft round |';
$editADraftRound = '| Start / edit / share a draft round |';
$makeItANewTicket = '| Make it a new ticket (draft round to report draft) | ⚠️ owner of an unshared draft only | ⚠️ owner of an unshared draft only | ⚠️ owner of an unshared draft only | ⚠️ owner of an unshared draft only | — | — |';

$reportAProblem = '| Report a problem (create ticket through the intake) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |';
$ownReportDrafts = '| See, edit and send my report drafts ⁹ | ⚠️ own only | ⚠️ own only | ⚠️ own only | ⚠️ own only | ⚠️ own only | ⚠️ own only |';
$noAdminOrLeadSeesAReportDraft = 'A report draft is its owner\'s alone: no admin or lead sees it';
$onlyAClickSendsAReportDraft = 'but only the person\'s click in the browser sends it.';

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

    ['source' => $adminPages, 'attribute' => AdminPermission::AI, 'actor' => 'Lena', 'subject' => 'Acme Foods', 'expected' => 'granted', 'twoStepProof' => true],
    ['source' => $adminPages, 'attribute' => AdminPermission::AI, 'actor' => 'Sara', 'subject' => 'Acme Foods', 'expected' => 'granted', 'twoStepProof' => true],
    ['source' => $adminPages, 'attribute' => AdminPermission::AI, 'actor' => 'Ben', 'subject' => 'Beta Bakery', 'expected' => 'granted', 'twoStepProof' => true],
    ['source' => $adminPages, 'attribute' => AdminPermission::AI, 'actor' => 'Omar', 'subject' => 'Acme Foods', 'expected' => 'denied', 'twoStepProof' => true],
    ['source' => $adminPages, 'attribute' => AdminPermission::AI, 'actor' => 'Mia', 'subject' => 'Acme Foods', 'expected' => 'denied', 'twoStepProof' => true],
    ['source' => $adminPages, 'attribute' => AdminPermission::AI, 'actor' => 'Raj', 'subject' => 'Acme Foods', 'expected' => 'denied', 'twoStepProof' => true],
    ['source' => $adminPages, 'attribute' => AdminPermission::AI, 'actor' => 'Noor', 'subject' => 'Acme Foods', 'expected' => 'denied', 'twoStepProof' => true],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => AdminPermission::AI, 'actor' => 'Lena', 'subject' => 'Beta Bakery', 'expected' => 'denied', 'twoStepProof' => true],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => AdminPermission::AI, 'actor' => 'Ben', 'subject' => 'Acme Foods', 'expected' => 'denied', 'twoStepProof' => true],
    ['source' => $adminPages, 'attribute' => AdminPermission::AI, 'actor' => 'Sara', 'subject' => 'Acme Foods', 'expected' => 'granted', 'twoStepProof' => 3600],
    ['source' => $adminPages, 'attribute' => AdminPermission::AI, 'actor' => 'Lena', 'subject' => 'Acme Foods', 'expected' => 'denied', 'twoStepProof' => 3601],
    ['source' => $adminPages, 'attribute' => AdminPermission::AI, 'actor' => 'Sara', 'subject' => 'Acme Foods', 'expected' => 'denied', 'twoStepProof' => 3601],
    ['source' => $adminPages, 'attribute' => AdminPermission::AI, 'actor' => 'Lena', 'subject' => 'Acme Foods', 'expected' => 'denied', 'twoStepProof' => 'noCode'],
    ['source' => $adminPages, 'attribute' => AdminPermission::AI, 'actor' => 'Sara', 'subject' => 'Acme Foods', 'expected' => 'denied', 'twoStepProof' => 'noCode'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => AdminPermission::AI, 'actor' => 'Lena', 'subject' => 'Acme Foods', 'expected' => 'denied'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => AdminPermission::AI, 'actor' => 'Sara', 'subject' => 'Acme Foods', 'expected' => 'denied'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => AdminPermission::AI, 'actor' => 'Ben', 'subject' => 'Beta Bakery', 'expected' => 'denied'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => AdminPermission::AI, 'actor' => 'Raj', 'subject' => 'Acme Foods', 'expected' => 'denied'],

    // Each admin with a fresh code proof: the password session is granted (ADMIN_AREA and ADMIN_AI above), the token denied.
    ['source' => $adminPages, 'attribute' => OrganizationPermission::ADMIN, 'actor' => 'Lena', 'subject' => 'Acme Foods', 'expected' => 'granted', 'twoStepProof' => true],
    ['source' => $adminPages, 'attribute' => OrganizationPermission::ADMIN, 'actor' => 'Sara', 'subject' => 'Acme Foods', 'expected' => 'granted', 'twoStepProof' => true],
    ['source' => $adminPages, 'attribute' => OrganizationPermission::ADMIN, 'actor' => 'Ben', 'subject' => 'Beta Bakery', 'expected' => 'granted', 'twoStepProof' => true],
    ['source' => $adminPages, 'attribute' => OrganizationPermission::INVITE, 'actor' => 'Lena', 'subject' => 'Acme Foods', 'expected' => 'granted', 'twoStepProof' => true],
    ['source' => $adminPages, 'attribute' => OrganizationPermission::INVITE, 'actor' => 'Sara', 'subject' => 'Acme Foods', 'expected' => 'granted', 'twoStepProof' => true],
    ['source' => $adminPages, 'attribute' => OrganizationPermission::INVITE, 'actor' => 'Ben', 'subject' => 'Beta Bakery', 'expected' => 'granted', 'twoStepProof' => true],
    ['source' => $adminNeedsABrowserSession, 'attribute' => OrganizationPermission::ADMIN, 'actor' => 'Lena', 'subject' => 'Acme Foods', 'expected' => 'denied', 'twoStepProof' => true, 'login' => 'personal access token', 'refusalReason' => AdminNeedsBrowserSessionVoter::REFUSAL_REASON],
    ['source' => $adminNeedsABrowserSession, 'attribute' => OrganizationPermission::ADMIN, 'actor' => 'Sara', 'subject' => 'Acme Foods', 'expected' => 'denied', 'twoStepProof' => true, 'login' => 'personal access token', 'refusalReason' => AdminNeedsBrowserSessionVoter::REFUSAL_REASON],
    ['source' => $adminNeedsABrowserSession, 'attribute' => OrganizationPermission::ADMIN, 'actor' => 'Ben', 'subject' => 'Beta Bakery', 'expected' => 'denied', 'twoStepProof' => true, 'login' => 'personal access token', 'refusalReason' => AdminNeedsBrowserSessionVoter::REFUSAL_REASON],
    ['source' => $adminNeedsABrowserSession, 'attribute' => OrganizationPermission::INVITE, 'actor' => 'Lena', 'subject' => 'Acme Foods', 'expected' => 'denied', 'twoStepProof' => true, 'login' => 'personal access token', 'refusalReason' => AdminNeedsBrowserSessionVoter::REFUSAL_REASON],
    ['source' => $adminNeedsABrowserSession, 'attribute' => OrganizationPermission::INVITE, 'actor' => 'Sara', 'subject' => 'Acme Foods', 'expected' => 'denied', 'twoStepProof' => true, 'login' => 'personal access token', 'refusalReason' => AdminNeedsBrowserSessionVoter::REFUSAL_REASON],
    ['source' => $adminNeedsABrowserSession, 'attribute' => OrganizationPermission::INVITE, 'actor' => 'Ben', 'subject' => 'Beta Bakery', 'expected' => 'denied', 'twoStepProof' => true, 'login' => 'personal access token', 'refusalReason' => AdminNeedsBrowserSessionVoter::REFUSAL_REASON],
    ['source' => $adminNeedsABrowserSession, 'attribute' => AdminPermission::AREA, 'actor' => 'Lena', 'subject' => 'Acme Foods', 'expected' => 'denied', 'twoStepProof' => true, 'login' => 'personal access token', 'refusalReason' => AdminNeedsBrowserSessionVoter::REFUSAL_REASON],
    ['source' => $adminNeedsABrowserSession, 'attribute' => AdminPermission::AREA, 'actor' => 'Sara', 'subject' => 'Acme Foods', 'expected' => 'denied', 'twoStepProof' => true, 'login' => 'personal access token', 'refusalReason' => AdminNeedsBrowserSessionVoter::REFUSAL_REASON],
    ['source' => $adminNeedsABrowserSession, 'attribute' => AdminPermission::AREA, 'actor' => 'Ben', 'subject' => 'Beta Bakery', 'expected' => 'denied', 'twoStepProof' => true, 'login' => 'personal access token', 'refusalReason' => AdminNeedsBrowserSessionVoter::REFUSAL_REASON],
    ['source' => $adminNeedsABrowserSession, 'attribute' => AdminPermission::AI, 'actor' => 'Lena', 'subject' => 'Acme Foods', 'expected' => 'denied', 'twoStepProof' => true, 'login' => 'personal access token', 'refusalReason' => AdminNeedsBrowserSessionVoter::REFUSAL_REASON],
    ['source' => $adminNeedsABrowserSession, 'attribute' => AdminPermission::AI, 'actor' => 'Sara', 'subject' => 'Acme Foods', 'expected' => 'denied', 'twoStepProof' => true, 'login' => 'personal access token', 'refusalReason' => AdminNeedsBrowserSessionVoter::REFUSAL_REASON],
    ['source' => $adminNeedsABrowserSession, 'attribute' => AdminPermission::AI, 'actor' => 'Ben', 'subject' => 'Beta Bakery', 'expected' => 'denied', 'twoStepProof' => true, 'login' => 'personal access token', 'refusalReason' => AdminNeedsBrowserSessionVoter::REFUSAL_REASON],

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
    ['source' => $leadRights, 'attribute' => TicketPermission::ASSIGN, 'actor' => 'Noor', 'subject' => 'Support', 'expected' => 'granted'],
    ['source' => $leadRights, 'attribute' => TicketPermission::ASSIGN, 'actor' => 'Lena', 'subject' => 'Shop', 'expected' => 'granted'],
    ['source' => $leadRights, 'attribute' => TicketPermission::ASSIGN, 'actor' => 'Ben', 'subject' => 'Checkout', 'expected' => 'granted'],
    ['source' => $memberRights, 'attribute' => TicketPermission::ASSIGN, 'actor' => 'Omar', 'subject' => 'Shop', 'expected' => 'denied'],
    ['source' => $memberRights, 'attribute' => TicketPermission::ASSIGN, 'actor' => 'Mia', 'subject' => 'Shop', 'expected' => 'denied'],
    ['source' => $viewerRights, 'attribute' => TicketPermission::ASSIGN, 'actor' => 'Mia', 'subject' => 'Support', 'expected' => 'denied'],
    ['source' => $managerAssigns, 'attribute' => TicketPermission::ASSIGN, 'actor' => 'Raj', 'subject' => 'Shop', 'expected' => 'denied'],
    ['source' => $managerAssigns, 'attribute' => TicketPermission::ASSIGN, 'actor' => 'Raj', 'subject' => 'Mobile', 'expected' => 'denied'],
    ['source' => $managerAssigns, 'attribute' => TicketPermission::ASSIGN, 'actor' => 'Sara', 'subject' => 'Shop', 'expected' => 'denied'],
    ['source' => $managerAssigns, 'attribute' => TicketPermission::ASSIGN, 'actor' => 'Lena', 'subject' => 'Support', 'expected' => 'denied'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => TicketPermission::ASSIGN, 'actor' => 'Lena', 'subject' => 'Checkout', 'expected' => 'denied'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => TicketPermission::ASSIGN, 'actor' => 'Ben', 'subject' => 'Shop', 'expected' => 'denied'],

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

    ['source' => $useAsMainSummary, 'attribute' => TicketPermission::CHOOSE_MAIN_SUMMARY, 'actor' => 'Lena', 'subject' => 'Team-only Shop ticket', 'expected' => 'granted'],
    ['source' => $useAsMainSummary, 'attribute' => TicketPermission::CHOOSE_MAIN_SUMMARY, 'actor' => 'Mia', 'subject' => 'Team-only Support ticket reported by Mia', 'expected' => 'denied'],
    ['source' => $useAsMainSummary, 'attribute' => TicketPermission::CHOOSE_MAIN_SUMMARY, 'actor' => 'Omar', 'subject' => 'Team-only Support ticket reported by Omar', 'expected' => 'granted'],
    ['source' => $useAsMainSummary, 'attribute' => TicketPermission::CHOOSE_MAIN_SUMMARY, 'actor' => 'Noor', 'subject' => 'For-everyone Shop ticket reported by Noor', 'expected' => 'granted'],
    ['source' => $useAsMainSummary, 'attribute' => TicketPermission::CHOOSE_MAIN_SUMMARY, 'actor' => 'Omar', 'subject' => 'Team-only Shop ticket', 'expected' => 'denied'],
    ['source' => $useAsMainSummary, 'attribute' => TicketPermission::CHOOSE_MAIN_SUMMARY, 'actor' => 'Raj', 'subject' => 'Team-only Shop ticket', 'expected' => 'denied'],
    ['source' => $useAsMainSummary, 'attribute' => TicketPermission::CHOOSE_MAIN_SUMMARY, 'actor' => 'Sara', 'subject' => 'Team-only Shop ticket', 'expected' => 'denied'],
    ['source' => $useAsMainSummary, 'attribute' => TicketPermission::CHOOSE_MAIN_SUMMARY, 'actor' => 'Noor', 'subject' => 'For-everyone Shop ticket', 'expected' => 'denied'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => TicketPermission::CHOOSE_MAIN_SUMMARY, 'actor' => 'Ben', 'subject' => 'Team-only Shop ticket', 'expected' => 'denied'],

    ['source' => $commentAndReply, 'attribute' => ConversationPermission::COMMENT, 'actor' => 'Omar', 'subject' => 'Team-only Shop ticket', 'expected' => 'granted'],
    ['source' => $commentAndReply, 'attribute' => ConversationPermission::COMMENT, 'actor' => 'Raj', 'subject' => 'Team-only Shop ticket', 'expected' => 'granted'],
    ['source' => $adminWithoutTeamRole, 'attribute' => ConversationPermission::COMMENT, 'actor' => 'Sara', 'subject' => 'Team-only Shop ticket', 'expected' => 'granted'],
    ['source' => $triagerOfANoTeamTicket, 'attribute' => ConversationPermission::COMMENT, 'actor' => 'Noor', 'subject' => 'Team-only Shop ticket', 'expected' => 'denied'],
    ['source' => $triagerOfANoTeamTicket, 'attribute' => ConversationPermission::COMMENT, 'actor' => 'Noor', 'subject' => 'Team-only ticket with no team', 'expected' => 'granted'],
    ['source' => $managersOutsideTheTeam, 'attribute' => ConversationPermission::COMMENT, 'actor' => 'Raj', 'subject' => 'Team-only ticket with no team', 'expected' => 'denied'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => ConversationPermission::COMMENT, 'actor' => 'Ben', 'subject' => 'Team-only Shop ticket', 'expected' => 'denied'],
    ['source' => $pingAPerson, 'attribute' => PingPermission::SEND, 'actor' => 'Omar', 'subject' => 'Team-only Shop ticket', 'expected' => 'granted'],
    ['source' => $pingAPerson, 'attribute' => PingPermission::SEND, 'actor' => 'Mia', 'subject' => 'Team-only Shop ticket', 'expected' => 'granted'],
    ['source' => $pingAPerson, 'attribute' => PingPermission::SEND, 'actor' => 'Raj', 'subject' => 'Team-only Shop ticket', 'expected' => 'granted'],
    ['source' => $adminPings, 'attribute' => PingPermission::SEND, 'actor' => 'Sara', 'subject' => 'Team-only Shop ticket', 'expected' => 'granted'],
    ['source' => $teamOnlyTickets, 'attribute' => PingPermission::SEND, 'actor' => 'Noor', 'subject' => 'Team-only Shop ticket', 'expected' => 'denied'],
    ['source' => $pingAPerson, 'attribute' => PingPermission::SEND, 'actor' => 'Noor', 'subject' => 'Team-only ticket with no team', 'expected' => 'denied'],
    ['source' => $managersOutsideTheTeam, 'attribute' => PingPermission::SEND, 'actor' => 'Raj', 'subject' => 'Team-only ticket with no team', 'expected' => 'denied'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => PingPermission::SEND, 'actor' => 'Ben', 'subject' => 'Team-only Shop ticket', 'expected' => 'denied'],
    ['source' => $pingAPerson, 'attribute' => PingPermission::SEND, 'actor' => 'Mia', 'subject' => 'Team-only Support ticket reported by Mia', 'expected' => 'denied'],
    ['source' => $pingAPerson, 'attribute' => PingPermission::SEND, 'actor' => 'Omar', 'subject' => 'Team-only Support ticket reported by Omar', 'expected' => 'granted'],
    ['source' => $pingAPerson, 'attribute' => PingPermission::SEND, 'actor' => 'Mia', 'subject' => 'Team-only Support ticket reported by Omar', 'expected' => 'denied'],
    ['source' => $pingAPerson, 'attribute' => PingPermission::SEND, 'actor' => 'Noor', 'subject' => 'Team-only Support ticket reported by Mia', 'expected' => 'granted'],
    ['source' => $pingAPerson, 'attribute' => PingPermission::SEND, 'actor' => 'Noor', 'subject' => 'For-everyone Shop ticket', 'expected' => 'denied'],
    ['source' => $pingAPerson, 'attribute' => PingPermission::SEND, 'actor' => 'Noor', 'subject' => 'For-everyone Shop ticket reported by Noor', 'expected' => 'granted'],
    ['source' => $askAQuestion, 'attribute' => ConversationPermission::ASK, 'actor' => 'Omar', 'subject' => 'Team-only Shop ticket', 'expected' => 'granted'],
    ['source' => $askAQuestion, 'attribute' => ConversationPermission::ASK, 'actor' => 'Raj', 'subject' => 'Team-only Shop ticket', 'expected' => 'granted'],
    ['source' => $adminPings, 'attribute' => ConversationPermission::ASK, 'actor' => 'Sara', 'subject' => 'Team-only Shop ticket', 'expected' => 'granted'],
    ['source' => $teamOnlyTickets, 'attribute' => ConversationPermission::ASK, 'actor' => 'Noor', 'subject' => 'Team-only Shop ticket', 'expected' => 'denied'],
    ['source' => $askAQuestion, 'attribute' => ConversationPermission::ASK, 'actor' => 'Noor', 'subject' => 'Team-only ticket with no team', 'expected' => 'denied'],
    ['source' => $managersOutsideTheTeam, 'attribute' => ConversationPermission::ASK, 'actor' => 'Raj', 'subject' => 'Team-only ticket with no team', 'expected' => 'denied'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => ConversationPermission::ASK, 'actor' => 'Ben', 'subject' => 'Team-only Shop ticket', 'expected' => 'denied'],
    ['source' => $askAQuestion, 'attribute' => ConversationPermission::ASK, 'actor' => 'Mia', 'subject' => 'Team-only Support ticket reported by Mia', 'expected' => 'denied'],
    ['source' => $askAQuestion, 'attribute' => ConversationPermission::ASK, 'actor' => 'Omar', 'subject' => 'Team-only Support ticket reported by Omar', 'expected' => 'granted'],
    ['source' => $askAQuestion, 'attribute' => ConversationPermission::ASK, 'actor' => 'Mia', 'subject' => 'Team-only Support ticket reported by Omar', 'expected' => 'denied'],
    ['source' => $askAQuestion, 'attribute' => ConversationPermission::ASK, 'actor' => 'Noor', 'subject' => 'Team-only Support ticket reported by Mia', 'expected' => 'granted'],
    ['source' => $askAQuestion, 'attribute' => ConversationPermission::ASK, 'actor' => 'Noor', 'subject' => 'For-everyone Shop ticket', 'expected' => 'denied'],
    ['source' => $askAQuestion, 'attribute' => ConversationPermission::ASK, 'actor' => 'Noor', 'subject' => 'For-everyone Shop ticket reported by Noor', 'expected' => 'granted'],
    ['source' => $resolveAComment, 'attribute' => ConversationPermission::RESOLVE, 'actor' => 'Omar', 'subject' => 'Omar\'s comment on Team-only Shop ticket', 'expected' => 'granted'],
    ['source' => $resolveAComment, 'attribute' => ConversationPermission::RESOLVE, 'actor' => 'Lena', 'subject' => 'Omar\'s comment on Team-only Shop ticket', 'expected' => 'granted'],
    ['source' => $resolveAComment, 'attribute' => ConversationPermission::RESOLVE, 'actor' => 'Mia', 'subject' => 'Omar\'s comment on Team-only Shop ticket', 'expected' => 'denied'],
    ['source' => $resolveAComment, 'attribute' => ConversationPermission::RESOLVE, 'actor' => 'Sara', 'subject' => 'Omar\'s comment on Team-only Shop ticket', 'expected' => 'denied'],
    ['source' => $resolveAComment, 'attribute' => ConversationPermission::RESOLVE, 'actor' => 'Sara', 'subject' => 'Sara\'s comment on Team-only Shop ticket', 'expected' => 'granted'],
    ['source' => $resolveAComment, 'attribute' => ConversationPermission::RESOLVE, 'actor' => 'Raj', 'subject' => 'Omar\'s comment on Team-only Shop ticket', 'expected' => 'denied'],
    ['source' => $resolveAComment, 'attribute' => ConversationPermission::RESOLVE, 'actor' => 'Raj', 'subject' => 'Raj\'s comment on Team-only Shop ticket', 'expected' => 'granted'],
    ['source' => $resolveAComment, 'attribute' => ConversationPermission::RESOLVE, 'actor' => 'Mia', 'subject' => 'Mia\'s comment on Team-only Support ticket reported by Mia', 'expected' => 'granted'],
    ['source' => $resolveAComment, 'attribute' => ConversationPermission::RESOLVE, 'actor' => 'Noor', 'subject' => 'Mia\'s comment on Team-only Support ticket reported by Mia', 'expected' => 'granted'],
    ['source' => $resolveAComment, 'attribute' => ConversationPermission::RESOLVE, 'actor' => 'Lena', 'subject' => 'Mia\'s comment on Team-only Support ticket reported by Mia', 'expected' => 'denied'],
    ['source' => $resolveAComment, 'attribute' => ConversationPermission::RESOLVE, 'actor' => 'Omar', 'subject' => 'Omar\'s comment on Team-only Support ticket reported by Omar', 'expected' => 'granted'],
    ['source' => $resolveAComment, 'attribute' => ConversationPermission::RESOLVE, 'actor' => 'Noor', 'subject' => 'Noor\'s comment on Team-only ticket with no team', 'expected' => 'granted'],
    ['source' => $resolveAComment, 'attribute' => ConversationPermission::RESOLVE, 'actor' => 'Lena', 'subject' => 'Noor\'s comment on Team-only ticket with no team', 'expected' => 'denied'],
    ['source' => $teamOnlyTickets, 'attribute' => ConversationPermission::RESOLVE, 'actor' => 'Noor', 'subject' => 'Noor\'s comment on Team-only Shop ticket', 'expected' => 'denied'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => ConversationPermission::RESOLVE, 'actor' => 'Ben', 'subject' => 'Ben\'s comment on Team-only Shop ticket', 'expected' => 'denied'],

    ['source' => $teamOnlyTickets, 'attribute' => MutePermission::MUTE, 'actor' => 'Noor', 'subject' => 'Team-only Support ticket reported by Omar', 'expected' => 'granted'],
    ['source' => $teamOnlyTickets, 'attribute' => MutePermission::MUTE, 'actor' => 'Omar', 'subject' => 'Team-only Shop ticket', 'expected' => 'granted'],
    ['source' => $viewerRights, 'attribute' => MutePermission::MUTE, 'actor' => 'Mia', 'subject' => 'Team-only Support ticket reported by Omar', 'expected' => 'granted'],
    ['source' => $adminWithoutTeamRole, 'attribute' => MutePermission::MUTE, 'actor' => 'Sara', 'subject' => 'Team-only Shop ticket', 'expected' => 'granted'],
    ['source' => $teamOnlyTickets, 'attribute' => MutePermission::MUTE, 'actor' => 'Noor', 'subject' => 'Team-only Shop ticket', 'expected' => 'denied'],
    ['source' => $managersOutsideTheTeam, 'attribute' => MutePermission::MUTE, 'actor' => 'Raj', 'subject' => 'Team-only ticket with no team', 'expected' => 'denied'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => MutePermission::MUTE, 'actor' => 'Ben', 'subject' => 'Team-only Shop ticket', 'expected' => 'denied'],
    ['source' => $seeForEveryoneTickets, 'attribute' => MutePermission::MUTE, 'actor' => 'Noor', 'subject' => 'For-everyone Shop ticket', 'expected' => 'granted'],
    ['source' => $seeForEveryoneTickets, 'attribute' => MutePermission::MUTE, 'actor' => 'Raj', 'subject' => 'For-everyone Shop ticket', 'expected' => 'granted'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => MutePermission::MUTE, 'actor' => 'Ben', 'subject' => 'For-everyone Shop ticket', 'expected' => 'denied'],

    ['source' => $leadRights, 'attribute' => MediaPermission::UPLOAD, 'actor' => 'Lena', 'subject' => 'Upload to a team-only Shop ticket', 'expected' => 'granted'],
    ['source' => $memberRights, 'attribute' => MediaPermission::UPLOAD, 'actor' => 'Omar', 'subject' => 'Upload to a team-only Shop ticket', 'expected' => 'granted'],
    ['source' => $leadRights, 'attribute' => MediaPermission::UPLOAD, 'actor' => 'Noor', 'subject' => 'Upload to a team-only Support ticket', 'expected' => 'granted'],
    ['source' => $viewerRights, 'attribute' => MediaPermission::UPLOAD, 'actor' => 'Raj', 'subject' => 'Upload to a team-only Shop ticket', 'expected' => 'denied'],
    ['source' => $viewerRights, 'attribute' => MediaPermission::UPLOAD, 'actor' => 'Mia', 'subject' => 'Upload to a team-only Support ticket', 'expected' => 'denied'],
    ['source' => $adminMayNotEditTickets, 'attribute' => MediaPermission::UPLOAD, 'actor' => 'Sara', 'subject' => 'Upload to a team-only Shop ticket', 'expected' => 'denied'],
    ['source' => $memberRights, 'attribute' => MediaPermission::UPLOAD, 'actor' => 'Omar', 'subject' => 'Upload to a team-only Support ticket', 'expected' => 'denied'],
    ['source' => $memberRights, 'attribute' => MediaPermission::UPLOAD, 'actor' => 'Noor', 'subject' => 'Upload to a team-only Shop ticket', 'expected' => 'denied'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => MediaPermission::UPLOAD, 'actor' => 'Ben', 'subject' => 'Upload to a team-only Shop ticket', 'expected' => 'denied'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => MediaPermission::UPLOAD, 'actor' => 'Lena', 'subject' => 'Upload to a ticket nobody has', 'expected' => 'denied'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => MediaPermission::UPLOAD, 'actor' => 'Lena', 'subject' => 'Upload to a target of an unknown kind', 'expected' => 'denied'],

    // Rounds and draft rounds.
    ['source' => $seeADraftRound, 'attribute' => RoundPermission::VIEW, 'actor' => 'Omar', 'subject' => 'Unshared draft round of Omar', 'expected' => 'granted'],
    ['source' => $seeADraftRound, 'attribute' => RoundPermission::VIEW, 'actor' => 'Lena', 'subject' => 'Unshared draft round of Omar', 'expected' => 'denied'],
    ['source' => $seeADraftRound, 'attribute' => RoundPermission::VIEW, 'actor' => 'Sara', 'subject' => 'Unshared draft round of Omar', 'expected' => 'denied'],
    ['source' => $seeADraftRound, 'attribute' => RoundPermission::VIEW, 'actor' => 'Raj', 'subject' => 'Unshared draft round of Omar', 'expected' => 'denied'],
    ['source' => $seeADraftRound, 'attribute' => RoundPermission::VIEW, 'actor' => 'Mia', 'subject' => 'Unshared draft round of Omar', 'expected' => 'denied'],
    ['source' => $seeADraftRound, 'attribute' => RoundPermission::VIEW, 'actor' => 'Noor', 'subject' => 'Unshared draft round of Omar', 'expected' => 'denied'],
    ['source' => $editADraftRound, 'attribute' => RoundPermission::EDIT, 'actor' => 'Omar', 'subject' => 'Unshared draft round of Omar', 'expected' => 'granted'],
    ['source' => $editADraftRound, 'attribute' => RoundPermission::EDIT, 'actor' => 'Lena', 'subject' => 'Unshared draft round of Omar', 'expected' => 'denied'],
    ['source' => $editADraftRound, 'attribute' => RoundPermission::EDIT, 'actor' => 'Sara', 'subject' => 'Unshared draft round of Omar', 'expected' => 'denied'],
    ['source' => $seeADraftRound, 'attribute' => RoundPermission::VIEW, 'actor' => 'Omar', 'subject' => 'Deleted draft round of Omar', 'expected' => 'denied'],
    ['source' => $editADraftRound, 'attribute' => RoundPermission::EDIT, 'actor' => 'Omar', 'subject' => 'Deleted draft round of Omar', 'expected' => 'denied'],
    ['source' => $seeADraftRound, 'attribute' => RoundPermission::VIEW, 'actor' => 'Lena', 'subject' => 'Shared round of Omar on a team-only Shop ticket', 'expected' => 'granted'],
    ['source' => $seeADraftRound, 'attribute' => RoundPermission::VIEW, 'actor' => 'Omar', 'subject' => 'Shared round of Omar on a team-only Shop ticket', 'expected' => 'granted'],
    ['source' => $seeADraftRound, 'attribute' => RoundPermission::VIEW, 'actor' => 'Mia', 'subject' => 'Shared round of Omar on a team-only Shop ticket', 'expected' => 'granted'],
    ['source' => $seeADraftRound, 'attribute' => RoundPermission::VIEW, 'actor' => 'Raj', 'subject' => 'Shared round of Omar on a team-only Shop ticket', 'expected' => 'granted'],
    ['source' => $seeADraftRound, 'attribute' => RoundPermission::VIEW, 'actor' => 'Sara', 'subject' => 'Shared round of Omar on a team-only Shop ticket', 'expected' => 'granted'],
    ['source' => $seeADraftRound, 'attribute' => RoundPermission::VIEW, 'actor' => 'Noor', 'subject' => 'Shared round of Omar on a team-only Shop ticket', 'expected' => 'denied'],
    ['source' => $seeADraftRound, 'attribute' => RoundPermission::VIEW, 'actor' => 'Ben', 'subject' => 'Shared round of Omar on a team-only Shop ticket', 'expected' => 'denied'],
    ['source' => $editADraftRound, 'attribute' => RoundPermission::EDIT, 'actor' => 'Lena', 'subject' => 'Shared round of Omar on a team-only Shop ticket', 'expected' => 'granted'],
    ['source' => $editADraftRound, 'attribute' => RoundPermission::EDIT, 'actor' => 'Omar', 'subject' => 'Shared round of Omar on a team-only Shop ticket', 'expected' => 'granted'],
    ['source' => $editADraftRound, 'attribute' => RoundPermission::EDIT, 'actor' => 'Raj', 'subject' => 'Shared round of Omar on a team-only Shop ticket', 'expected' => 'denied'],
    ['source' => $editADraftRound, 'attribute' => RoundPermission::EDIT, 'actor' => 'Sara', 'subject' => 'Shared round of Omar on a team-only Shop ticket', 'expected' => 'denied'],
    ['source' => $editADraftRound, 'attribute' => RoundPermission::EDIT, 'actor' => 'Noor', 'subject' => 'Shared round of Omar on a team-only Shop ticket', 'expected' => 'denied'],
    ['source' => $seeADraftRound, 'attribute' => RoundPermission::VIEW, 'actor' => 'Omar', 'subject' => 'Shared round of Omar on a team-only Support ticket', 'expected' => 'denied'],
    ['source' => $seeADraftRound, 'attribute' => RoundPermission::VIEW, 'actor' => 'Omar', 'subject' => 'Shared round of Omar on a ticket nobody has', 'expected' => 'denied'],
    ['source' => $seeADraftRound, 'attribute' => RoundPermission::VIEW, 'actor' => 'Lena', 'subject' => 'Shared round of another organization', 'expected' => 'denied'],
    ['source' => $editADraftRound, 'attribute' => RoundPermission::EDIT, 'actor' => 'Lena', 'subject' => 'Shared round of another organization', 'expected' => 'denied'],
    ['source' => $seeADraftRound, 'attribute' => RoundPermission::VIEW, 'actor' => 'Omar', 'subject' => 'Unshared draft round of Omar in another organization', 'expected' => 'denied'],
    ['source' => $editADraftRound, 'attribute' => RoundPermission::EDIT, 'actor' => 'Omar', 'subject' => 'Unshared draft round of Omar in another organization', 'expected' => 'denied'],
    ['source' => $makeItANewTicket, 'attribute' => RoundPermission::MOVE_TO_REPORT_DRAFT, 'actor' => 'Omar', 'subject' => 'Unshared draft round of Omar', 'expected' => 'granted'],
    ['source' => $makeItANewTicket, 'attribute' => RoundPermission::MOVE_TO_REPORT_DRAFT, 'actor' => 'Lena', 'subject' => 'Unshared draft round of Omar', 'expected' => 'denied'],
    ['source' => $makeItANewTicket, 'attribute' => RoundPermission::MOVE_TO_REPORT_DRAFT, 'actor' => 'Sara', 'subject' => 'Unshared draft round of Omar', 'expected' => 'denied'],
    ['source' => $makeItANewTicket, 'attribute' => RoundPermission::MOVE_TO_REPORT_DRAFT, 'actor' => 'Raj', 'subject' => 'Unshared draft round of Omar', 'expected' => 'denied'],
    ['source' => $makeItANewTicket, 'attribute' => RoundPermission::MOVE_TO_REPORT_DRAFT, 'actor' => 'Mia', 'subject' => 'Unshared draft round of Omar', 'expected' => 'denied'],
    ['source' => $makeItANewTicket, 'attribute' => RoundPermission::MOVE_TO_REPORT_DRAFT, 'actor' => 'Omar', 'subject' => 'Deleted draft round of Omar', 'expected' => 'denied'],
    ['source' => $makeItANewTicket, 'attribute' => RoundPermission::MOVE_TO_REPORT_DRAFT, 'actor' => 'Omar', 'subject' => 'Shared round of Omar on a team-only Shop ticket', 'expected' => 'denied'],
    ['source' => $makeItANewTicket, 'attribute' => RoundPermission::MOVE_TO_REPORT_DRAFT, 'actor' => 'Lena', 'subject' => 'Shared round of Omar on a team-only Shop ticket', 'expected' => 'denied'],
    ['source' => $makeItANewTicket, 'attribute' => RoundPermission::MOVE_TO_REPORT_DRAFT, 'actor' => 'Lena', 'subject' => 'Shared round of another organization', 'expected' => 'denied'],
    ['source' => $makeItANewTicket, 'attribute' => RoundPermission::MOVE_TO_REPORT_DRAFT, 'actor' => 'Omar', 'subject' => 'Unshared draft round of Omar in another organization', 'expected' => 'denied'],
    ['source' => $editADraftRound, 'attribute' => RoundPermission::DRAFT_CREATE, 'actor' => 'Omar', 'subject' => 'Shop ticket assigned to Omar', 'expected' => 'granted'],
    ['source' => $editADraftRound, 'attribute' => RoundPermission::DRAFT_CREATE, 'actor' => 'Lena', 'subject' => 'Shop ticket assigned to Omar', 'expected' => 'denied'],
    ['source' => $editADraftRound, 'attribute' => RoundPermission::DRAFT_CREATE, 'actor' => 'Mia', 'subject' => 'Shop ticket assigned to Omar', 'expected' => 'denied'],
    ['source' => $editADraftRound, 'attribute' => RoundPermission::DRAFT_CREATE, 'actor' => 'Raj', 'subject' => 'Shop ticket assigned to Omar', 'expected' => 'denied'],
    ['source' => $editADraftRound, 'attribute' => RoundPermission::DRAFT_CREATE, 'actor' => 'Sara', 'subject' => 'Shop ticket assigned to Omar', 'expected' => 'denied'],
    ['source' => $editADraftRound, 'attribute' => RoundPermission::DRAFT_CREATE, 'actor' => 'Noor', 'subject' => 'Shop ticket assigned to Omar', 'expected' => 'denied'],
    ['source' => $editADraftRound, 'attribute' => RoundPermission::DRAFT_CREATE, 'actor' => 'Ben', 'subject' => 'Shop ticket assigned to Omar', 'expected' => 'denied'],
    ['source' => $editADraftRound, 'attribute' => RoundPermission::DRAFT_CREATE, 'actor' => 'Omar', 'subject' => 'Done Shop ticket assigned to Omar', 'expected' => 'denied'],
    ['source' => $editADraftRound, 'attribute' => RoundPermission::DRAFT_CREATE, 'actor' => 'Omar', 'subject' => 'Unassigned Shop ticket', 'expected' => 'denied'],
    ['source' => $editADraftRound, 'attribute' => RoundPermission::DRAFT_CREATE, 'actor' => 'Omar', 'subject' => 'Ticket nobody has, for a draft round', 'expected' => 'denied'],
    ['source' => $editADraftRound, 'attribute' => RoundPermission::DRAFT_CREATE, 'actor' => 'Mia', 'subject' => 'Shop ticket in Testing, Mia\'s turn', 'expected' => 'granted'],
    ['source' => $editADraftRound, 'attribute' => RoundPermission::DRAFT_CREATE, 'actor' => 'Omar', 'subject' => 'Shop ticket in Testing, Mia\'s turn', 'expected' => 'granted'],
    ['source' => $editADraftRound, 'attribute' => RoundPermission::DRAFT_CREATE, 'actor' => 'Lena', 'subject' => 'Shop ticket in Testing, Mia\'s turn', 'expected' => 'denied'],
    ['source' => $editADraftRound, 'attribute' => RoundPermission::DRAFT_CREATE, 'actor' => 'Raj', 'subject' => 'Shop ticket in Testing, Mia\'s turn', 'expected' => 'denied'],
    ['source' => $editADraftRound, 'attribute' => RoundPermission::DRAFT_CREATE, 'actor' => 'Sara', 'subject' => 'Shop ticket in Testing, Mia\'s turn', 'expected' => 'denied'],
    ['source' => $editADraftRound, 'attribute' => RoundPermission::DRAFT_CREATE, 'actor' => 'Noor', 'subject' => 'Shop ticket in Testing, Mia\'s turn', 'expected' => 'denied'],
    ['source' => $editADraftRound, 'attribute' => RoundPermission::DRAFT_CREATE, 'actor' => 'Ben', 'subject' => 'Shop ticket in Testing, Mia\'s turn', 'expected' => 'denied'],

    // Report a problem: every role of the organization, nobody of another.
    ['source' => $reportAProblem, 'attribute' => TicketPermission::REPORT, 'actor' => 'Lena', 'subject' => 'Acme Foods', 'expected' => 'granted'],
    ['source' => $reportAProblem, 'attribute' => TicketPermission::REPORT, 'actor' => 'Omar', 'subject' => 'Acme Foods', 'expected' => 'granted'],
    ['source' => $reportAProblem, 'attribute' => TicketPermission::REPORT, 'actor' => 'Mia', 'subject' => 'Acme Foods', 'expected' => 'granted'],
    ['source' => $reportAProblem, 'attribute' => TicketPermission::REPORT, 'actor' => 'Raj', 'subject' => 'Acme Foods', 'expected' => 'granted'],
    ['source' => $reportAProblem, 'attribute' => TicketPermission::REPORT, 'actor' => 'Sara', 'subject' => 'Acme Foods', 'expected' => 'granted'],
    ['source' => $reportAProblem, 'attribute' => TicketPermission::REPORT, 'actor' => 'Noor', 'subject' => 'Acme Foods', 'expected' => 'granted'],
    ['source' => $reportAProblem, 'attribute' => TicketPermission::REPORT, 'actor' => 'Ben', 'subject' => 'Beta Bakery', 'expected' => 'granted'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => TicketPermission::REPORT, 'actor' => 'Ben', 'subject' => 'Acme Foods', 'expected' => 'denied'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => TicketPermission::REPORT, 'actor' => 'Lena', 'subject' => 'Beta Bakery', 'expected' => 'denied'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => TicketPermission::REPORT, 'actor' => 'Omar', 'subject' => 'Beta Bakery', 'expected' => 'denied'],

    // Report drafts: Mia's draft is hers alone; a token may see and change it, never send it.
    ['source' => $ownReportDrafts, 'attribute' => ReportDraftPermission::VIEW, 'actor' => 'Mia', 'subject' => 'Report draft of Mia', 'expected' => 'granted'],
    ['source' => $ownReportDrafts, 'attribute' => ReportDraftPermission::EDIT, 'actor' => 'Mia', 'subject' => 'Report draft of Mia', 'expected' => 'granted'],
    ['source' => $ownReportDrafts, 'attribute' => ReportDraftPermission::SEND, 'actor' => 'Mia', 'subject' => 'Report draft of Mia', 'expected' => 'granted'],
    ['source' => $ownReportDrafts, 'attribute' => ReportDraftPermission::VIEW, 'actor' => 'Omar', 'subject' => 'Report draft of Mia', 'expected' => 'denied'],
    ['source' => $ownReportDrafts, 'attribute' => ReportDraftPermission::EDIT, 'actor' => 'Omar', 'subject' => 'Report draft of Mia', 'expected' => 'denied'],
    ['source' => $ownReportDrafts, 'attribute' => ReportDraftPermission::SEND, 'actor' => 'Omar', 'subject' => 'Report draft of Mia', 'expected' => 'denied'],
    ['source' => $noAdminOrLeadSeesAReportDraft, 'attribute' => ReportDraftPermission::VIEW, 'actor' => 'Lena', 'subject' => 'Report draft of Mia', 'expected' => 'denied'],
    ['source' => $noAdminOrLeadSeesAReportDraft, 'attribute' => ReportDraftPermission::EDIT, 'actor' => 'Lena', 'subject' => 'Report draft of Mia', 'expected' => 'denied'],
    ['source' => $noAdminOrLeadSeesAReportDraft, 'attribute' => ReportDraftPermission::VIEW, 'actor' => 'Sara', 'subject' => 'Report draft of Mia', 'expected' => 'denied'],
    ['source' => $noAdminOrLeadSeesAReportDraft, 'attribute' => ReportDraftPermission::EDIT, 'actor' => 'Sara', 'subject' => 'Report draft of Mia', 'expected' => 'denied'],
    ['source' => $noAdminOrLeadSeesAReportDraft, 'attribute' => ReportDraftPermission::VIEW, 'actor' => 'Raj', 'subject' => 'Report draft of Mia', 'expected' => 'denied'],
    ['source' => $noAdminOrLeadSeesAReportDraft, 'attribute' => ReportDraftPermission::VIEW, 'actor' => 'Noor', 'subject' => 'Report draft of Mia', 'expected' => 'denied'],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => ReportDraftPermission::VIEW, 'actor' => 'Ben', 'subject' => 'Report draft of Mia', 'expected' => 'denied'],
    ['source' => $onlyAClickSendsAReportDraft, 'attribute' => ReportDraftPermission::VIEW, 'actor' => 'Mia', 'subject' => 'Report draft of Mia', 'expected' => 'granted', 'login' => 'personal access token'],
    ['source' => $onlyAClickSendsAReportDraft, 'attribute' => ReportDraftPermission::EDIT, 'actor' => 'Mia', 'subject' => 'Report draft of Mia', 'expected' => 'granted', 'login' => 'personal access token'],
    ['source' => $onlyAClickSendsAReportDraft, 'attribute' => ReportDraftPermission::SEND, 'actor' => 'Mia', 'subject' => 'Report draft of Mia', 'expected' => 'denied', 'login' => 'personal access token', 'refusalReason' => ReportDraftVoter::TOKEN_REFUSAL_REASON],

    // Revoking the current token: only a request made with that token has one, so a browser session is denied.
    ['source' => $deniedUnlessARuleAllows, 'attribute' => AccountPermission::REVOKE_CURRENT_TOKEN, 'actor' => 'Omar', 'subject' => 'No subject', 'expected' => 'denied', 'refusalReason' => CurrentApiTokenVoter::SESSION_REFUSAL_REASON],
    ['source' => $deniedUnlessARuleAllows, 'attribute' => AccountPermission::REVOKE_CURRENT_TOKEN, 'actor' => 'Sara', 'subject' => 'No subject', 'expected' => 'denied', 'refusalReason' => CurrentApiTokenVoter::SESSION_REFUSAL_REASON],
];
