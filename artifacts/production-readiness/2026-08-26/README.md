# Production-readiness evidence index

Captured against the local Docker environment on 26 August 2026 at 1600 × 1000 unless the document format requires another viewport.

## Automated evidence

- `00-automated-test-summary.json` — machine-readable result for 31 suites.
- `00-automated-test-transcript.txt` — complete assertion transcript.
- `00-environment.txt` — runtime versions, clean-log posture and Docker service health.
- `SHA256SUMS.txt` — integrity manifest for every evidence file.
- `exports/pharmasure-sales-20260826.xlsx` — browser-downloaded and ZIP/XML-structure-validated workbook.

## Visual evidence

| File | Evidence |
|---|---|
| `00-branded-login.png` | PharmaSure sign-in without WordPress branding |
| `01-overview-dark.png` | Overview, branch posture, metrics, ledger and persistent logout |
| `02-overview-light.png` | Light theme parity |
| `03-pos-workspace.png` | POS finder, till session, cart and tender panes |
| `04-pos-active-sale.png` | Live payable cart |
| `05-pos-light.png` | POS light theme |
| `06-pos-thermal-receipt.png` | Protected 80 mm receipt with line and payment evidence |
| `07-clinical-workspace.png` | Patient and prescription safety workspace |
| `08-clinical-review-seeded.png` | Seeded pharmacist review dialog from the same build; the final live pass had no prescription awaiting review |
| `09-clinical-light.png` | Clinical light theme |
| `10-claims-workspace.png` | Claim queue, evidence lines and lifecycle |
| `11-claims-light.png` | Claims light theme |
| `12-inventory-catalogue.png` | Inventory catalogue and inspector |
| `13-inventory-receiving.png` | Multi-line receiving workflow |
| `14-inventory-light.png` | Inventory light theme |
| `15-inventory-expiry.png` | Expiry inspection |
| `16-reports-and-exports.png` | Reports, export actions and schedules |
| `17-reports-light.png` | Reports light theme |
| `18-offline-operations.png` | Offline queue, metrics and manager tabs |
| `19-accounts-workspace.png` | Tenant-scoped account directory |
| `20-account-create.png` | Role and branch boundary creation dialog |
| `20-account-password.png` | Tenant-member self-service temporary/current password replacement |
| `21-superadmin-platform-overview.png` | Network platform command centre |
| `22-superadmin-tenant-directory.png` | Clean tenant identity/licence directory |
| `23-superadmin-provision-pharmacy.png` | Atomic three-section tenant provisioning workflow |
| `24-headless-logout-control.png` | Persistent secure-session exit in the headless shell |
| `25-logged-out-secure-login.png` | Successful session termination at branded login |

## Capture controls

- Browser scripts: `scripts/capture-headless-app.ps1`, `capture-reports-offline.ps1`, `capture-reports-accounts.ps1`, `capture-platform-readiness.ps1`.
- The POS capture creates a small sale solely for receipt validation and voids it with an audited test reason.
- Platform capture creates a randomized temporary super-admin and deletes it in `finally`, including failed runs.
- Screens were accepted only after DOM assertions for headings, row/tile counts, absence of admin-bar leakage and route-specific content.
