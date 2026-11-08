# Web

The UI shell: layout, navigation built from the user's roles, Home composition and the design-system Twig components. It has no business logic and only puts other modules' UI pieces together. It owns one table, `web_home_focus` (each user's Home focus chip per organization), and has no Api of its own. It may use the Api of Insight, Organization, Notification and Ticketing (read only).

See the [module catalogue](../../docs/02-architecture/modules.md#web-ui-shell).
