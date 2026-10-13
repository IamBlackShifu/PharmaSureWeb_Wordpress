# Blue identity and pharmacy workflow usability

Implemented in the WordPress checkout and deployed to the existing PharmaSure Wasmer app. Release records are in artifacts/wasmer-usability-deployment.json and artifacts/wasmer-typography-deployment.json.

## Brand and reading size

The website, sign-in, application, and simplified interlocking-cross brand mark use royal blue and navy, with red in the brand and green for successful outcomes. Normal restores the original 14px application base and component typography. Large (18px) and Extra large (20px) remain saved per signed-in user; existing `standard` preferences map to Normal. The blanket control/table font overrides were removed to preserve the original visual hierarchy. Larger sizes retain a 44px minimum for operational controls, excluding checkbox/radio inputs. The header uses automatic row heights, navigation and tools occupy separate rows, and panels reflow at narrower widths. SIG and till-session styles scale with the selected reading size.

Real Chromium checks cover all three sizes, both themes, and 390/768/1024/1440/1920px widths, checking page overflow, header overlap, clipped controls, and the original heading size. Screenshots and results are in artifacts/screenshots/workspace-typography. Isolated licensing/tenancy boundary checks cover entitlement denial, tenant-scoped licence caching/queries, trial duration/modules/quotas, denied tenant provisioning, and super-administrator access. A later live Greenwood audit found and fixed licence grace-period and trial-lookup inconsistencies; see docs/GREENWOOD-LICENSING.md. Tenant provisioning services remain unchanged. Full authenticated tenant creation and migration checks still require a WordPress database; the local Docker daemon is unavailable.

Dates in application tables use YYYY-MM-DD. Native date pickers retain browser behavior; form labels and adjacent confirmation text show the stored ISO date. Timestamp output uses the user's local clock with YYYY-MM-DD ordering.

## Views and notifications

Visibility comes from WordPress capabilities supplied by the server, not role names supplied by a browser. Existing REST permissions remain in force.

- Owners/managers open in Overview and see their assigned modules.
- Cashiers open in POS; clinical intake and administration links are hidden unless separately granted.
- Pharmacists open in Clinical and retain their assigned claims/report permissions.
- Inventory staff see receiving, batches, expiry, transfers, and movement registers.
- Auditors retain the broader read-only inventory registers and their assigned reports.
- Cashiers and pharmacists receive a simple medicine catalogue without stock command bars, management metrics, reorder columns, or read-only action columns.

Navigation, Ctrl+K, overview shortcuts, and inventory subviews follow these permissions. Unauthorized inventory subviews are also rejected by the workspace API.

Attention refreshes every 20 seconds while the page is visible and can be refreshed manually. It contains current-branch tasks for review, dispensing, cashier payment, claims, expiry, disposal, and recent incoming transfers as permitted. Clicking clinical notices opens the relevant status filter. Task notices disappear when the underlying work is completed; transfer notices cover the preceding day. These are live task notices, not a permanent notification history or external messages.

## Receiving

Owners/managers set a pharmacy-specific default markup in Settings. No percentage is invented when the pharmacy has not configured one. Receiving supports a receipt-level percentage and an explicit selling-price override on each line.

Selling price = unit cost Ãƒâ€” (1 + markup / 100), rounded to minor currency units. A manual price survives subsequent cost/markup edits until Suggest price is selected. The backend also computes omitted prices and rejects invalid markup or missing prices without a configured markup. Receiving updates catalogue prices for future POS sales and prescription intake; receipt, batch and historical order snapshots remain separate.

## Prescriptions and patients

Patient registration requires first/last name only; optional demographic and clinical details are collapsed. Anonymous OTC sales continue without a patient record.

Prescription intake can send the order directly to pharmacist review through an explicitly selected checkbox. It still requires clinical approval before dispensing. Confirmed verbal counselling is sufficient for dispensing; written notes are optional. Existing review, SIG, stock and controlled-drug checks are retained. Undispensed prescriptions can be cancelled with a reason and optional verbal/written counselling evidence.

After dispensing, cashiers see the existing clinical sale in Prescriptions ready for payment and can Pick up & charge using their own open till. Payment uses the recorded total and does not consume stock again. Transactions lock the sale, preventing duplicate cashier payments. Cash and referenced card/mobile/bank payments are supported. A sale with an active medical-aid claim is excluded from this full-payment queue and stays in the existing claims/remittance workflow; split medical-aid/copayment charging is not added by this change.

## AHFoZ

Settings stores the pharmacy's AHFoZ Provider Payee Number. Prescriptions and claims capture a provider-number snapshot. Claim validation requires it; draft/queried/rejected claim numbers can be corrected with an audit event. Submitted claims keep their recorded identity. Printed dispensing summaries, claim forms and manual submission metadata include the number. This stores provider identity; it does not implement an external AHFoZ verification or submission integration.

Clinical and claims schema versions are upgraded per tenant site, and the site upgrade marker is saved only after the provider column exists. New installations also receive the column. Existing draft claims without a number can use Add AHFoZ number before validation.

## Expiry, disposal and transfers

Expiry/batch screens support removing expired stock and recording its disposal method and handling reference. Disposal of an expired active batch can be done in one action. Previously removed batches are not deducted from saleable stock twice. The disposition records the physical quantity and reference, and the disposed batch ends with zero remaining physical stock. Future-dated active stock requires quarantine/withdrawal before disposal. Existing FEFO branch transfers remain available to stock staff; incoming transfers appear in the receiving branch's attention queue.

## Verification

New isolated PHP service tests and frontend DOM tests exercise role visibility, text preferences, price calculation/override, minimum patient fields, provider identity, verbal counselling, cancellation, dispensing retries, disposal and cashier handoff. Existing SIG engine/service/builder suites also pass.

Run locally:

```powershell
& C:\xampp\php\php.exe scripts/test-platform-usability.php
node scripts/test-platform-usability.cjs
& C:\xampp\php\php.exe scripts/test-smart-sig.php
& C:\xampp\php\php.exe scripts/test-smart-sig-service.php
node scripts/test-smart-sig-builder.cjs
```

Deployed to https://pharmasure.wasmer.app as Wasmer v15 (dav_Ra7Iet1up8mD) on 2026-10-12. Live public desktop/mobile layout, login/logout, health, asset hashes, and unauthenticated endpoint protection pass. PHP/JavaScript syntax checks and isolated regression suites pass. The local Docker daemon was unavailable during implementation. Authenticated tenant database migrations and concurrent multi-user workflows still require tenant UAT covering all three text sizes, cashier/pharmacist/stock roles, receipt pricing, printed provider details, simultaneous cashier pickup, and disposal. Deployment evidence is in artifacts/wasmer-usability-deployment.json; v14 remains available for rollback.

Typography correction deployed as Wasmer v16 (dav_KE4I8tMuPwb4) on 2026-10-12. Live JS/CSS hashes, health/login and public browser checks passed. v15 remains available for rollback. Run node scripts/test-workspace-layout.cjs and PHP scripts/test-licensing-tenancy-boundaries.php for the additional regression checks.
