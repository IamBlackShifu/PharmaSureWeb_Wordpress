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
- Browser automation for claims, reports and offline manager workflows.
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

## Headless Inventory verification - 2026-08-25

- `/app/inventory` and the receiving workflow were rendered in Chromium with no WordPress administrative assets.
- Both demo tenants were upgraded and seeded with explicit Inventory entitlements; GreenLife retained two active branches for transfer testing.
- Existing populated receipt-line tables were upgraded through a staged tenant backfill and verified with non-null tenant/status/created columns and composite tenant indexes.
- Transactional command verification passed **20 assertions** and covered receipt lines, adjustments, cross-tenant denial, quarantine/release accounting, FEFO transfers, archival safety, REST registration and plan quota presence.
- Headless Inventory verification passed **34 assertions**; accessibility/hardening (15), architecture (7), app launcher (20) and licensing (19) also passed without failures.
- Rendered evidence is stored under `artifacts/screenshots/headless-inventory-*.png`.

## Headless Point of Sale verification - 2026-08-26

- `/app/pos` rendered in authenticated Chromium with no WordPress admin bar or administrative assets and redirected pharmacy-staff `/wp-admin` access back to `/app`.
- The seeded GreenLife CBD branch exposed its authorized till session and three branch-stock products; prescription-only stock was visibly routed to the Clinical workflow.
- Browser interaction added an eligible product to the cart, reconciled the cash tender exactly and enabled checkout in both dark and light clinical themes without persisting a test sale.
- POS verification passed **49 service assertions** and **21 headless assertions**; launcher (20), application shell (11), accessibility/hardening (15) and headless Inventory (34) also remained green.
- Held-cart resume now completes the original hold atomically with checkout, preventing a stale duplicate cart; payment rows persist the tenant's validated ISO currency.
- Receipt handoff now reserves its browser window before asynchronous job creation, uses a nonce-protected tenant `/app/print/{job}` route, and renders a dedicated 80 mm thermal document with branch, cashier, till, customer, status, item, total and tender evidence.
- Live Chromium checkout rendered receipt `R000004` through the headless route and then voided the verification sale, restoring its exact stock allocation. Print verification passes **23/23** and headless POS verification passes **22/22**.
- Inventory migration DDL was expanded to one field per line so WordPress `dbDelta` no longer misreads receipt/transfer status declarations as date defaults.
- Rendered evidence is stored at `artifacts/screenshots/headless-pos-workspace.png`, `headless-pos-active-cart.png`, `headless-pos-active-cart-light.png` and `headless-pos-thermal-receipt.png`.

## Headless Clinical verification - 2026-08-26

- `/app/clinical` rendered in authenticated Chromium as a standalone patient-safety and dispensing workspace with no WordPress administrative presentation.
- GreenLife was seeded with a branch-scoped patient safety profile, active payer cover, one pending-review prescription and one approved prescription ready for dispensing.
- Prescription creation resolves medicine identity, control status and price from the tenant catalogue; pharmacist review requires allergy, interaction and dose checks plus a rationale.
- Dispensing requires recorded counselling, consumes branch stock using FEFO, snapshots sale value and batch cost, and is idempotent. Controlled items additionally require an authorized second-user witness and immutable register entry.
- Clinical/FEFO verification passed **27 assertions** and headless Clinical verification passed **26 assertions**. POS (21), Inventory (34), Claims (24), Print (19), launcher (20) and accessibility/hardening (15) remained green.
- Rendered evidence is stored at `artifacts/screenshots/headless-clinical-workspace.png`, `headless-clinical-review.png` and `headless-clinical-workspace-light.png`.

## Headless Claims verification - 2026-08-26

- `/app/claims` rendered in authenticated Chromium as a standalone revenue-cycle workspace with no WordPress administrative presentation.
- The GreenLife fixture exposes a branch-scoped draft claim with authoritative item value, member cover, scheme context and its immutable preparation event; an additional covered sale is visible for claim preparation.
- The workspace supports validation, rejection/query correction, manual submission handoff, payer-reference evidence, adjudication, partial approval, remittance allocation, reconciliation and reasoned write-off controls.
- Remittance requests now inject the authorized branch server-side and cannot allocate a payment to another branch's approved claim.
- Claims lifecycle verification passes **24/24** and headless Claims verification passes **18/18**. PHP/JavaScript syntax and Git whitespace checks remain clean.
- Rendered evidence is stored at `artifacts/screenshots/headless-claims-workspace.png` and `headless-claims-workspace-light.png`.

## Headless Reports and Accounts verification - 2026-08-26

- `/app/reports` rendered in authenticated Chromium with sales, historical margin, tender, inventory, stock movement, dispensing, claims, audit and security registers; no WordPress administrative assets or toolbar were present.
- Report periods, CSV/XLSX/PDF controls and branch-scoped scheduled delivery administration render in both dark and light themes. Schedule creation and status changes emit tenant audit events, while audit/security views require the separate audit capability.
- Historical margin continues to use immutable sale-time and refund-time cost snapshots. Clinical reporting joins prescription lines using prescription, tenant and branch keys.
- `/app/account` lists only memberships resolved from the authenticated tenant. Account creation validates every branch against that tenant, rejects an email already owned by another pharmacy identity, and protects the current session and last active owner.
- Reporting verification passes **18/18** and the headless Reports/Accounts isolation suite passes **19/19**. The isolation suite explicitly proves Sunrise's owner is absent from GreenLife, cross-pharmacy email reuse fails closed, and report schedules/account seats enforce licensed quotas.
- The refreshed `/wp-login.php` surface uses PharmaSure branding and clinical tokens, with no WordPress logo or administrative styling dependency.
- Rendered evidence is stored at `artifacts/screenshots/headless-reports-workspace.png`, `headless-reports-workspace-light.png`, `headless-accounts-workspace.png`, `headless-account-create.png` and `pharmasure-login.png`.

## Browser-specific branch context verification - 2026-08-26

- A missing branch after owner login was traced to the absence of an authorized default fallback; the previous implementation restored only one global user-meta branch ID.
- New login sessions now resolve the tenant's default active authorized branch automatically. Owner all-branch access and explicit operational assignments remain enforced by `TenantContext::can_access_branch()`.
- Branch selection is stored per WordPress login-token digest and tenant. Two browsers can therefore retain different GreenLife branches while tabs sharing one authenticated browser session intentionally share a working branch.
- Verification passes browser-session context **7/7**, branch authorization **8/8**, tenant isolation **12/12** and application launcher **20/20**.
