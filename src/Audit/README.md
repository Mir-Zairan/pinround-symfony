# Audit

The activity log: who did what, when, with the old and new value. It is append-only. It owns the tables `aud_event` and `aud_export`. Its Api is `AuditLog`. It may use the Api of SharedKernel only.

See the [module catalogue](../../docs/02-architecture/modules.md#audit-aud_).
