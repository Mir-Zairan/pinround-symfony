# SharedKernel

The small set of types every module shares: ids, `Clock`, `TenantContext`, the `DomainEvent` base and `Result`/`Problem`. It owns no tables. It has no Api interfaces of its own, because other modules use its types directly. It depends on no other module. Keep it tiny: a type joins only when three or more modules need it.

See the [module catalogue](../../docs/02-architecture/modules.md#sharedkernel).
