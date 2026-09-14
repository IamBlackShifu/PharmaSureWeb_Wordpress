# PharmaSure on Wasmer Edge

This directory contains the source configuration for the Wasmer deployment.
The deployable document root is generated under `dist/wasmer`; WordPress core is
not committed to this repository.

## Build

From the repository root:

```powershell
.\scripts\build-wasmer.ps1
```

The build downloads the WordPress version pinned by the script, validates every
core file against the official WordPress checksum API, and overlays the tracked
PharmaSure plugins and portal theme.

## Required Wasmer secrets

Configure these before making a release active:

- `WORDPRESS_AUTH_KEY`
- `WORDPRESS_SECURE_AUTH_KEY`
- `WORDPRESS_LOGGED_IN_KEY`
- `WORDPRESS_NONCE_KEY`
- `WORDPRESS_AUTH_SALT`
- `WORDPRESS_SECURE_AUTH_SALT`
- `WORDPRESS_LOGGED_IN_SALT`
- `WORDPRESS_NONCE_SALT`
- `PHARMASURE_ENCRYPTION_KEY`

Add integration, licensing, payment, Supabase, and SMTP credentials only when
the corresponding production integration is enabled. Database credentials are
provisioned by Wasmer and exposed as `DB_*` variables.

## Deploy a preview

```powershell
wasmer deploy --dir .\dist\wasmer --publish-package --bump --no-default
```

Verify the version URL, database persistence, uploads, authentication, tenant
isolation, module migrations, email, and callbacks before promoting it.

## Multisite conversion

The initial deployment sets `WORDPRESS_ALLOW_MULTISITE=true`, which exposes
**Tools > Network Setup** without prematurely switching the installed database
into multisite mode. After Network Setup completes, add the following app
environment values and redeploy:

```yaml
env:
  WORDPRESS_ALLOW_MULTISITE: "true"
  WORDPRESS_MULTISITE: "true"
  WORDPRESS_SUBDOMAIN_INSTALL: "false"
  WORDPRESS_DOMAIN_CURRENT_SITE: "pharmasure.wasmer.app"
```

Path-based multisite is the supported initial configuration. Do not enable the
`WORDPRESS_MULTISITE` flag until WordPress has created its network tables.

Do not deploy the repository root. It is development source and includes Docker
configuration and Composer metadata that are not part of the Wasmer runtime.
