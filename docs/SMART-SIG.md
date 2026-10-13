# Smart SIG implementation and acceptance

Smart SIG lives in the existing Clinical plugin. `Sig/Engine.php` is a deterministic, UI-independent parser, formatter and quantity calculator. `Sig/Service.php` handles tenant-scoped product compatibility, approved terminology, immutable instruction snapshots and verification. `assets/js/smart-sig.js` exposes `window.PharmaSureSmartSig.mount(host, {api, drug, quantity, legacyFields})` and can be reused in other intake surfaces.

Clinical → SIG Management is the pharmacy configuration surface. POS → Prescription sale / dispensing opens Clinical intake and the same builder. A dispensed prescription can supply the fields for a new repeat draft; it must pass current product/terminology validation and a new pharmacist review. Preparing a draft does **not** grant repeat authority, reserve stock or decrement a repeat entitlement. Confirm prescription authority and legal repeat eligibility before approval.

## Data and migration

Clinical schema version is 3. The additive migration creates `ps_sig_definitions`, `ps_sig_instructions`, and `ps_sig_templates` under the current WordPress site prefix. SIG also maintains a site-local migration marker so each tenant site creates its own tables. Existing prescription rows remain compatible and use the established manual directions workflow.

Each instruction stores the original prescription text, original SIG, exact rational dose, independently stored form and route, frequency, duration, administration site, timing, additional instructions, referenced dictionary versions, generated directions, patient directions, calculation, stock quantity and stock unit. Reconciliation/quantity reasons and pharmacist identity/timestamp are retained. Saved instructions and printed directions are never regenerated from today's dictionary. All dictionary changes create new versions; the newest version controls new uses. Editing a code into draft withdraws its earlier production version until the new version is approved.

The source of truth is structured instruction data. Patient wording can differ only with a recorded reconciliation and explicit pharmacist comparison. The existing prescription approval transaction records SIG verification and approval together. Both dispensing entry points reject unverified SIG records. Labels use saved patient directions and the existing print-job/reprint audit infrastructure.

## Permissions and approval

- `pharmasure_manage_prescriptions`: build, preview and save directions; personal templates/preferences.
- `pharmasure_review_prescriptions`: clinical approval of terminology and prescription-specific SIG verification.
- `pharmasure_manage_sig`: configuration, dictionary versions, shared-template administration and aggregate history. Owner, manager and pharmacist roles receive this capability. Cashier, inventory clerk and auditor roles do not.

Pharmacists open **Clinical → SIG approvals & management**. Draft and pending codes have a **Review** action that loads the definition for activation without checking the clinical approval box. The pharmacist must verify the values, enter an approval rationale, explicitly confirm clinical approval and save a new version. Existing sites receive the role capability during normal initialization.

Prescription intake now offers **Set up Smart SIG** inline while the feature is inactive. A pharmacist with SIG management and review permission records acceptance and activates the existing settings API without leaving the draft. Structured dose/frequency/duration entry works immediately without a dictionary or templates; shorthand still requires individually approved codes. Other prescription users can check for activation and retain manual entry while waiting. Optional instructions and templates are collapsed, compatible units/routes are selected automatically when there is exactly one valid choice, and discrepancy reasons appear when required.
- `pharmasure_print_documents`: existing printing authorization.

Routes use existing ClinicalAccess tenant/branch resolution and clinical entitlement checks. Client-supplied tenant identifiers do not establish scope. WordPress REST cookie/nonce authentication remains in use. Configuration activation also requires pharmacist review permission and a clinical acceptance reference. Only active approved codes are exposed to dispensing users; starter codes are drafts. OD, QD, QOD, U and IU remain ambiguous/high-risk and require replacement using explicit structured fields. Alias collisions fail closed.

## Supported calculations and boundaries

Fixed daily and every-N-hours schedules with whole-day durations calculate exact regimen quantities. Single-dose regimens do not require duration. Decimals and fractions are represented as integer numerator/denominator; results that cannot be represented in the inventory's three-decimal precision require manual verification. PRN quantities are never inferred; an explicitly entered maximum is printed and its theoretical total is separate from expected consumption and stock quantity.

Weekly, alternate-day, variable, tapering and weight-based/custom schedules require the prescribed schedule in additional text and a manually verified stock quantity. There is no automatic weight-to-dose, mass-to-volume, eye-drop bottle, inhaler, cream, injection-package or pack-size conversion. Unknown catalogue forms cannot use Smart SIG until a compatible profile is configured. Missing treatment duration also requires manual quantity verification.

The calculator displays prescribed units separately from catalogue stock units. A mismatch requires a documented product-specific conversion and explicit stock quantity. Even where `pack_size` is present, its meaning is not assumed. The existing FEFO allocator retains stock/batch/expiry behavior and remains the only stock mutation during dispensing.

`pharmasure_sig_product_profile` can supply validated product-specific `units` and `routes` using the server-side product and tenant arguments. The default profiles express structural form compatibility only, supply no dose or route defaults, and are not clinical recommendations. Configure narrower product-specific route restrictions where needed.

Only English instructions are currently implemented. Other languages require approved translation/version support before use. Structural checks do not establish clinical safety; medication-specific interaction, renal, allergy or dosage thresholds are not invented. Existing pharmacist clinical checks remain mandatory. Offline SIG confirmation is not enabled; a fresh server preview and online dispensing are required.

## Tests

Available without WordPress:

```powershell
php scripts/test-smart-sig.php
php scripts/test-smart-sig-service.php
node scripts/test-smart-sig-builder.cjs
```

These cover deterministic examples, fraction precision, ambiguity/conflicts, PRN quantities, compatibility, permission/scope contracts, versioning, persistence contracts, keyboard autocomplete, reconciliation, quantity overrides, debounce and stale response handling. The service and builder harnesses exercise actual code with simulated WordPress/DOM boundaries; they do not replace real database or browser tests.

On a disposable local WordPress database:

```powershell
docker compose up -d
docker compose run --rm -T wpcli eval-file scripts/test-smart-sig-integration.php --url=http://localhost:8080/
docker compose run --rm -T wpcli eval-file scripts/test-headless-clinical.php --url=http://localhost:8080/
docker compose run --rm -T wpcli eval-file scripts/test-pos.php --url=http://localhost:8080/
docker compose run --rm -T wpcli eval-file scripts/test-print.php --url=http://localhost:8080/
docker compose run --rm -T wpcli eval-file scripts/test-isolation.php --url=http://localhost:8080/
```

The new integration fixture checks additive/idempotent migration, rollback on invalid instructions, exact persistence, inventory unchanged on entry, explicit pharmacist approval, verification identity, FEFO dispensing, historical snapshots and label rendering. It cleans up its test tenant and user. Run it in development only.

## Required before production activation

Smart SIG is disabled by default and no starter code is automatically approved. Docker was unavailable during implementation, so the live database integration/regression suites and browser acceptance remain outstanding.

Complete the integration and regression commands above, then test in a real browser with keyboard and screen-reader navigation, narrow layouts, failed/offline requests, rapid edits, repeat drafts and stale dictionary references. Test a second tenant site and unauthorized cashier/configuration roles. Verify printed layout, pharmacy particulars, quantity, pharmacist identity, dates and any locally required batch/expiry fields with the deployment's printers.

A pharmacist must approve the local terminology, compatible product forms/routes, schedules, conversion procedures, reconciliation workflow and actual label examples. Record locally applicable regulatory review and clinical acceptance. Approve required draft codes individually and enter the acceptance reference in SIG Management before enabling. Advanced clinical decision support, approved multilingual labels and validated automatic complex/pack conversions remain future extensions.

To withdraw new SIG entry, disable the tenant configuration. Existing verified historical instructions remain printable and dispensable under existing prescription controls. Disabling codes blocks their reuse in new previews/templates and pending verification, while completed dispensing snapshots retain their wording. Do not delete instruction tables as a rollback strategy.
