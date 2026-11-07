# Audit

The activity log: who did what, when, with the old and new value. It is append-only. It owns the tables `aud_event` (P00.T7) and `aud_export` (P11). Its Api is `AuditLog::record(AuditEntry)` (an entry may carry the time of the action, `occurredAt`; else it is recorded at now), with `ActorKind` and `AuditEntryForAnotherTenantRefused`; `Infrastructure\DbalAuditLog` implements it. `Infrastructure\Health\NextAuditPartitionExists` tells `/health/ready` whether next month's partition exists. `Application\RecordOrganizationAuditEntry` records each Organization event once (`member.joined`, `org_roles.changed`, `team.created`, `team_role.changed`, at the event's time, with exactly the data keys of its `DATA_KEYS` allow-list); `config/services.yaml` tags it as an `event.bus` handler. It may use the Api of SharedKernel and the events of every module.

See the [module catalogue](../../docs/02-architecture/modules.md#audit-aud_).
