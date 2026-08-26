# PharmaSure Milestone Record

Last updated: 2026-08-26

This ledger records implemented increments separately from runtime-verified completion. `PROJECT-CHECKLIST.md` remains the detailed source of truth.

## Implemented through 2026-08-23

1. Canonical audit persistence and sensitive-key redaction.
2. Active tenant membership and branch-assignment authorization.
3. Atomic FEFO clinical dispensing and immutable stock movements.
4. POS tills, checkout, pricing, split tenders, held carts, receipts, refunds, voids and permissioned variance approval.
5. Insurers, schemes, patient cover, claim preparation, lifecycle, manual submission handoff, remittances, reconciliation, write-offs and claim documents.
6. Operational, sales, tender, inventory, claims, clinical, audit, security and historical-margin reporting.
7. CSV, PDF and conditional XLSX exports plus persisted scheduled-report delivery history.
8. Immutable receipt, sale, refund and return cost snapshots with conservative legacy backfill.
9. Secure integrations foundation: encrypted credentials, email and signed-webhook adapters, idempotent queues/callbacks, retry and dead-letter handling.
10. Offline operations: signed device intake, authoritative replay, manager conflict queue and mutation detail, reason-gated discard/rebase, device administration, replay monitoring and escalating signature/replay security alerts.
11. Unified operations shell: capability-aware module navigation, persistent authorized branch context, responsive data tables/forms, skip navigation, visible focus, reduced-motion support, live announcements and admin print treatment.
12. Accessibility and API hardening: safe POS DOM rendering, keyboard-operable dynamic workflows, explicit names/status announcements, report semantics, minimum target sizes, atomic namespace rate limits, request-size ceilings, security telemetry and defensive REST/admin headers.

## Headless inventory command increment - 2026-08-25

13. Standalone `/app/inventory` workspace with catalogue, batches, receipts, movements, low-stock, expiry and supplier registers in dark and light clinical themes.
14. Inventory command layer: catalogue and supplier lifecycle maintenance, multi-line stock receiving, reasoned batch adjustments, controlled batch dispositions and atomic FEFO inter-branch transfers.
15. Inventory security: server-derived tenant/branch scope, explicit receipt-line tenant keys, active-record validation, immutable audit/ledger events, entitlement enforcement and monthly plan write quotas.
16. Runtime evidence: Inventory command tests **20/20**, headless Inventory tests **34/34**, full authenticated browser capture, and existing accessibility, architecture, launcher and licensing suites all pass.

## Verification state

- PHP 8.2 syntax checks pass for custom code.
- JavaScript syntax checks pass for the headless application client.
- Git whitespace checks pass.
- Docker runtime verification on 2026-08-24 passed all 19 suites: 294 assertions, 0 failures.
- The live stack verified WordPress Multisite, MySQL migrations, network module activation, tenant isolation, schema upgrades and REST security headers.
- Runtime findings and remaining production gates are recorded in `RUNTIME-VERIFICATION.md`.
- XAMPP PHP has OpenSSL but currently lacks Sodium and Zip; AES-256-GCM is used for secrets and XLSX reports report the missing Zip prerequisite explicitly.

## Headless Point of Sale increment - 2026-08-26

17. Standalone `/app/pos` workspace with open-till posture, branch metrics, barcode/name/SKU lookup, prescription routing, transaction cart, authoritative totals and high-density dark/light presentation.
18. Counter workflows: keyboard-first product entry, quantity controls, policy-limited discounts, exact split tenders, provider references, atomic checkout, receipt handoff and cash reconciliation.
19. Exception workflows: persisted holds and safe resume, atomic held-cart completion, refunds with exact-batch restock/quarantine, full voids and permission-gated receipt/refund/void actions.
20. POS security and durability: server-derived tenant/branch/cashier scope, active-session locking, FEFO allocation, idempotency, tenant currency persistence, entitlement/write-quota enforcement and mutation audit events.
21. Runtime evidence: POS service tests **49/49**, headless POS tests **22/22**, launcher **20/20**, application shell **11/11**, accessibility/hardening **15/15**, and authenticated Chromium dark/light interaction capture all pass. Receipt printing additionally passes **23/23** with a live 80 mm thermal capture.

## Headless Clinical increment - 2026-08-26

22. Standalone `/app/clinical` workspace with branch-scoped patient lookup, safety profiles, prescription intake, pharmacist decision queues and high-density dark/light presentation.
23. Clinical command layer: authoritative catalogue-backed prescription lines, explicit validation submission, reasoned pharmacist approval/rejection, counselling evidence and atomic FEFO dispensing into sale and stock ledgers.
24. Patient and medicine safeguards: allergy, condition and current-medication context; allergy, interaction and dose attestations; stock posture; controlled-drug declarations; and dual-user witness enforcement.
25. Claims and document handoff: completed dispensings retain immutable unit-price and cost snapshots, expose active patient cover, prepare linked claims and queue medication-label or dispensing-summary print jobs.
26. Clinical security and durability: server-derived tenant/branch scope, tenant-keyed prescription items and clinical evidence, transaction locking, idempotent dispensing, entitlement/write-quota enforcement and redacted mutation audit events.
27. Runtime evidence: Clinical/FEFO tests **27/27**, headless Clinical tests **26/26**, plus POS **22/22**, Inventory **34/34**, Claims **24/24**, Print **23/23**, launcher **20/20** and accessibility/hardening **15/15** regressions all pass. Authenticated Chromium captures verify the workspace, pharmacist review and both themes without WordPress presentation assets.

## Headless Claims increment - 2026-08-26

28. Standalone `/app/claims` revenue-cycle workspace with branch metrics, searchable lifecycle queues, claim-line evidence, coverage posture, payer context and immutable event timelines in dark and light themes.
29. Coverage and preparation workflows: eligible covered sales surface automatically, patient/member/scheme validity remains server-resolved, and claim preparation retains authoritative sale lines and idempotency.
30. Submission and adjudication workflows: validation, manual handoff, payer-reference evidence, acknowledgements, queries, full/partial approvals, rejection correction and cancellation transitions remain explicit and audited.
31. Settlement workflows: approved-balance remittance allocation, partial-payment posture, payer reconciliation history and reasoned write-offs are available beside the claim inspector.
32. Claims security: tenant and branch context is injected server-side, remittance allocation is restricted to the authorized branch, Claims entitlement and monthly write quotas protect entry points, and no client tenant identifiers are accepted.
33. Runtime evidence: Claims lifecycle tests **24/24**, headless Claims tests **18/18**, JavaScript/PHP syntax checks and authenticated Chromium dark/light capture all pass.

## Headless Reports and Accounts increment - 2026-08-26

34. Standalone `/app/reports` intelligence workspace with operational sales posture, inventory valuation, tender analysis, stock movements, dispensing activity, claim performance, audit events, security events and historical margin evidence.
35. Report delivery operations: selectable periods, tenant/branch-scoped CSV, XLSX and PDF exports, recurring daily/weekly/monthly schedules, pause/resume administration and recent delivery-run posture.
36. Reporting integrity: reporting entitlement enforcement, audit permission separation, audited schedule mutations, formula-safe CSV output, immutable sale/refund cost snapshots for historical margins and tenant-keyed clinical joins.
37. Standalone `/app/account` tenant directory with owner, manager, pharmacist, cashier, inventory-clerk and auditor roles, explicit operational branch assignments, active/inactive posture and least-privilege inspectors.
38. Account isolation: tenant identity is injected from authenticated context; branches are validated against that tenant; another pharmacy's members are never listed; email identities already used elsewhere cannot be silently reused; self-deactivation and removal of the last active owner are blocked.
39. Branded login treatment removes the WordPress visual language and presents a responsive PharmaSure security surface in the user's light/dark system preference.
40. Runtime evidence: reporting integration tests **18/18**, headless Reports/Accounts isolation tests **19/19**, PHP/JavaScript syntax checks and authenticated Chromium captures of login, both report themes, account directory and account creation all pass.

## Browser-specific branch context correction - 2026-08-26

41. Owners, managers and explicitly assigned operational users now receive the tenant's default authorized branch automatically when a new login session has no selection, eliminating the false branch-required state after login.
42. Working-branch preferences are keyed by a one-way digest of the WordPress login token plus tenant, so two browsers using the same identity can operate different branches without overwriting one global user preference.
43. The former scalar preference is migrated safely only when it still belongs to the authenticated tenant; inactive, unauthorized and cross-tenant branch IDs continue to fail closed.
44. Runtime evidence: browser-session branch tests **7/7**, branch authorization **8/8**, tenant isolation **12/12** and application launcher **20/20** all pass.

## Worktree note

The worktree already contained uncommitted changes before these increments. No milestone commit has been created because doing so would combine or misattribute unrelated existing work. Create logical commits only after the owner reviews and separates the dirty tree.

## Next milestone

Migrate Offline manager operations and platform Account/security preferences fully into the headless shell, then complete production-readiness verification: authenticated role-matrix tests, concurrent stock/checkout load, clean-install/upgrade rehearsal and backup/restore evidence.

## Production-readiness track

The screen-level accessibility and broader REST hardening increment is implemented. Next: production readiness verification—clean install/upgrade, authenticated browser and REST end-to-end tests, concurrency/load calibration, backup/restore and release runbooks.
