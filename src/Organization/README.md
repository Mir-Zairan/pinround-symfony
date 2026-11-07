# Organization

Organizations, teams, members, roles, invitations and settings such as ping limits and AI policy. It owns the tables `org_organization`, `org_membership`, `org_team`, `org_team_member`, `org_invitation` and `org_settings`. Its Api is `MembershipQueries`, `TeamQueries` and `OrgSettings`, and its events in `Api\Event` (`MemberJoined`, `OrgRolesChanged`, `TeamCreated`, `TeamRoleChanged`, all `OrganizationEvent`), which go through the outbox to `async_notify`. Its commands in `Application` (`CreateOrganization`, `AddMember`, `SetOrgRoles`, `CreateTeam`, `SetTeamRole`) write in the caller's transaction and publish their events through `OrganizationEvents` (`Infrastructure\Messenger\MessengerOrganizationEvents`). SetOrgRoles locks the organization's admins and SetTeamRole the team before changing anything, so concurrent changes keep an admin and one lead. It may use the Api of Identity.

See the [module catalogue](../../docs/02-architecture/modules.md#organization-org_).
