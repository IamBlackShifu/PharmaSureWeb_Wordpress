# Greenwood licence validation correction

Verified against the live Wasmer database on 2026-10-12. The active pharmacy is site 3 (`/greenwoood-pharmacy/`), tenant 1, branch 1. The older `/greenwood/` WordPress site is not the active pharmacy used by the workspace.

The pharmacy's trial expired at 2026-10-11 08:52:02 UTC and has seven recorded grace days, ending 2026-10-18 08:52:02 UTC (10:52:02 in Harare). No licence dates, entitlements, quotas, stock, patient records or payments were changed during diagnosis.

Two runtime errors caused inconsistent access:

- LicenseManager filtered out expired records in SQL before evaluating the grace period. Licensed modules therefore reported no active licence during valid grace.
- The licensing plugin's separate lookup excluded trial licences. Its licence API disagreed with the module validator.

LicenseManager now loads the eligible record, calculates expiry/grace in UTC, permits valid grace, and returns an actionable 403 after grace ends. Cached licences are rechecked at the grace boundary. The licence API and TenantContext use this shared validator. Overview now enforces the inventory entitlement, and the workspace displays a grace/renewal notice with the exact deadline.

Verification used the production WordPress controllers and Greenwood's existing owner permissions against live data with MySQL's session transaction mode set to READ ONLY. Only the rate-counter persistence filter was excluded in this local diagnostic because its inserts cannot run in a read-only session; role, licence, tenant and branch guards remained enabled. No public diagnostic route or production authorization bypass was added.

All ten tested controller responses succeeded: Overview, Inventory, POS, Clinical, Claims, Reports, Offline, Accounts, Notifications and Licence. No boot/controller database read errors were found. Greenwood manager and pharmacist module permissions matched the server-derived views. Anonymous access was denied, unauthorized branch selection was rejected, posted tenant/branch overrides were ignored, and AHFoZ provider columns exist for prescriptions and claims.

Sanitized evidence is in `artifacts/greenwood-license-verification.json`. Diagnosis scripts keep database credentials in process memory/child environment and never persist or print them. Full sale, dispensing, claiming and tenant provisioning writes were not executed against production.

Local regression checks:

```powershell
& C:\xampp\php\php.exe scripts/test-license-lifecycle.php
& C:\xampp\php\php.exe scripts/test-licensing-tenancy-boundaries.php
& C:\xampp\php\php.exe scripts/test-platform-usability.php
node scripts/test-platform-usability.cjs
node scripts/test-workspace-layout.cjs
```

The licence must be renewed through the platform's authorized licensing process before the recorded grace period ends to maintain access afterward.

Deployed as Wasmer v17 (dav_R9EIBtkuoQrj) on 2026-10-12. Live JS/CSS hashes and public health/login/browser checks passed. v16 remains available for rollback; it contains the original licence validation bug.
