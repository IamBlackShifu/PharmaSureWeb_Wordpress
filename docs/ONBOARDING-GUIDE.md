# Easy pharmacy onboarding guide

## For the platform super administrator

1. Sign in to WordPress Network Administration with a super-admin account.
2. Open **PharmaSure → Provision pharmacy**.
3. Complete **Pharmacy identity**:
   - legal and trading names;
   - unique lowercase tenant URL slug;
   - ISO-2 country, ISO-3 currency and IANA timezone.
4. Complete **First branch** with a unique branch code, address and contact details.
5. Complete **Owner handoff** with a unique owner email. Enter a strong temporary password or leave it empty for secure generation.
6. Select **Provision pharmacy tenant** once. The workflow creates the site, all module schemas, owner membership, role, first branch, mapping and 30-day Enterprise trial. A failure removes the partial tenant.
7. Store the one-time handoff result in the approved password-sharing channel. Never send the temporary password in the same message as the login URL.
8. Open **Pharmacy tenants** and confirm owner, branch count, account count, licence and application link.

## For the pharmacy owner

1. Open the issued login URL and sign in with the temporary credentials.
2. Change the temporary password immediately from Account/security.
3. Confirm the Overview heading shows the correct pharmacy and default branch.
4. Add remaining branches and verify branch codes, timezone, tax/discount policy, receipt details and contact information.
5. Create staff accounts with unique emails, least-privileged roles and explicit branch assignments.
6. Add suppliers and catalogue medicines. Verify SKU/barcode, prescription flag, price, reorder level and tax treatment.
7. Receive opening stock by supplier invoice. Capture lot/batch, expiry, quantity and cost accurately.
8. Confirm low-stock and expiry signals match the physical shelves.
9. Configure tills and test a small sale, receipt, refund/void and cash-up using training stock.
10. Configure clinical, insurer/claim and scheduled-report data required by the pharmacy.
11. Register approved Offline devices and store each one-time secret directly on its trusted device.
12. Test each role in a separate browser and confirm it cannot see another tenant or an unauthorized branch.

## First-day go-live check

- [ ] Owner and backup manager can sign in and log out.
- [ ] Every user sees only their pharmacy and authorized branches.
- [ ] Branch selector defaults correctly in a new browser session.
- [ ] Catalogue, opening balances, batches and expiry dates reconcile to physical stock.
- [ ] Receipt printer and barcode scanner work on the selected workstation/browser.
- [ ] Clinical safety checks and controlled-medicine procedure are understood.
- [ ] Insurer references and submission responsibilities are assigned.
- [ ] XLSX/PDF exports open correctly and scheduled-report recipients are approved.
- [ ] Offline devices are named, assigned, stored securely and revocable.
- [ ] Backup, support, incident and downtime contacts are available to staff.

## Training route

Use this 45-minute sequence with training data:

1. Overview and branch selection — 5 minutes.
2. Receive a batch and find its movement — 8 minutes.
3. Complete and print a cash sale — 8 minutes.
4. Review and dispense a prescription — 8 minutes.
5. Inspect a claim and report export — 6 minutes.
6. Create a branch-restricted account — 4 minutes.
7. Inspect an Offline conflict and security alert — 4 minutes.
8. Log out and sign back in — 2 minutes.

## If onboarding stops

Do not manually create a partial tenant in several screens. Capture the displayed error and correlation ID, verify the URL slug and owner email are unique, and retry the single provisioning workflow after the underlying issue is resolved. Its compensating cleanup is designed to prevent orphan sites or memberships.
