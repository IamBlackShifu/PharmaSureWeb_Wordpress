# PharmaSure WordPress Rebuild - Development Setup

## Quick Start

### Prerequisites

- Docker & Docker Compose
- Git
- Make (optional, for convenience commands)

### Setup

1. **Clone the repository**
   ```bash
   git clone <repository-url>
   cd PharmaSureWeb_Wordpress
   ```

2. **Copy environment file**
   ```bash
   cp .env.example .env
   ```

3. **Start Docker containers**
   ```bash
   docker-compose up -d
   ```

   Wait for services to be healthy:
   ```bash
   docker-compose ps
   ```

4. **Initialize WordPress Multisite**
   ```bash
   docker-compose exec wpcli wp core install \
     --url=http://localhost:8080 \
     --title="PharmaSure" \
     --admin_user=admin \
     --admin_password=admin123 \
     --admin_email=admin@pharmasure.local
   ```

5. **Enable Multisite**
   ```bash
   docker-compose exec wpcli wp core multisite-convert
   ```

6. **Access the application**
   - WordPress Admin: http://localhost:8080/wp-admin
   - PHPMyAdmin: http://localhost:8081
   - MailHog (email): http://localhost:8025

### Accessing Services

| Service | URL | Credentials |
|---------|-----|-------------|
| WordPress Admin | http://localhost:8080/wp-admin | admin / admin123 |
| PHPMyAdmin | http://localhost:8081 | pharmasure / pharmasure_pass |
| MailHog | http://localhost:8025 | N/A (auto-intercepts mail) |
| Redis Commander | redis:6379 | N/A (redis CLI) |

### Docker Compose Commands

```bash
# Start services
docker-compose up -d

# Stop services
docker-compose down

# View logs
docker-compose logs -f wordpress

# Run WP-CLI commands
docker-compose exec wpcli wp plugin list

# Access database CLI
docker-compose exec db mysql -u pharmasure -p pharmasure

# Restart a specific service
docker-compose restart wordpress
```

## Plugin Development Structure

```
wp-content/plugins/
├── pharmasure-core/          # Core: database, tenant context, audit, licensing
├── pharmasure-inventory/     # Inventory & stock management
├── pharmasure-pos/           # Point of sale
├── pharmasure-clinical/      # Patients, prescriptions, dispensing
├── pharmasure-claims/        # Insurance claims
├── pharmasure-reporting/     # Dashboards & reports
├── pharmasure-tenancy/       # Tenant & branch management
├── pharmasure-licensing/     # Feature licenses & quotas
├── pharmasure-print/         # Printing service
├── pharmasure-integrations/  # Payment gateways, SMS, etc.
├── pharmasure-platform-admin/# Network admin
└── pharmasure-offline/       # PWA & sync (optional)

wp-content/themes/
└── pharmasure-portal/        # WordPress block theme
```

## Tenancy & Licensing Architecture

### Tenancy Isolation (Server-Side)

1. **TenantContext singleton** (`pharmasure-core/src/TenantContext.php`):
   - Derived from authenticated user membership on every request
   - Never trusts posted `tenant_id` or `branch_id`
   - Resolves tenant from WordPress Multisite or user membership table
   - Enforces capability checks before granting access

2. **Tenant ID in every query**:
   - All domain tables have `tenant_id` and/or `branch_id` columns
   - Repository methods REQUIRE `TenantContext` parameter
   - Database queries include tenant predicates automatically

3. **Composite foreign keys**:
   - Unique keys like `(tenant_id, sku)`, `(branch_id, receipt_number)`
   - Prevents accidental cross-tenant data mixing

### Licensing - Robust & Anti-Tamper

1. **Server-side validation** (`pharmasure-core/src/LicenseManager.php`):
   - Authoritative license source is the MySQL database
   - Every privileged operation validates license state
   - Short-lived cache (1 hour) for performance

2. **Offline support with cryptographic signing**:
   - Clients receive RS256-signed JWT tokens
   - Tokens contain entitlements, quotas, expiry
   - Server validates signature; cannot be forged
   - Token revocation list prevents replay

3. **License states**:
   - `trial`, `active`, `past_due`, `grace`, `suspended`, `expired`, `revoked`
   - Grace period allows operations briefly after expiry (configurable)
   - Revocation is immediate and audited

4. **Feature enforcement**:
   - Features check via `$license_manager->has_entitlement('feature_key')`
   - Quotas checked at use case boundary (not UI hiding)
   - Failed entitlements return stable error codes

### Audit Trail

Every sensitive operation is recorded:
- Actor (user), action, entity type/ID, tenant/branch
- Before/after state diffs (sensitive fields redacted)
- IP, user-agent, correlation ID
- Immutable append-only log

## Development Workflow

### Creating a New Plugin

1. **Create plugin directory**
   ```bash
   mkdir -p wp-content/plugins/pharmasure-<module>
   ```

2. **Use plugin template**
   ```php
   <?php
   /**
    * Plugin Name: PharmaSure <Module>
    * Plugin URI: https://pharmasure.local
    * Description: <Description>
    * Version: 1.0.0
    * Author: PharmaSure Team
    * Requires Plugins: pharmasure-core
    * Requires PHP: 8.2
    */

   namespace PharmaSure\<Module>;

   // Ensure core plugin is loaded
   if ( ! defined( 'PHARMASURE_CORE_PATH' ) ) {
       wp_die( 'PharmaSure Core plugin must be active' );
   }

   // Your module code
   ```

3. **Require tenant context**
   ```php
   use PharmaSure\Core\TenantContext;

   $context = TenantContext::instance();
   $tenant_id = $context->get_tenant_id();

   // Always include in queries
   $wpdb->get_results( $wpdb->prepare(
       "SELECT * FROM {$wpdb->prefix}ps_drugs WHERE tenant_id = %d",
       $tenant_id
   ) );
   ```

### Database Migrations

1. **Create migration file** in `pharmasure-core/migrations/`:
   ```bash
   # Format: YYYY_MM_DD_HHmmss_description.php
   # Example: 2026_01_15_100000_create_tenants_table.php
   ```

2. **Write migration class**:
   ```php
   <?php
   namespace PharmaSure\Migrations;

   class CreateTenantsTable {
       public function up() {
           global $wpdb;
           $charset_collate = $wpdb->get_charset_collate();
           
           $sql = "CREATE TABLE {$wpdb->prefix}ps_tenants (
               id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
               name VARCHAR(255) NOT NULL,
               slug VARCHAR(100) NOT NULL UNIQUE,
               tenant_id BIGINT UNSIGNED NOT NULL,
               created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
               INDEX idx_slug (slug)
           ) $charset_collate;";

           require_once ABSPATH . 'wp-admin/includes/upgrade.php';
           dbDelta( $sql );
       }
   }
   ```

3. **Migrations run automatically** on plugin activation

## Testing Isolation

### Test Cross-Tenant Access Prevention

```bash
# Create test scenario
docker-compose exec wpcli wp pharmasure-core test-isolation

# Expected output: "✓ Tenant isolation verified"
```

### License Validation Test

```bash
docker-compose exec wpcli wp pharmasure-core test-license
```

## Debugging

### View PHP Logs
```bash
docker-compose logs -f wordpress
```

### Debug Database Queries
```bash
docker-compose exec db mysql -u pharmasure -ppharm asure_pass pharmasure
mysql> SELECT * FROM wp_ps_audit_events LIMIT 10;
```

### XDebug Support (IDE Debugging)

The WordPress container has XDebug enabled. Configure your IDE (VSCode/PhpStorm) with:
- Host: localhost (or Docker network IP)
- Port: 9003
- Path mapping: `/var/www/html` → `<local-wordpress-path>`

## Security Checklist

- [ ] Never log or display tenant/license secrets
- [ ] All queries include tenant scope
- [ ] License validation before privileged operations
- [ ] Audit events for all sensitive actions
- [ ] API responses never include other tenants' data
- [ ] Environment secrets in `.env` (never in code)
- [ ] CORS & CSRF protection enabled
- [ ] All user input validated/escaped

## Migration from Current Project

Once the plugin suite is complete:

1. Export SQLite & Supabase data to staging format
2. Map legacy IDs to canonical IDs
3. Run migration scripts in `migrations/` directory
4. Reconcile balances and audit trails
5. Parallel-run reports with old system
6. Pilot with one tenant, then expand

See `INTENT.md` § 13 for detailed migration phases.

## Useful Commands

```bash
# Activate all PharmaSure plugins
docker-compose exec wpcli wp plugin activate pharmasure-core pharmasure-tenancy pharmasure-licensing

# Create test tenant
docker-compose exec wpcli wp pharmasure-core create-tenant \
  --name="Test Pharmacy" \
  --slug="test-pharmacy" \
  --country="US"

# Check license status
docker-compose exec wpcli wp pharmasure-licensing status --tenant_id=1

# Export audit log
docker-compose exec wpcli wp pharmasure-core export-audit --format=csv > audit.csv

# Database backup
docker-compose exec db mysqldump -u pharmasure -ppharmasure_pass pharmasure > backup.sql
```

## Support & Issues

For questions or issues:
1. Check logs: `docker-compose logs -f`
2. Review INTENT.md for architecture decisions
3. Check plugin README in each module
4. File issue with:
   - Docker version
   - Error message (full stack)
   - Steps to reproduce

---

**Latest Update**: 2026-01-16  
**Status**: Foundation phase complete. Ready for module development.
