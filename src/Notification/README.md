# Notification

Pings, the bell, mutes and email digests. Pings are rate limited and clear by themselves when the person acts on the ticket. It owns the tables `ntf_ping`, `ntf_notification`, `ntf_mute` and `ntf_delivery`. Its Api is `PingCommands` and `NotificationQueries`. It may use the Api of Organization and Ticketing.

Its events, in `Api\Event`, are `PingSent` and `PingCleared` (`NotificationEvent`, ids only, through the outbox to `async_notify`); `Domain\QuietHours` decides whether a ping is held until 09:00.

Its tables are built (`Version20261114130000`): `ntf_ping` mapped by `Domain\Ping`; `ntf_notification` (monthly partitions from `NotificationPartitions`), `ntf_mute` and `ntf_delivery` are DBAL tables hidden by the `schema_filter`. All are tenant tables with row-level security; all but `ntf_delivery` go with their ticket (ON DELETE CASCADE); see the [data model](../../docs/02-architecture/data-model.md#notification-assist-integration-audit-agentapi).

See the [module catalogue](../../docs/02-architecture/modules.md#notification-ntf_).
