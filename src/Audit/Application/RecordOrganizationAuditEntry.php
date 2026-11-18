<?php

declare(strict_types=1);

namespace App\Audit\Application;

use App\Audit\Api\AuditEntry;
use App\Audit\Api\AuditLog;
use App\Organization\Api\Event\MemberInvited;
use App\Organization\Api\Event\MemberJoined;
use App\Organization\Api\Event\OrganizationEvent;
use App\Organization\Api\Event\OrgRolesChanged;
use App\Organization\Api\Event\SettingsChanged;
use App\Organization\Api\Event\TeamCreated;
use App\Organization\Api\Event\TeamRoleChanged;
use App\SharedKernel\Application\OnceOnlyHandling;
use App\SharedKernel\Domain\OrganizationId;

/**
 * Writes one aud_event row per Organization event (ADR-0026: role and membership changes, team changes, settings changes), once per
 * message however often it is delivered. It runs in worker-notify on event.bus (config/services.yaml), in the bus's
 * transaction under the event's organization, and keeps the event's time. The data of each action holds exactly the
 * keys of DATA_KEYS, ids, role values and setting values, and requireAllowListedData() refuses any other.
 */
final readonly class RecordOrganizationAuditEntry
{
    /** The OnceOnlyHandling name; kept when the class is renamed, or redelivered events would be recorded again. */
    public const string HANDLER_NAME = 'audit.record_organization_event';

    /** Action => the keys its data may hold. */
    public const array DATA_KEYS = [
        // No data: the invited email address stays out of the audit log; the target is the invitation.
        'member.invited' => [],
        'member.joined' => ['org_roles'],
        'org_roles.changed' => ['from', 'to'],
        'team.created' => ['lead_user_id'],
        'team_role.changed' => ['team_id', 'from', 'to'],
        // The setting's path and its values; SettingsChanged never carries a secret.
        'settings.changed' => ['setting', 'from', 'to'],
    ];

    public function __construct(private OnceOnlyHandling $onceOnlyHandling, private AuditLog $auditLog)
    {
    }

    /**
     * @throws \LogicException for an Organization event this class has no action for, so it is retried and then
     *                         waits in the failed transport instead of going unrecorded
     */
    public function __invoke(OrganizationEvent $event): void
    {
        $entry = $this->entryFor($event);
        $this->onceOnlyHandling->runOnce(self::HANDLER_NAME, function () use ($entry): void {
            $this->auditLog->record($entry);
        });
    }

    private function entryFor(OrganizationEvent $event): AuditEntry
    {
        [$action, $targetType, $targetId, $idsAndRoleValues] = match (true) {
            $event instanceof MemberInvited => ['member.invited', 'invitation', $event->invitationId, []],
            $event instanceof MemberJoined => ['member.joined', 'user', $event->userId, ['org_roles' => $event->orgRoles]],
            $event instanceof OrgRolesChanged => ['org_roles.changed', 'user', $event->userId, ['from' => $event->orgRolesBefore, 'to' => $event->orgRolesAfter]],
            $event instanceof TeamCreated => ['team.created', 'team', $event->teamId, ['lead_user_id' => $event->leadUserId]],
            $event instanceof TeamRoleChanged => ['team_role.changed', 'user', $event->userId, ['team_id' => $event->teamId, 'from' => $event->teamRoleBefore, 'to' => $event->teamRoleAfter]],
            $event instanceof SettingsChanged => ['settings.changed', 'organization', $event->organizationId, ['setting' => $event->setting, 'from' => $event->settingBefore, 'to' => $event->settingAfter]],
            default => throw new \LogicException(\sprintf('"%s" has no audit action.', $event::class)),
        };
        $allowListedValues = self::requireAllowListedData($action, $idsAndRoleValues);
        $organizationId = new OrganizationId($event->organizationId);

        return null === $event->actorId
            ? AuditEntry::bySystem($organizationId, $action, $targetType, $targetId, $allowListedValues, $event->occurredAt)
            : AuditEntry::byUser($organizationId, $event->actorId, $action, $targetType, $targetId, $allowListedValues, occurredAt: $event->occurredAt);
    }

    /**
     * The check entryFor() runs on the data of every entry (ADR-0026: a schema allow-list per action).
     *
     * @param array<string, mixed> $entryData
     *
     * @return array<string, mixed> $entryData unchanged
     *
     * @throws \LogicException when the action has no allow-list, or $entryData does not hold exactly its keys
     */
    public static function requireAllowListedData(string $action, array $entryData): array
    {
        return AuditDataAllowList::requireExactKeys(self::DATA_KEYS, $action, $entryData);
    }
}
