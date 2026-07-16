# PharmaSure WordPress Rebuild Blueprint

## 1. Purpose and scope

This document is the implementation contract for rebuilding PharmaSure as a WordPress product. It covers the behavior found in the React/Tauri desktop application, its Rust/SQLite backend, the Supabase cloud schema and row-level security, the separate React web-admin portal, and the repository's planning documents.

The rebuild must preserve the intended pharmacy system while adding production-grade multitenancy, subscription/licence control, and comprehensive printing. This is a specification, not a claim that every feature in the current repository is production complete.

Status labels used below:

- **Implemented**: working code paths and UI exist in the repository.
- **Partial**: schema, service, UI, or documentation exists, but the end-to-end workflow is incomplete, duplicated, simulated, or inconsistent.
- **Proposed**: required for a safe WordPress/SaaS implementation but not fully present in the current product.

## 2. Product definition

PharmaSure is a multi-branch pharmacy management platform for daily dispensing and retail operations. Its actors are pharmacy cashiers, pharmacists, managers, tenant administrators, and platform super-administrators.

The intended product includes:

1. Inventory and batch/expiry management.
2. Point of sale with split tenders, discounts, tax, held sales, barcode lookup, and receipts.
3. Patient records and insurer membership details.
4. Prescription capture, review, approval/rejection, dispensing, and conversion to a sale.
5. Medical-aid/insurance claim capture and status tracking.
6. Dashboards, operational reports, exports, and end-of-day reporting.
7. Users, roles, branches, sessions, audit logs, and security settings.
8. Tenant onboarding, suspension/reactivation, billing, plan pricing, notifications, and cloud sync administration.
9. Offline-first operation in the existing desktop architecture.
10. Printing of receipts, dispensing labels, reports, stock documents, and administrative documents.

## 3. Repository audit summary

### 3.1 Application layers

| Layer | Current technology | Responsibility | WordPress destination |
|---|---|---|---|
| Pharmacy UI | React, TypeScript, Vite, Tailwind | Operational screens | Admin SPA or WordPress application pages supplied by plugins |
| Desktop shell | Tauri | Native packaging, filesystem, dialogs | Optional companion/PWA; not replaced by a theme alone |
| Local backend | Rust and SQLite | Commands, transactions, offline data | PHP domain services and custom MySQL tables; optional offline client |
| Cloud data | Supabase/PostgreSQL | Central tenant data and RLS | WordPress network database or external service |
| Platform admin | Separate React web app | Tenants, pricing, settings, sync | Network Admin screens in a platform plugin |
| Website presentation | Not a major current concern | Marketing/login shell | PharmaSure theme |

### 3.2 Authoritative source areas

- `src/pages`: pharmacy screens for dashboard, inventory, patients, prescriptions, POS, claims, reports, settings, and users.
- `src/components`, `src/store`, `src/services`: layout, branch selection, authentication, payments, validation, cloud/offline synchronization.
- `src-tauri/src/services`: business operations for inventory, POS, prescriptions, patients, sales, claims, reports, users, printing, currency, stock take, audit, security, enterprise, and super-admin behavior.
- `src-tauri/src/database.rs`: SQLite initialization, migrations, seed data, and sync queues.
- `supabase/migrations`: central multi-tenant schema and row-level policies.
- `web-admin`: cloud/platform administrator application, tenant provisioning, pricing, cloud sync, settings, and analytics.
- `database` and `scripts`: intermediate multitenancy, super-user, pricing, and migration artifacts.

### 3.3 Important audit findings

- The system already intends tenant and branch isolation. Both local and cloud models contain tenant/branch keys.
- There are competing or historical schemas. The WordPress build must establish one canonical schema and migration history.
- The desktop app deliberately removed super-admin routes in favor of the separate web-admin portal, although an older `SuperAdminDashboard` component remains.
- Several advanced services—stock take, enhanced POS, enterprise functions, and security—exist but are not consistently exposed through the current primary UI.
- Some communications and printing functions are placeholders or simulation-oriented. They must be replaced with real adapters.
- Existing documentation reports high completion percentages, but code-level acceptance tests must be the source of truth.
- Secrets and credentials must never be migrated from `.env`, setup scripts, or credential notes into source control or browser-delivered code.

## 4. Required WordPress product packaging

### 4.1 Theme: `pharmasure-portal`

The theme owns presentation only:

- Marketing site, product/pricing pages, documentation, support, and legal pages.
- Branded login, password-reset, tenant onboarding, licence-expired, maintenance, and access-denied templates.
- Application shell styling and print styles.
- Header, footer, accessibility, responsive layout, localization, and tenant-selectable branding variables.
- No inventory, patient, sale, licence, or tenant business logic.

Use a block theme with `theme.json`, patterns, and child-theme-safe tokens. Domain plugins must continue to function if the theme changes.

### 4.2 Required plugins

| Plugin | Responsibility |
|---|---|
| `pharmasure-core` | Shared bootstrap, database versioning, capabilities, tenant context, money/quantity primitives, REST conventions, jobs, events, audit foundation |
| `pharmasure-inventory` | Drug catalogue, suppliers, batches, stock ledger, receipts, transfers, adjustments, stock takes, expiry and reorder alerts |
| `pharmasure-pos` | Till sessions, cart, quotation/held sale, sale transaction, split payment, refund/void, receipt, end-of-day close |
| `pharmasure-clinical` | Patients, insurers, prescriptions, validation, pharmacist review, dispensing, medication labels |
| `pharmasure-claims` | Insurer schemes, claim preparation/submission/status/remittance and reconciliation |
| `pharmasure-reporting` | Dashboards, scheduled reports, CSV/XLSX/PDF exports and print views |
| `pharmasure-tenancy` | Tenant and branch lifecycle, context switching, isolation enforcement, branding, quotas |
| `pharmasure-licensing` | Products, plans, entitlements, licences, activations, billing state, grace periods, usage metering |
| `pharmasure-print` | Printer profiles, templates, print jobs, browser/PDF/ESC-POS/ZPL adapters, retries |
| `pharmasure-integrations` | Payment gateways, email/SMS, accounting, insurer APIs, webhooks, optional Supabase migration bridge |
| `pharmasure-platform-admin` | Network/platform dashboard, onboarding, suspension, pricing, notifications, support impersonation controls |
| `pharmasure-offline` (optional) | PWA cache, IndexedDB operation queue, conflict resolution, device registration and sync |

Plugins may be shipped as one commercial suite initially, but the internal module boundaries and capability checks must remain.

## 5. Functional requirements

### 5.1 Authentication, users, and authorization

Current evidence: **Implemented/Partial**.

Required behavior:

- Username/email login, logout, password change and reset.
- WordPress user identities mapped to one or more tenant memberships and permitted branches.
- Session inactivity expiry, explicit revocation, device/session list, and optional concurrent-session limits.
- Strong-password rules, login throttling, lockout notifications, and optional TOTP/WebAuthn MFA.
- Roles are templates; capabilities are the actual enforcement mechanism.
- Default roles: platform super admin, tenant owner, tenant admin, branch manager, pharmacist, pharmacy technician, cashier, inventory clerk, claims officer, auditor/read-only.
- A user may have different roles per tenant/branch.
- Privileged actions require reauthentication where appropriate.
- Support impersonation must require reason, time limit, visible banner, and immutable audit entries.

Minimum capability groups:

- Tenant: view/manage tenant, branches, branding, billing, integrations.
- User: view/create/edit/disable users, assign roles/branches, view audit.
- Inventory: view catalogue, manage catalogue, receive, transfer, adjust, count, approve variance.
- Clinical: view patients, manage patients, create/review/reject/dispense prescriptions, authorize controlled override.
- POS: open till, sell, discount, hold/retrieve, void, refund, close till.
- Claims: prepare, submit, reconcile, reject/write off.
- Reports: operational, financial, clinical, export, scheduled delivery.
- Print: print/reprint receipts, labels, reports; manage templates/printers.

### 5.2 Tenant and branch lifecycle

Current evidence: **Partial** across enterprise Rust services, Supabase, scripts, and web-admin.

Tenant workflow:

1. Platform admin or self-service customer starts onboarding.
2. Capture legal/trading name, slug, contacts, address, country/timezone, tax/currency settings, plan and billing cycle.
3. Create tenant owner and default main branch atomically.
4. Issue trial/subscription licence and entitlements.
5. Send verification/onboarding message.
6. Record all provisioning actions and failures.
7. Allow additional branches within entitlement limits.
8. Suspend access without deleting pharmacy data; permit billing/admin access as policy allows.
9. Reactivate, archive, export, and eventually purge under a documented retention process.

Branch records include name, code, location/address, phone/email, manager, timezone, receipt header/footer, tax registration, default currency, stock location, active state, and printer profiles.

Tenant isolation rules:

- Every domain row contains a non-null `tenant_id`; branch-scoped rows also contain `branch_id`.
- Tenant context is derived server-side from authenticated membership and route/site context. Never trust a posted tenant ID.
- Every repository query includes tenant scope; branch scope is applied where required.
- Cross-tenant joins, caches, object IDs, exports, media, logs, background jobs, and search indexes are scoped.
- Unique keys are composite where tenant ownership matters, e.g. `(tenant_id, sku)` and `(branch_id, receipt_number)`.
- Super-admin access is a separate capability and always audited.

### 5.3 Dashboard

Current evidence: **Implemented** for operational summaries; **Partial** for cloud/platform analytics.

Tenant/branch dashboard:

- Today's sales value and count, gross profit if permitted, average basket, pending prescriptions, pending claims.
- Low-stock, out-of-stock, near-expiry and expired batch counts.
- Recent sales and stock activity.
- Top-selling drugs and category trends.
- Date range, branch, and currency filters.
- Action shortcuts to sale, stock receipt, patient, prescription, stock take, and reports.

Platform dashboard:

- Total/active/trial/suspended tenants, active users/devices/branches.
- Monthly recurring revenue, annualized revenue, churn, plan distribution, licence expiry, payment arrears.
- Sync health, failed jobs, storage/transaction usage, security alerts, and recent admin activities.

### 5.4 Drug catalogue and inventory

Current evidence: **Implemented** for core catalogue/batches/receiving; **Partial** for advanced stock workflows.

Drug master fields:

- SKU/internal code, barcode(s), brand/trade name, generic name, strength, dosage form, pack size, unit of measure, category, manufacturer.
- Prescription requirement, controlled-drug flag/schedule, tax class, active state.
- Default cost and retail price, minimum/reorder level, maximum level, preferred supplier.
- Storage instructions, notes, image, created/updated metadata.

Inventory requirements:

- Supplier CRUD with contact, tax and payment details.
- Batch/lot number, supplier, purchase reference, received date, manufacture date, expiry date, quantity received, quantity available, unit cost, selling price, and location.
- Immutable stock ledger for receipts, sales, dispensing, returns, transfers, adjustments, damages, expiries and stock-count variances.
- FEFO allocation by default; block expired lots; configurable near-expiry warning.
- Receive stock with purchase/delivery reference and printable goods-received note.
- Branch transfer request, dispatch, in-transit, receipt, discrepancy and cancellation states.
- Stock take: draft, freeze/snapshot, count, recount, variance approval, posting, completion and report.
- Adjustment reason codes and approval thresholds.
- Reorder suggestions and optional purchase-order workflow.
- Search/filter/pagination by drug, generic, category, SKU, barcode, batch, supplier, stock state and expiry.
- CSV import/export with validation preview and per-row error report.
- Inventory valuation using a declared method (weighted average or batch/FIFO); do not mix methods silently.

### 5.5 Patient management

Current evidence: **Implemented** CRUD; tenant/branch and privacy hardening is required.

- Patient number, full name, date of birth, sex/gender where legally appropriate, phone, email and address.
- Insurer/medical-aid provider, scheme, member number, dependent code, validity dates and authorization notes.
- Allergy, contraindication and clinical-note fields with stricter clinical capabilities.
- Search by name, phone, patient number or membership number.
- Patient history: prescriptions, dispensing, sales/claims linked to the patient, with role-based financial visibility.
- Duplicate detection and controlled merge with audit trail.
- Consent, data export, correction, retention and anonymization workflows appropriate to applicable health/privacy law.

### 5.6 Prescriptions and dispensing

Current evidence: **Implemented** basic workflow and conversion to sale; safety controls are **Partial**.

Prescription fields:

- Patient, prescriber name and registration number, prescription date/reference, source, notes, attachment, branch and status.
- Items: prescribed drug/free-text drug, strength, dose, route, frequency, duration, quantity prescribed, repeats and instructions.

Workflow states:

`draft -> pending_review -> approved -> partially_dispensed -> dispensed`

Alternative terminal states: `rejected`, `cancelled`, `expired`.

Rules:

- Only authorized clinical roles approve or dispense.
- Validate quantity, stock, expiry, prescription requirement and duplicate dispensing.
- Select batch using FEFO while allowing an audited pharmacist override.
- Partial dispensing records remaining quantity and each dispensing event.
- Dispensing posts the stock ledger and, when charged, creates/links a POS sale in one database transaction.
- Rejection and override require reason.
- Print medicine labels and a dispensing summary.
- Controlled-drug support requires stronger authorization and a dedicated register if enabled by jurisdiction.
- Drug interaction/allergy checking requires a maintained clinical data source; never represent heuristic text matching as clinical validation.

### 5.7 Point of sale

Current evidence: **Implemented** core POS; some tender/communication adapters are **Partial**.

- Open/close till shift and cash float.
- Barcode scan and product search; display price, batch/expiry constraints and available quantity.
- Cart add/remove, quantity change, line/order discount with permission and reason, tax calculation, customer/patient association.
- Prescription enforcement and pharmacist override where policy permits.
- Cash, card, mobile money, bank transfer, account/medical aid and split payment tenders.
- Multi-currency display/payment with stored exchange-rate snapshot; base currency accounting remains consistent.
- Paynow/mobile-money integration through server-side signed requests, idempotent callbacks and reconciliation. Demo mode must be explicit.
- Hold/retrieve/cancel sales using reference numbers.
- Atomic sale completion: sale, lines, payments, stock movements, receipt sequence and audit event either all commit or all roll back.
- Printable/email/SMS receipt with consent and delivery status.
- Void before settlement; refund/return after settlement with original receipt, reason, approval, stock disposition and payment reversal.
- End-of-day report: expected vs actual tender totals, variance, cashier, till, opening/closing timestamps and approval.

### 5.8 Claims

Current evidence: **Implemented** basic create/list/status reporting; submission and remittance are **Partial/Proposed**.

- Link patient, insurer/scheme, prescription/sale and claim lines.
- Claim number and payer reference, service date, submitted/approved/rejected/paid amounts.
- States: draft, validated, submitted, acknowledged, queried, approved, partially_paid, paid, rejected, cancelled.
- Validation rules, authorization number and attachments.
- Manual submission initially; adapter interface for payer APIs/files.
- Response/remittance import, rejection codes, resubmission, reconciliation and write-off.
- Aging, outstanding value, rejection reason and insurer performance reports.

### 5.9 Reports and exports

Current evidence: **Implemented** sales, inventory, claims, top-selling and dashboard reports.

Required reports:

- Sales summary/detail by date, branch, cashier, category, drug and tender.
- Tax report, discount/void/refund report, cash-up/end-of-day report.
- Gross margin and cost-of-goods report with permission restrictions.
- Current stock, valuation, low/out-of-stock, batch ledger, near-expiry/expired, stock movement, adjustment, transfer and stock-take variance.
- Prescription workload/status, dispensing and controlled-drug register where enabled.
- Claims status, aging, rejection and remittance reconciliation.
- User activity, login/security and audit reports.
- Platform tenant, entitlement, licence expiry, billing and usage reports.

All reports need tenant/branch/date filters, timezone-correct boundaries, pagination, totals, CSV and PDF, print view, and an audit record for sensitive exports. Scheduled reports use background jobs and secure expiring links.

### 5.10 Settings

Current evidence: **Partial**, with several UI settings not consistently persisted.

- Pharmacy/legal identity, address, contacts, logo, tax numbers and receipt text.
- Branch settings, business hours, timezone, locale and document numbering.
- Currency and exchange rates; source, effective date and audit history.
- Tax/discount/rounding rules.
- Inventory thresholds, expiry window, costing and negative-stock policy.
- Prescription and override policy.
- Printers, label/receipt layouts and defaults.
- Notification events and delivery channels.
- Backup/export/retention controls.
- Security: password/MFA/session/lockout/audit settings.
- Integration credentials stored encrypted and never returned in full after save.

## 6. Multitenancy architecture

### 6.1 Recommended deployment: WordPress Multisite hybrid

Use WordPress Multisite for tenant-facing site separation and a network-activated platform suite. Each tenant receives a site for branding, pages and tenant administrators. High-volume transactional pharmacy tables should be global custom tables keyed by `tenant_id` and `branch_id`, rather than posts/postmeta duplicated per site.

Why this model:

- Network Admin naturally hosts platform operations.
- Each tenant can have an isolated domain/subdomain and theme configuration.
- Global tables make upgrades, aggregate platform metrics and licence enforcement manageable.
- Explicit row ownership remains enforceable and testable.

Alternative deployment modes:

- Single WordPress site with tenant resolved from hostname/path: simpler infrastructure, weaker native administrative separation.
- Separate WordPress installation/database per tenant: strongest isolation and easier customer-specific residency, but expensive upgrades and platform reporting.

The plugin API should use a tenant-context abstraction so enterprise customers can later use database-per-tenant without rewriting domain services.

### 6.2 Tenant resolution

Resolve tenant using the current Multisite blog/site mapping and authenticated membership. A request-scoped `TenantContext` contains tenant ID, branch ID, user membership, capabilities, timezone, currency, licence snapshot and correlation ID.

Rules:

- Network administrators must explicitly enter a tenant context for tenant data.
- Branch selection is stored per session/user but validated on every request.
- REST routes do not accept arbitrary tenant overrides.
- CLI and jobs must explicitly supply tenant context.
- Cache keys and transients start with tenant and branch identifiers.

### 6.3 Isolation defense in depth

1. Repository/service methods require `TenantContext`.
2. SQL includes tenant predicates and composite foreign keys where supported.
3. Authorization checks tenant membership and capability before service execution.
4. REST schemas reject unknown/read-only ownership fields.
5. Integration tests seed two tenants and attempt horizontal/vertical access violations.
6. Audit events contain actor tenant, target tenant, branch, object, action, result and correlation ID.
7. Backups and exports are generated per tenant and encrypted.

## 7. Licensing and commercial control

Current evidence: plan/pricing tables, history, tenant billing and licence validation/usage concepts are **Partial**.

### 7.1 Licence objects

- Product/SKU: the sellable PharmaSure suite or add-on.
- Plan/tier: Starter, Professional, Enterprise or configurable equivalents.
- Price: currency, interval, amount, effective dates and tax behavior.
- Entitlement: a named feature or quota granted by a plan.
- Subscription: customer billing agreement and provider references.
- Licence: signed authorization snapshot with start/end, status, grace period and entitlements.
- Activation/device: registered installation/browser/PWA device where device limits apply.
- Usage meter: branches, active users, devices, transactions, storage or API usage.

### 7.2 Entitlements

Examples: inventory, POS, prescriptions, claims, advanced reports, offline mode, API access, custom branding, multi-branch, accounting integration, priority support. Quotas include maximum branches, users, registers/devices and monthly transactions.

Feature checks happen server-side at the use case boundary. Hiding a menu is only presentation. A failed entitlement returns a stable machine code and an upgrade message without leaking data.

### 7.3 Licence states and behavior

`trial`, `active`, `past_due`, `grace`, `suspended`, `expired`, `cancelled`, `revoked`.

- Trial/active: entitled use.
- Past due/grace: warn owners; retain operations according to commercial/safety policy.
- Suspended/expired: default to read-only access and data export, while blocking new commercial transactions. Emergency dispensing policy must be decided with pharmacy/legal stakeholders.
- Revoked: immediate block for fraud/security, with platform-only recovery.
- Never delete customer data solely because a licence expires.

### 7.4 Validation and anti-tamper

- Hosted WordPress validates against its own authoritative database on each privileged request, with a short tenant-scoped cache.
- Offline clients receive a signed licence token with tenant, device, entitlements, issue/expiry timestamps and key ID.
- Sign using asymmetric keys; the client contains only the public key.
- Rotate signing keys and support revocation lists.
- Offline grace is finite and configurable. Sync refreshes the lease.
- Webhook handling is authenticated, idempotent, replay-resistant and auditable.
- Platform admins can issue, extend, suspend, revoke and inspect licences with reasons.

### 7.5 Billing integration

Keep billing provider logic behind an adapter. Store provider customer/subscription/invoice IDs, not raw card data. Support trials, coupons, plan changes, proration policy, invoices, payment failure, cancellation, renewal and manual/offline payments. Pricing changes create history and do not silently rewrite existing contracts.

## 8. Printing specification

Current evidence: Rust services generate dispensing labels and receipts; POS contains receipt print hooks. Actual platform printing needs production adapters.

### 8.1 Printable documents

- 58/80 mm POS receipt and A4 invoice.
- Prescription/dispensing receipt.
- Medicine label in configurable stock sizes.
- Barcode/shelf label and batch label.
- Quote, refund/credit note and reprint copy.
- Goods-received note, purchase order if enabled, stock transfer dispatch/receipt.
- Stock-take sheets and variance report.
- End-of-day/cash-up report.
- Sales, tax, inventory, expiry, claims and audit reports.
- Tenant licence/invoice documents from platform admin.

### 8.2 Output channels

1. Browser print with dedicated print routes and `@media print` CSS.
2. Server-generated PDF for archival/email and consistent A4 output.
3. ESC/POS for thermal receipts and cash drawer where a local bridge is installed.
4. ZPL/TSPL or vendor adapter for label printers.
5. CSV/XLSX for data exports; these are exports, not visual print artifacts.

Browsers cannot reliably perform silent raw printing. For one-click/silent thermal or label printing, use a signed local print-agent/desktop companion or an explicitly configured print service. Do not depend on insecure browser workarounds.

### 8.3 Print template requirements

- Versioned templates with tenant/branch overrides and safe defaults.
- Logo, legal name, branch, address, phone, tax number, document number, cashier/pharmacist, timestamps and currency.
- Labels support patient, drug/generic name, strength, dosage instructions, quantity, prescriber, dispense date, expiry/beyond-use instruction, warnings, pharmacy contacts and machine-readable code where appropriate.
- Preview with representative data; test page and calibration offsets.
- Page size, margins, font size, density, copies and destination printer profile.
- Escape untrusted text and restrict template variables; no arbitrary PHP execution.

### 8.4 Print jobs and audit

`queued -> rendering -> ready -> sending -> printed`, with `failed` and `cancelled` paths. Store document type, entity ID, template/version, actor, tenant/branch, printer, copies, timestamps, checksum, attempt count and error. Reprints display “COPY/REPRINT,” reason and original document number. Clinical and financial reprints require appropriate capability.

## 9. Canonical data model

Use custom tables with the WordPress database prefix. IDs may be unsigned big integers or UUID/ULID consistently. Store money as integer minor units plus ISO currency; quantities should use fixed precision where fractional units are possible. Store timestamps in UTC and render in branch timezone.

### 9.1 Platform and access tables

- `ps_tenants`
- `ps_branches`
- `ps_tenant_memberships`
- `ps_membership_branches`
- `ps_roles` / `ps_role_capabilities` only if WordPress roles are insufficient per membership
- `ps_user_sessions`
- `ps_security_events`
- `ps_audit_events`
- `ps_settings`
- `ps_notifications`

### 9.2 Commercial tables

- `ps_products`, `ps_plans`, `ps_prices`
- `ps_entitlements`, `ps_plan_entitlements`
- `ps_subscriptions`, `ps_invoices`, `ps_payments`
- `ps_licences`, `ps_licence_activations`, `ps_usage_meters`
- `ps_pricing_history`, `ps_webhook_events`

### 9.3 Inventory tables

- `ps_drugs`, `ps_drug_barcodes`, `ps_categories`, `ps_suppliers`
- `ps_batches`, `ps_stock_locations`
- `ps_stock_movements` as immutable source of truth
- `ps_stock_balances` as transactional projection/cache
- `ps_stock_receipts`, `ps_stock_receipt_lines`
- `ps_stock_transfers`, `ps_stock_transfer_lines`
- `ps_stock_takes`, `ps_stock_take_items`
- optional `ps_purchase_orders`, `ps_purchase_order_lines`

### 9.4 Clinical and sales tables

- `ps_patients`, `ps_insurers`, `ps_patient_cover`
- `ps_prescriptions`, `ps_prescription_items`, `ps_dispensing_events`, `ps_dispensing_items`
- `ps_tills`, `ps_till_sessions`
- `ps_sales`, `ps_sale_items`, `ps_sale_payments`
- `ps_sale_holds`, `ps_refunds`, `ps_refund_items`
- `ps_claims`, `ps_claim_items`, `ps_claim_events`, `ps_remittances`

### 9.5 Operations tables

- `ps_document_sequences`
- `ps_print_templates`, `ps_printer_profiles`, `ps_print_jobs`
- `ps_exchange_rates`
- `ps_sync_devices`, `ps_sync_operations`, `ps_sync_conflicts`, `ps_sync_cursors`
- `ps_outbox_events`, `ps_job_failures`, `ps_import_jobs`, `ps_export_jobs`

Every mutable business record includes ownership, version, created/updated timestamps and actors. Financial and stock records use reversal/correction records rather than destructive edits.

## 10. API and service architecture

- Namespaced REST API: `/wp-json/pharmasure/v1/...`.
- Controllers validate transport concerns only; application services enforce authorization, licence, transactions and workflow.
- Repositories are the only layer issuing domain SQL.
- Use WordPress nonces for same-origin UI and application passwords/OAuth/JWT-equivalent short-lived tokens for approved external clients.
- All mutations accept an idempotency key where retries could duplicate a sale, payment, claim or sync operation.
- Use optimistic concurrency via `version`/ETag for mutable records.
- Standard error envelope: code, user-safe message, field errors, correlation ID; never return SQL traces.
- Background jobs use Action Scheduler or an equivalent persistent queue for reports, notifications, webhooks, imports and sync.
- Emit domain events transactionally through an outbox.

Representative route groups:

- `/session`, `/me`, `/tenants`, `/branches`, `/memberships`
- `/drugs`, `/batches`, `/stock-movements`, `/stock-receipts`, `/transfers`, `/stock-takes`
- `/patients`, `/prescriptions`, `/dispensing`
- `/tills`, `/sales`, `/payments`, `/refunds`
- `/insurers`, `/claims`, `/remittances`
- `/reports`, `/exports`, `/print-jobs`
- `/plans`, `/subscriptions`, `/licences`, `/usage`
- `/sync/push`, `/sync/pull`, `/sync/conflicts`

## 11. Offline and synchronization

Current evidence: SQLite sync flags/queues plus Supabase sync services are **Partial**. A browser-hosted WordPress application cannot promise the same offline behavior without a PWA layer.

Recommended approach:

- Cache the application shell and read models in a service worker/IndexedDB.
- Register each device and issue a scoped sync credential.
- Queue mutations with operation UUID, entity UUID, tenant/branch, base version, client timestamp and payload.
- Server deduplicates operations, validates licence/capability, assigns authoritative versions and returns change cursor.
- Inventory, dispensing, sales and payments use conservative conflict rules; never apply last-write-wins to stock or money.
- Catalogue/profile fields may use field-level merge or explicit resolution.
- Surface conflict, retry, last successful sync, pending count and licence lease expiry.
- Encrypt sensitive local data where feasible and offer remote device revocation.

If guaranteed offline POS is a hard requirement, retain a desktop/local service companion and make WordPress the central control plane/API rather than relying on the theme alone.

## 12. Security, privacy, and compliance baseline

- Follow WordPress coding standards, parameterized SQL, output escaping, REST schemas and capability checks.
- CSRF protection for browser mutations; strict CORS; security headers and secure cookies.
- Encrypt integration secrets and sensitive backups; use TLS everywhere.
- No service-role/Supabase secret, payment secret or licence private key in JavaScript.
- Least privilege for database, filesystem, queue and external services.
- Append-only audit log for login, user/role, tenant, licence, patient access, prescription, dispensing, stock, sale, refund, claim, settings, export, print/reprint and impersonation events.
- Audit record includes before/after diffs with sensitive-field redaction, actor, tenant/branch, IP/user-agent where lawful, result and correlation ID.
- Define retention, legal hold, backup restore, breach response, data-subject export/correction and secure purge procedures.
- Health and pharmacy rules are jurisdiction-specific. Controlled medicines, patient consent, record retention, fiscal receipts, tax and insurer submission require legal review for every deployment country.
- Accessibility target: WCAG 2.2 AA; keyboard-operable POS and visible focus are mandatory.

## 13. Migration from the current project

### Phase 0: discovery and decisions

- Confirm target countries, pharmacy regulations, fiscal/tax devices, insurers, payment providers, printers and offline SLA.
- Select Multisite hybrid vs database-per-tenant.
- Declare canonical identifiers, currencies, quantity precision, costing method and timezones.
- Freeze a field-level mapping from every SQLite/PostgreSQL source table.

### Phase 1: platform foundation

- Create theme, core, tenancy, licensing and audit plugins.
- Add migrations, tenant context, capabilities, network admin and automated isolation tests.
- Implement tenant/branch onboarding and plan entitlements.

### Phase 2: operational parity

- Inventory catalogue, batches and ledger.
- Patients and prescriptions/dispensing.
- POS, tenders, receipts, refunds and cash-up.
- Claims and reports.
- Print service and templates.

### Phase 3: data migration

- Extract SQLite and Supabase data to a staging format without secrets.
- Map legacy tenant/branch IDs to canonical IDs.
- Normalize currencies, timestamps, statuses, role names and document numbers.
- Load master data before transactional data.
- Reconstruct and reconcile stock balances from movements where possible.
- Reconcile sales totals, payments, claims and licence dates.
- Produce rejected-row files and signed migration summary.
- Run the migration repeatedly in staging, then final delta/cutover.

### Phase 4: offline/integrations and rollout

- Implement PWA/companion sync only after online transaction correctness.
- Integrate payment, messaging, insurer and accounting providers.
- Pilot one tenant/branch, parallel-run reports and stock, train users, then expand.

## 14. Acceptance criteria

The rebuild is not complete until automated and user acceptance tests prove:

- Tenant A cannot read, mutate, search, export, print or cache Tenant B data, including guessed IDs.
- Branch permissions isolate branch operations while authorized tenant reports aggregate correctly.
- Licence quotas and features are enforced at API/service level; expiry/grace/suspension transitions work.
- Concurrent sale/dispense operations cannot oversell a batch under the configured stock policy.
- Sale completion is atomic and idempotent; tender totals equal the amount due.
- Refunds and voids preserve traceability and reconcile stock/payment ledgers.
- Prescription approval/dispensing roles and override reasons are enforced.
- Stock receipt, transfer, adjustment and stock take reconcile to the stock ledger.
- Currency, tax, discount, rounding and timezone edge cases are deterministic.
- Every required document prints correctly on target sizes and reprints are marked/audited.
- PDF/CSV totals match on-screen reports and source transactions.
- Offline retry does not duplicate sales/payments; conflicts are surfaced safely.
- Backup restore, tenant export and disaster recovery are rehearsed.
- Keyboard, screen-reader and responsive checks meet the accessibility target.
- Performance meets agreed targets using realistic tenant volume, not seed data.

## 15. Traceability matrix

| Existing area | Key source evidence | Rebuild module | Status/risk |
|---|---|---|---|
| Login/users | `LoginPage`, `authStore`, Rust `users` | Core/access | Implemented; harden sessions/MFA |
| Dashboard | `Dashboard`, Rust `dashboard`/`reports` | Reporting | Implemented core |
| Inventory | `InventoryPage`, Rust `inventory`/`enhanced_inventory` | Inventory | Core implemented; ledger/advanced workflow needed |
| Stock take | Rust `stock_take` | Inventory | Backend-oriented/partial UI |
| Patients | `PatientsPage`, Rust `patients` | Clinical | Implemented CRUD |
| Prescriptions | `PrescriptionsPage`, Rust `prescriptions` | Clinical | Implemented basic workflow |
| POS | `POSPage`, Rust `pos`/`enhanced_pos` | POS | Implemented core; integrations/returns need completion |
| Mobile money | `MobileMoneyPayment`, Paynow services | Integrations/POS | Partial/demo paths exist |
| Claims | `ClaimsPage`, Rust `claims` | Claims | Basic tracking; payer workflow incomplete |
| Reports | `ReportsPage`, Rust `reports` | Reporting | Implemented baseline |
| Printing | Rust `printing`, POS print hooks | Print | Generators/hooks exist; production adapters needed |
| Currency | Rust `currency`, enterprise settings | Core/POS | Implemented/partial persistence |
| Branches | selectors, enterprise services, schemas | Tenancy | Partial but strong intent |
| Tenants | Supabase, web-admin, enterprise services | Tenancy/platform admin | Partial; consolidate schemas |
| Pricing/licence | pricing SQL/services/docs, enterprise validation | Licensing | Partial; subscription-grade enforcement needed |
| Cloud sync | SQLite sync functions, Supabase, admin sync pages | Offline/integrations | Partial and architecturally mixed |
| Platform admin | `web-admin` dashboard/tenants/settings/sync | Platform admin | Substantial UI; verify real persistence |
| Audit/security | Rust audit/security, cloud audit tables | Core | Partial; make universal/immutable |

## 16. Decisions required before implementation

These choices materially change the build and should be formally approved:

1. Whether offline POS must continue during an internet outage, and for how long.
2. Multisite shared database versus isolated database/install per enterprise tenant.
3. Countries/jurisdictions and their pharmacy, privacy, tax and fiscal-device requirements.
4. Supported insurer/medical-aid submission formats.
5. Payment gateways and settlement currencies.
6. Exact receipt and label printer models/protocols.
7. Stock costing method and whether negative stock is ever allowed.
8. Licence behavior during non-payment for safety-critical dispensing.
9. Plan names, features, quotas, currencies and billing cycles.
10. Data retention and tenant termination/export policy.

## 17. Definition of done

“Exactly what this project intended to do” means functional parity is demonstrated against this document and the traceability matrix, not merely that similarly named WordPress pages exist. Each module must include schema migrations, services, UI, capabilities, licence gates, tenant-isolation tests, audit events, reports/printing where applicable, error handling, documentation, backup implications and upgrade/rollback procedures.
