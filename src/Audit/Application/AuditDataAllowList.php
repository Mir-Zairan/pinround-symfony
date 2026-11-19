<?php

declare(strict_types=1);

namespace App\Audit\Application;

/**
 * The schema allow-list per action of ADR-0026, the one list of every action the audit log takes and the data keys
 * each holds. AuditLog::record (DbalAuditLog) checks every entry against it, whichever module writes the entry, so a
 * new action or key is refused until it is listed here.
 */
final class AuditDataAllowList
{
    /** Action => the keys its data must hold, all of them and no other. */
    public const array DATA_KEYS_BY_ACTION = [
        // Organization events (RecordOrganizationAuditEntry). No data for an invitation: the invited email address
        // stays out of the audit log; the target is the invitation.
        'member.invited' => [],
        'member.joined' => ['org_roles'],
        'org_roles.changed' => ['from', 'to'],
        'team.created' => ['lead_user_id'],
        'team_role.changed' => ['team_id', 'from', 'to'],
        // The setting's path and its values; SettingsChanged never carries a secret.
        'settings.changed' => ['setting', 'from', 'to'],
        // Identity's token events (RecordIdentityAuditEntry). Never the token, its hash, its prefix or its name; a
        // revoke has no data, as the revoked token is the target and its owner is kept in its row.
        'api_token.issued' => ['scopes', 'expires_at'],
        'api_token.revoked' => [],
        // AgentApi's ToolCallAuditor: the tool's name and how the call ended, never its input or output.
        'mcp.tool_called' => ['tool', 'outcome'],
        // Assist's LocalAiService: the refusal code and the model name when it is a valid one.
        'local_ai.result_refused' => ['reason', 'model'],
    ];

    /**
     * @param array<array-key, mixed> $entryData
     *
     * @throws \LogicException when the action has no allow-list, or $entryData does not hold exactly its keys; the
     *                         message names the action and the listed keys, never a value
     */
    public static function refuseUnlessAllowed(string $action, array $entryData): void
    {
        $allowedKeys = self::DATA_KEYS_BY_ACTION[$action] ?? throw new \LogicException(\sprintf('The audit action "%s" has no data allow-list.', $action));
        $keys = array_keys($entryData);
        if ([] !== array_diff($keys, $allowedKeys) || [] !== array_diff($allowedKeys, $keys)) {
            throw new \LogicException(\sprintf('The data of the audit action "%s" must hold exactly the keys %s.', $action, implode(', ', $allowedKeys)));
        }
    }
}
