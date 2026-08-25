# PharmaSure Runtime Verification

Verified: 2026-08-24  
Environment: Docker Desktop, WordPress Multisite, PHP 8.2.32, MySQL 8.0.46, Redis 7 and Nginx.

## Result

- All application containers are running and healthy.
- All completed PharmaSure modules are network active.
- All 19 runtime suites pass: **294 assertions, 0 failures**.
- The live REST namespace rejects unauthenticated reporting access with HTTP 403.
- PharmaSure REST responses include `Cache-Control: no-store, private`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer` and `X-Frame-Options: DENY`.
- Software version disclosure was removed from Nginx and PHP responses.
- Xdebug is disabled by default; developers can enable it explicitly through their local environment.

## Issues found and corrected

1. POS activation exposed malformed repeat-upgrade SQL caused by compact `dbDelta` definitions.
2. A shared SQL normalizer now gives `dbDelta` one column/index definition per line without splitting composite indexes or decimal declarations.
3. POS schema version 7 repaired and verified sales columns and indexes on the existing database.
4. Audit event names now retain namespace separators and derive stable action suffixes.
5. Tenant-isolation tests now establish and restore the authenticated user context correctly.
6. Expected offline nonce replays use an atomic `INSERT IGNORE`, preventing false database-error noise while retaining replay rejection and security telemetry.
7. HSTS was removed from the local plain-HTTP listener; it must be configured only at the production TLS listener or edge proxy.

## Verified suites

Accessibility/hardening, admin navigation, application shell, audit, branch authorization, claims, clinical/FEFO, integrations, inventory, tenant isolation, licensing, offline device security, offline manager operations, authoritative offline replay, POS/refunds/voids, printing, reporting, runtime schema and tenancy onboarding.

## Remaining release gates

- Authenticated REST end-to-end tests using real session/nonces and role matrices.
- Browser automation for POS, clinical, claims, reports and offline manager workflows.
- Manual keyboard, screen-reader and WCAG 2.2 AA review.
- Concurrent stock/dispensing/checkout contention tests.
- Load testing and rate-limit calibration using expected branch/device traffic.
- Clean-volume installation, upgrade-from-release and rollback rehearsal.
- Database and uploads backup/restore rehearsal with recovery-time evidence.
- Production TLS/edge configuration, secrets rotation, monitoring and alert delivery verification.
- WP-CLI image currently uses a MariaDB client that cannot authenticate to MySQL 8 `caching_sha2_password` for `wp db query`; WordPress-backed `wp eval-file` tests work correctly, but the CLI image should be upgraded.
- MySQL reports `/etc/mysql/conf.d/my.cnf` as world-writable and ignores it; host/container file permissions must be corrected before relying on those settings.

## Local endpoints

- Application: `http://localhost:8080`
- phpMyAdmin: `http://localhost:8081`
- MailHog: `http://localhost:8025`
- MySQL: `localhost:3307`
- Redis: `localhost:6379`

The Docker stack was intentionally left running for browser verification.
