# Platform administration

Sign in with the existing WordPress network super-admin account, then open:

https://pharmasure.wasmer.app/wp-admin/network/admin.php?page=pharmasure-admin

The shared navigation includes Overview, Tenants, Licensing and Provision pharmacy. Licensing shows the tenant, plan, effective status, expiry, grace deadline and active membership count. Dates are UTC. This change adds visibility; it does not extend or renew licences.

Platform views aggregate pharmacy tables across the current network. Tenant IDs are scoped by site, and the pharmacy's operational records take precedence over its root directory copy. This ensures Greenwood's active site and licence are shown consistently with its workspace.

The root `/app/` entry and default login destination send super-admins to platform administration. Explicit pharmacy URLs retain their tenant context. The pharmacy workspace includes a Platform admin link for network super-admins. Pharmacy owners and other staff do not receive network administration access.

Verification uses the packaged WordPress runtime and existing live accounts with a database-enforced read-only connection. Run `node scripts/verify-wasmer-workspaces.cjs --platform --save`; sanitized evidence is in `artifacts/platform-admin-verification.json`. It checks all four page registrations and rendered views, licence/grace data, login routing, explicit tenant scope and owner access denial. Credentials are held in memory and never written to the artifacts.
