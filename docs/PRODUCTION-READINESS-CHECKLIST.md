# PharmaSure production-readiness checklist

Evidence date: 26 August 2026  
Evidence set: `artifacts/production-readiness/2026-08-26/`

## Release position

The application-level release gate is green. The reproducible integration run completed all 31 suites with 0 failed suites and 0 failed assertions. Browser evidence covers the branded login, every headless workspace, dark/light themes, tenant administration, receipt printing, XLSX download, staff admin redirect and secure logout.

This is not a claim of regulatory certification. Production deployment still requires the environment and governance controls listed at the end of this document.

## Created and verified

| Area | Delivered | Verification | Primary implementation |
|---|---|---|---|
| Headless shell | Standalone `/app` and nested routes, no WordPress presentation assets, command navigation, dark/light themes | Browser asset audit, launcher and accessibility suites | `pharmasure-core/src/AppLauncher.php`, `templates/app-shell.php`, `assets/js/app.js`, `assets/css/crisp-theme.css` |
| Authentication | Branded login, staff `/wp-admin` redirect, visible logout on every workspace, active-sale warning, nonce-safe logout URL | Browser login/logout round trip and launcher suite | `pharmasure-core/src/Admin/WhiteLabel.php`, `AppLauncher.php`, `assets/css/login.css` |
| Tenant provisioning | One guarded workflow creates directory record, site, schemas, first branch, exclusive owner, role, mapping, trial licence, entitlements and quotas; partial failures roll back | Temporary pharmacy created and removed; 18 provisioning assertions | `pharmasure-tenancy/src/Services/TenantProvisioningService.php` |
| Super-admin control plane | Platform overview, tenant directory, status/licence/owner posture and structured provisioning form with one-time handoff | Live network-admin browser capture; no tenant branch context in platform UI | `pharmasure-tenancy/src/Admin/TenantAdmin.php`, `assets/css/platform-admin.css` |
| Tenant and branch isolation | Server-derived tenant context, explicit branch authorization, session-specific owner branch selection, unique tenant identities | Isolation, branch-access, browser-session and demo-user suites | `pharmasure-core/src/TenantContext.php`, tenancy services |
| Inventory | Catalogue, batches, receipts, movements, low-stock, expiry, suppliers; receive, adjust, quarantine/release and transfer commands | Inventory service, command, workspace and headless suites | `pharmasure-inventory/`, headless client in `app.js` |
| Point of Sale | Till sessions, product finder, cart, discounts, split tenders, holds, checkout, refunds, voids and FEFO stock allocation | POS service and headless suites plus live checkout/void browser run | `pharmasure-pos/`, headless client in `app.js` |
| Printing | Protected `/app/print/*` route, 80 mm receipt, totals, discount/tax and tender evidence, reprint marking | 23 print assertions and captured thermal receipt | `pharmasure-print/src/Services/PrintService.php` |
| Clinical | Patients, prescriptions, safety review, counselling, controlled-drug attestation and dispensing | Clinical and headless Clinical suites | `pharmasure-clinical/`, headless client in `app.js` |
| Claims | Insurer/scheme/cover records, claim preparation, submission, adjudication, remittance and write-off controls | Claims and headless Claims suites | `pharmasure-claims/`, headless client in `app.js` |
| Reports | Operational dashboards, inventory/dispensing/claims/audit/security/margin analysis, CSV/XLSX/PDF and scheduled delivery | 31 reporting assertions plus validated browser XLSX download | `pharmasure-reporting/`, headless client in `app.js` |
| Accounts | Tenant-only account directory, roles, active status, explicit branch assignments and audited self-service password replacement; cross-pharmacy email reuse fails closed | Accounts isolation/password tests and browser dialog captures | `pharmasure-tenancy/src/Services/AccountService.php`, `src/Rest/AccountController.php`, headless client in `app.js` |
| Offline operations | Signed devices, mutation/conflict queue, details, replay/rebase/discard, device revocation, replay monitor and escalated security alerts | Four Offline suites and live workspace capture | `pharmasure-offline/`, headless client in `app.js` |
| Hardening | Prepared/scoped queries, entitlements/quotas, immutable audit, request size/rate limiting, no-store headers, keyboard/focus/reduced-motion support | Architecture, audit, licensing, accessibility and schema suites | Core hardening services and module services |

## Automated gate

- Run: `powershell -File scripts/run-production-readiness.ps1`
- Result: 31/31 suites passed.
- Assertion result: 550 passed, 0 failed.
- Machine summary: `artifacts/production-readiness/2026-08-26/00-automated-test-summary.json`
- Full transcript: `artifacts/production-readiness/2026-08-26/00-automated-test-transcript.txt`

## Browser checks

- [x] `/app` is edge-to-edge and has no admin bar or WordPress admin styles.
- [x] Dark and light themes render across operational workspaces.
- [x] GreenLife owner sees only GreenLife and can use two branches without cross-browser branch collision.
- [x] Sunrise owner belongs only to Sunrise and cannot access GreenLife.
- [x] Staff `/wp-admin` access redirects to `/app`.
- [x] POS sale can be completed, printed and safely voided.
- [x] Thermal receipt contains pharmacy, branch, transaction, item, total and tender evidence.
- [x] XLSX downloads in-browser and is a valid Open XML workbook with Summary and Data worksheets.
- [x] Accounts and Offline administration render without tenant identifiers or secrets.
- [x] Super-admin sees the platform tenant directory and clean provisioning workflow.
- [x] Logout ends the session at the branded login page.

## Deployment controls still required

- [ ] Terminate TLS with production certificates and force HTTPS/HSTS.
- [ ] Replace all demo credentials and rotate WordPress salts, encryption keys and RS256 licence keys.
- [ ] Set `WP_DEBUG=false`, disable public database tooling and restrict network administration by identity-aware access controls.
- [ ] Configure encrypted database/object-storage backups and perform a witnessed restore test.
- [ ] Configure production SMTP, scheduled-report delivery credentials and delivery-failure alerting.
- [ ] Connect centralized logs, metrics, uptime checks, security-event paging and retention policies.
- [ ] Complete vulnerability scanning, dependency/SBOM review and independent penetration testing.
- [ ] Define RPO/RTO, incident response, breach notification, access-review and account-offboarding procedures.
- [ ] Complete applicable privacy, pharmacy, health-record and payment compliance review for each deployment country.
- [ ] Run performance/load tests using expected catalogue, branch, transaction and concurrent-user volumes.
- [ ] Validate supported receipt printers, barcode scanners, label printers and browser/device combinations on-site.

## Release decision

Application gate: **ready for stakeholder review and staging acceptance**.  
Public production go-live: **conditional on every deployment control above being signed off by its named owner**.
