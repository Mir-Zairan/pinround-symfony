# Identity

Users and how they sign in: invites, passwords, two-factor login, Microsoft login and API tokens. It owns the tables `idn_user`, `idn_credential`, `idn_password_reset`, `idn_mfa_secret`, `idn_sso_identity`, `idn_api_token` and `idn_login_attempt`. Sessions live in Valkey. Its Api is `CurrentUser` (built: the logged-in user of the request or of a security token, `Infrastructure\Security\SymfonyCurrentUser`), `UserQueries`, `TokenAuthenticator` and `ApiTokens`. It may use the Api of SharedKernel only.

See the [module catalogue](../../docs/02-architecture/modules.md#identity-idn_).
