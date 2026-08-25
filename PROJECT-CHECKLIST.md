# PharmaSure Development Checklist

Last updated: 2026-08-23

This is the working source of truth for implementation status. An item is checked only when working code exists and the relevant automated or runtime verification has passed. Broad modules remain unchecked until their remaining child items are complete.

## 1. Foundation and local runtime

- [x] Docker development stack with WordPress, MySQL, Nginx, Redis, MailHog and phpMyAdmin
- [x] WordPress Multisite installation
- [x] WordPress runtime and database aligned to version 7.0.1
- [x] PharmaSure MySQL exposed on host port 3307 so XAMPP can retain port 3306
- [x] Core database migration framework
- [x] Canonical core, tenancy, licensing, inventory, clinical and print tables installed
- [x] PharmaSure plugin PHP syntax checks
- [ ] Fix Windows-mounted MySQL configuration permissions so `my.cnf` is not ignored
- [ ] Remove duplicate PHP extension loading warnings
- [ ] Add automated clean-install and upgrade tests from an empty database
- [ ] Add backup and restore rehearsal

## 2. Tenant and platform administration

- [x] Network Admin PharmaSure dashboard
- [x] Network Admin tenant list
- [x] Server-side tenant onboarding form
- [x] Default branch creation during onboarding
- [x] Owner user and active membership creation
- [x] Tenant listing, search and updates aligned to the canonical schema
- [x] Branch ownership checks
- [x] Tenant isolation integration coverage
- [ ] Tenant detail and edit screen
- [ ] Tenant suspend, reactivate and archive actions
- [ ] Branch create, edit and deactivate screens
- [ ] User invitation, role and branch-assignment screens
- [ ] Tenant/branch context selector in the application UI
- [ ] Tenant branding and printer-profile settings
- [ ] Tenant data export and retention workflow

## 3. Licensing

- [x] Canonical licence, entitlement, quota, signing-key and revocation schema usage
- [x] Database licence validation
- [x] Entitlement and quota loading
- [x] RS256 offline token issuance and verification
- [x] Cross-tenant token rejection
- [x] Licensing integration test fixtures clean themselves up
- [ ] Product, plan, price and entitlement administration UI
- [ ] Licence creation, suspension, renewal and revocation UI
- [ ] Signing-key provisioning and rotation workflow
- [ ] Subscription and billing persistence aligned to the canonical schema
- [ ] Grace-period and expiry transition scheduler
- [ ] Enforce licence gates consistently in every module

## 4. Inventory

- [x] Tenant-scoped drug catalogue service
- [x] Tenant-scoped supplier service
- [x] Atomic stock receipt workflow
- [x] Batch, stock balance and immutable movement creation
- [x] Expired-stock receipt rejection
- [x] Cross-tenant supplier rejection
- [x] Inventory REST routes and capability checks
- [x] Basic Inventory admin page
- [x] Goods-received-note browser print document
- [ ] Drug and supplier edit/import screens
- [x] Transaction-safe FEFO stock allocation and consumption service
- [ ] Stock adjustments with approval thresholds
- [ ] Branch transfers and discrepancy workflow
- [ ] Stock takes, recounts, variance approval and posting
- [ ] Reorder suggestions and purchase orders
- [ ] Expiry, low-stock and out-of-stock alerts
- [ ] Inventory exports and operational reports

## 5. Clinical and dispensing

- [x] Versioned patient, prescription, prescription-item and sale tables
- [x] Tenant/branch-scoped patient creation and search
- [x] Cross-tenant patient isolation
- [x] Atomic prescription and item creation
- [x] Draft to pending-review transition
- [x] Pharmacist approval transition
- [x] Approved prescription dispensing to a sale
- [x] Duplicate dispensing prevention
- [x] Clinical REST routes and capabilities
- [x] Clinical admin menu registration and access regression test
- [x] Medication-label browser print document
- [x] Dispensing-summary browser print document
- [ ] Full patient CRUD and patient-history UI
- [ ] Insurer/medical-aid membership fields and workflows
- [ ] Prescription list, detail, review, rejection and cancellation UI
- [ ] Partial dispensing and remaining-quantity tracking
- [x] FEFO batch allocation during dispensing
- [x] Atomic stock-ledger deduction during dispensing
- [ ] Controlled-drug authorization and register
- [ ] Allergy/contraindication data model and reviewed clinical-data integration
- [ ] Dispensing event and item tables from the full specification

## 6. Printing

- [x] Dedicated `pharmasure-print` plugin
- [x] Versioned printer-profile, template and print-job tables
- [x] Tenant-scoped print-job service
- [x] Browser print adapter
- [x] Medication labels
- [x] Dispensing summaries
- [x] Goods-received notes
- [x] Basic sale receipts
- [x] Visible REPRINT marking
- [x] Print/reprint audit events
- [x] Print REST routes
- [x] Printing admin job form, history and Print/Open action
- [x] Cross-tenant print denial tests
- [x] Printing integration suite: 19 passing checks
- [ ] Printer-profile CRUD and branch defaults
- [ ] Editable/versioned template administration
- [ ] PDF renderer and downloadable archival documents
- [ ] ESC/POS thermal-printer adapter
- [ ] ZPL medication-label adapter
- [ ] Background print worker, retry policy and failure UI
- [ ] Print preview with paper-size and margin controls
- [ ] Email/SMS document delivery with consent and delivery status
- [ ] Fiscal-device integration where legally required
- [ ] Validate output on selected receipt and label printer models

## 7. Point of sale

- [x] Till and till-session schema with open/close and cash reconciliation
- [x] Cashier cart, barcode/SKU search and server-authoritative pricing
- [x] Branch tax, permissioned discount and integer rounding rules
- [x] Held cart retrieval and cancellation (quotations remain pending)
- [x] Cash, card, mobile money, bank and medical-aid tender recording
- [x] Split payments with exact total reconciliation
- [x] Atomic sale, line, payment, FEFO stock and receipt posting
- [x] Sale item and payment tables
- [x] Partial refund, stock disposition, payment reversal and void workflows
- [ ] End-of-day cash-up and permissioned variance approval (implemented; runtime verification pending)
- [x] Complete itemized receipt printing with totals and tenders

## 8. Claims and insurers

- [ ] Insurer and scheme administration (implemented; runtime verification pending)
- [ ] Patient cover and authorization data (implemented; runtime verification pending)
- [ ] Claim preparation and validation (implemented; runtime verification pending)
- [ ] Submission adapter interface with explicit manual handoff (implemented; runtime verification pending)
- [ ] Claim status, remittance and reconciliation (implemented; runtime verification pending)
- [ ] Rejection and write-off workflow (implemented; runtime verification pending)
- [ ] Claim forms and remittance browser printing (implemented; runtime verification pending)

## 9. Reporting and exports

- [ ] Tenant/branch operational dashboard and admin UI (implemented; runtime verification pending)
- [ ] Sales, tender and historical-margin reports (implemented; runtime verification pending)
- [ ] Immutable receipt, sale, refund and return cost ledger with conservative legacy backfill (implemented; runtime verification pending)
- [ ] Inventory valuation, near-expiry and stock-movement reports (implemented; runtime verification pending)
- [ ] Clinical and dispensing reports (implemented; runtime verification pending)
- [ ] Audit and security reports (implemented; runtime verification pending)
- [ ] CSV export with spreadsheet-injection protection (implemented; runtime verification pending)
- [ ] XLSX export (implemented; runtime verification pending, requires PHP Zip)
- [ ] PDF export (implemented; runtime verification pending)
- [ ] Scheduled reports, delivery and run history (implemented; runtime/mail verification pending)
- [ ] Print views whose totals match source transactions

## 10. Security, audit and compliance

- [x] Server-derived tenant context foundation
- [x] Tenant-scoped inventory, clinical, licensing and print integration tests
- [x] WordPress capability foundation
- [x] Implement one canonical audit persistence handler for all `pharmasure_audit_log` events
- [ ] Review every REST object lookup for guessed-ID and cross-tenant access
- [x] Enforce active branch membership at tenancy, inventory, clinical and printing request boundaries
- [ ] Add nonce/CSRF, authorization and negative-path tests for every admin action
- [ ] Sensitive-field redaction in audit events
- [ ] Session/device administration and revocation (offline device administration implemented; broader session controls pending)
- [ ] Rate limiting and security-event alerting (tenant/user/IP-aware REST budgets, request-size limits and offline escalation implemented; load calibration pending)
- [ ] Privacy export, correction, anonymization and retention workflows
- [ ] Jurisdiction-specific legal review for patient records, controlled medicines, tax and fiscal receipts

## 11. Integrations and offline operation

- [ ] Payment gateway adapter contract and idempotent signed callbacks (foundation implemented; provider adapter/runtime verification pending)
- [ ] WordPress email adapter with retryable delivery (implemented; runtime verification pending)
- [ ] SMS adapter contract (provider adapter pending)
- [ ] Accounting integration contract (provider adapter pending)
- [ ] Insurer API adapter contract (provider adapter pending)
- [ ] Signed webhook delivery, exponential retry and dead-letter handling (implemented; runtime verification pending)
- [ ] PWA static-asset cache and safety-focused offline shell (implemented; browser verification pending)
- [ ] Device registration, expiry, revocation and signed scoped credentials (implemented; runtime verification pending)
- [ ] Offline mutation intake, snapshots, authoritative replay and explicit conflict states (implemented; runtime verification pending)
- [ ] Safe deduplication and authoritative service replay for stock, dispensing and sales; payment remains provider-gated (implemented; runtime verification pending)
- [ ] Manager conflict queue, mutation detail, reason-gated discard/rebase, replay monitoring and device administration (implemented; runtime verification pending)
- [ ] Escalating security alerts for repeated offline signature, nonce-replay and mutation-replay failures (implemented; runtime verification pending)

## 12. User interface and accessibility

- [x] PharmaSure portal theme foundation
- [x] Network Admin dashboard and tenant pages
- [x] Basic Inventory, Clinical and Printing admin entries
- [ ] Unified pharmacy application shell (shared capability-aware admin shell implemented; browser verification pending)
- [ ] Responsive operational forms and data tables (shared responsive treatment implemented; screen-by-screen verification pending)
- [ ] Dashboard shortcuts and branch selector (module shortcuts and persistent authorized branch selector implemented; dashboard metrics pending)
- [ ] Empty, loading, success and error states throughout (shared empty-table and notice announcement treatment implemented; workflow-specific loading states pending)
- [ ] Keyboard-only workflow validation (native keyboard controls and focus/error handling remediated for POS, reporting and offline operations; browser traversal pending)
- [ ] Screen-reader labels and announcements (skip link, landmarks, responsive table labels and live notice announcements implemented; assistive-technology review pending)
- [ ] WCAG 2.2 AA review (shared focus, target-size, responsive-table, reduced-motion and naming remediation implemented; manual audit pending)
- [ ] Dedicated print styles in the portal theme

## 13. Automated verification

- [x] Tenant isolation: 12 passing checks
- [x] Licensing: 16 passing checks
- [x] Inventory: 7 passing checks
- [x] Tenancy onboarding: 8 passing checks
- [x] Clinical and FEFO dispensing: 27 passing checks
- [x] POS checkout, holds, refunds, cost ledger and voids: 47 passing checks
- [x] Claims and insurer lifecycle: 24 passing checks
- [x] Reporting totals, isolation, exports and scheduling: 18 passing checks
- [x] Integration encryption, idempotency and signatures: 14 passing checks
- [x] Offline device signing, revocation, replay and mutation conflicts: 12 passing checks
- [x] Authoritative offline replay, server references and version conflicts: 8 passing checks
- [x] Offline manager operations, isolation, immutable applied state and security escalation: 11 passing checks
- [x] Unified application shell, responsive tables, focus, reduced motion and live announcements: 11 passing checks
- [x] Screen-level accessibility and REST hardening: 15 passing checks
- [x] Printing: 19 passing checks
- [x] Admin menu registration: 7 passing checks
- [x] Canonical audit persistence and redaction: 7 passing checks
- [x] Branch membership authorization: 8 passing checks
- [x] Runtime schema, module activation and upgrade state: 23 passing checks
- [ ] Authenticated REST end-to-end suite
- [ ] Concurrent stock and dispensing tests
- [ ] Browser end-to-end tests for admin workflows
- [ ] Cross-browser print-layout tests
- [ ] Performance/load tests
- [ ] Clean install, migration upgrade and rollback tests

## 14. Documentation, packaging and release

- [x] Local setup and testing documentation exists
- [x] Individual plugin/theme ZIP packaging script
- [x] Existing PharmaSure plugin and theme archives
- [x] Added and verified `pharmasure-print.zip`
- [ ] Consolidate duplicate setup guides into one canonical README
- [ ] Update manifest and deployment documentation for Clinical and Printing
- [ ] Document database migrations and rollback procedures
- [ ] Document supported printer models and configuration
- [x] Rebuilt all plugin/theme release archives after the 2026-07-20 printing work
- [ ] Commit changes in logical, reviewable changesets
- [ ] Production readiness review and release sign-off

## Recommended next implementation order

1. Authenticated REST and audit/security coverage for the modules already implemented.
2. FEFO stock consumption integrated with clinical dispensing.
3. Point-of-sale foundation and itemized receipts.
4. Printer profiles/templates, followed by PDF and selected hardware adapters.
5. Claims and reporting.
6. Offline/sync, integrations, accessibility and production hardening.
