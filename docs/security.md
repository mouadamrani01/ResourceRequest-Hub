# Security review

## Protected data

The application stores names, email addresses, password hashes and research request descriptions. It is a demonstration project; do not store confidential research data in it.

## Controls implemented

- **SQL injection:** all data values are sent through PDO placeholders; emulated prepares are disabled.
- **Stored XSS:** user-provided text is escaped when rendered as HTML. `nl2br()` only receives escaped text.
- **CSRF:** every state-changing HTML form checks a per-session unpredictable token.
- **Session fixation:** successful login regenerates the session identifier.
- **Unauthorized access:** member requests, projects and discussions are scoped to their owner; admins may read and review all requests. Unauthorized details return 404.
- **Invalid state transitions and capacity:** transactions use row locks for the request and resource pool; approvals are limited to available capacity and releases restore it.
- **Repeated logins:** a database-backed bucket keyed by hashed email and IP limits repeated failures over 15 minutes.
- **Secrets:** credentials are read from an ignored local PHP file or environment variables. Apache uses `public/` as its document root.
- **HTTP response headers:** CSP, no-sniff, frame deny, referrer policy and no-cache behavior.

## Limits and deployment notes

- The fixed values in `compose.yaml` are *local development passwords*, not real deployment credentials.
- The login limiter is IP-scoped but has no distributed bot detection or additional account-level abuse monitoring.
- There is no password reset, MFA, SSO or account self-service. Account creation is administrator-only through CLI.
- No advanced audit retention, backup restoration or security event alerting is included.
- Secure cookies are enabled when PHP sees HTTPS. On a real reverse proxy, configure HTTPS termination and proxy trust explicitly rather than relying on the development settings.
- `GET /api/requests/{id}` includes contact information for people the caller is allowed to see. Use access policy and data minimization rules before exposing an API publicly.
- The application does not ship with a WAF, CSP reports, vulnerability scanning or third-party penetration testing.

**Intended environment:** a private local development machine or an isolated demonstration instance. A public deployment requires additional review and operational controls.
