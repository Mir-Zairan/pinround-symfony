# Notification

Pings, the bell, mutes and email digests. Pings are rate limited and clear by themselves when the person acts on the ticket. It owns the tables `ntf_ping`, `ntf_notification`, `ntf_mute` and `ntf_delivery`. Its Api is `PingCommands` and `NotificationQueries`. It may use the Api of Organization and Ticketing.

Its events, in `Api\Event`, are `PingSent` and `PingCleared` (`NotificationEvent`, ids only, through the outbox to `async_notify`); `Domain\QuietHours` decides whether a ping is held until 09:00.

See the [module catalogue](../../docs/02-architecture/modules.md#notification-ntf_).
