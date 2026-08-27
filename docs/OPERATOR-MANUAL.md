# PharmaSure operator manual

## Sign in and sign out

Open the pharmacy-specific login URL supplied during onboarding. Sign in with your issued email and password. PharmaSure opens `/app`; normal pharmacy staff are redirected there if they try to use WordPress administration.

Use **Log out** at the far right of the global header whenever you finish work or leave a shared device. If a POS cart is active, PharmaSure asks before discarding it. Closing a browser window is not a reliable server-side logout; use the button first.

## Global workspace

The top bar contains:

- the PharmaSure brand and current module;
- Overview, Point of Sale, Inventory, Clinical, Claims, Reports, Offline and Account navigation;
- `Ctrl+K` command launcher;
- dark/light theme control;
- authorized working-branch selector; and
- current role and logout.

Owners can work across all active tenant branches. Branch-restricted staff see only their explicit assignments. Selecting a branch changes operational data for that browser session only.

## Daily opening sequence

1. Sign in and confirm the pharmacy and working branch in the Overview heading.
2. Review low-stock, expiry and security signals.
3. For POS, confirm the expected till session is open and its float is correct.
4. Review Offline for unresolved conflicts or devices that require attention.
5. Verify any scheduled reports or insurer work due that day.

## Point of Sale

Search or scan a barcode, select the medicine and verify quantity and price. Prescription-only items are routed to Clinical. Apply discounts only with the required capability and a recorded reason. Confirm tender totals before checkout.

After completion, choose **Print receipt**. The protected thermal document includes pharmacy/branch identity, receipt reference, cashier/till, line items, discount, tax, total and payments. Use refund or void only with an operational reason; stock and financial reversals remain audited.

## Inventory

- **Catalogue:** medicines, SKU/barcode, pricing, reorder and active posture.
- **Batches:** lot, expiry, quantity, cost and quarantine posture.
- **Receipts:** supplier deliveries and line evidence.
- **Movements:** immutable receipt, sale, dispensing, transfer, adjustment and return ledger.
- **Low stock:** reorder queue for the active branch.
- **Expiry:** shelf-life inspection and expiring stock.
- **Suppliers:** supplier context and receipt history.

Use the command bar to receive stock, maintain catalogue/suppliers, adjust stock, quarantine/release batches or transfer stock. Verify branch, batch, expiry, quantity and unit cost before submitting. PharmaSure uses FEFO for eligible stock consumption.

## Clinical

Find or create the patient, record allergies and conditions, then create the prescription. Pharmacist review requires allergy, interaction and dose checks. Controlled medicines require legal attestation and a distinct authorized witness where configured. Record counselling before dispensing. Dispensing consumes only authorized, unexpired branch stock and captures historical cost.

## Claims

Confirm insurer, scheme, member cover and authorization before preparing a claim. Review the item evidence and lifecycle timeline. Submission requires payer evidence. Record adjudication and remittances against the correct claim; partial remittances remain open until reconciled. Use write-off controls only with the required authority and reason.

## Reports and exports

Choose report type, date range and branch scope, then refresh. Available analysis includes sales/tenders, inventory valuation, stock movements, clinical dispensing, claims, audit, security and historical margins.

Exports:

- CSV for portable row data;
- XLSX for a formatted Summary and Data workbook; and
- PDF for a fixed presentation copy.

Scheduled reports require authorized recipients, format, cadence and active status. Investigate failed runs; never add recipients outside the pharmacy’s approved reporting boundary.

## Accounts

Only owners/managers with account authority can create or change accounts. Use a unique email that is not attached to another pharmacy. Assign the least-privileged role and explicit branches for non-manager staff. Deactivate access immediately when a person leaves; do not share accounts.

## Offline operations

Register only trusted devices. Copy the client ID and secret immediately—the secret is shown once. Revoke lost or retired devices.

Review queued mutations and conflicts. Inspect payload and authoritative state before replay. Rebase only after confirming the latest server state; discard only with a clear manager reason. Repeated signature or replay failures appear in Security and escalate by frequency.

## Closing sequence

1. Complete or deliberately hold the active POS cart.
2. Reconcile and close the till as required by local procedure.
3. Resolve or hand over critical claim, stock, clinical and Offline exceptions.
4. Confirm scheduled delivery failures are assigned.
5. Select **Log out** and verify the secure sign-in screen appears.

## Support handoff

When reporting an issue include: pharmacy, branch, time, module, visible reference/receipt/claim ID, steps taken and correlation ID if shown. Do not send patient data, passwords, device secrets, licence keys or full payment information in ordinary support messages.
