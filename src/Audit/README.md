# Audit

The activity log: who did what, when, with the old and new value. It is append-only. It owns the tables `aud_event` (P00.T7) and `aud_export` (P11). Its Api is `AuditLog::record(AuditEntry)`, with `ActorKind` and `AuditEntryForAnotherTenantRefused`; `Infrastructure\DbalAuditLog` implements it. `Infrastructure\Health\NextAuditPartitionExists` tells `/health/ready` whether next month's partition exists. It may use the Api of SharedKernel only.

See the [module catalogue](../../docs/02-architecture/modules.md#audit-aud_).
