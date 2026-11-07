# SharedKernel

The small set of types every module shares: ids, `Clock`, `TenantContext`, the `DomainEvent` base, `Result`/`Problem`, and `OnceOnlyHandling` with `MessageIdStamp` and `MessageBeingHandled` for idempotent async handlers, and `ReadinessCheck` (`Infrastructure/Health`) with the platform checks of `/health/ready`. It owns one table, `processed_message`, the record of which handler ran its effect for which message. It has no Api interfaces of its own, because other modules use its types directly. It depends on no other module. Keep it tiny: a type joins only when three or more modules need it.

See the [module catalogue](../../docs/02-architecture/modules.md#sharedkernel).
