<div align="center">

# PharmaSure

**Enterprise multi-tenant pharmacy operations platform**

![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777BB4?style=flat-square&logo=php)
![WordPress 6.4+](https://img.shields.io/badge/WordPress-6.4%2B-21759B?style=flat-square&logo=wordpress)
![Tests Passing](https://img.shields.io/badge/tests-passing-16875D?style=flat-square)
![Proprietary](https://img.shields.io/badge/license-proprietary-9B2C2C?style=flat-square)

Secure multi-branch inventory, dispensing, point of sale, claims, reporting and operational control—built on WordPress Multisite without presenting WordPress as the product.

</div>

## Product direction

PharmaSure is a pharmacy operations console, not a generic WordPress dashboard. WordPress provides proven content, identity and multisite infrastructure underneath a purpose-built application layer. Tenant users receive a branded, pharmacy-specific workspace; platform administrators retain the underlying WordPress administration tools.

The interface direction is **clinical precision**: compact and legible information, clear operational hierarchy, restrained color, semantic status indicators, low-radius controls, predictable keyboard behavior and minimal decoration. See [Design Direction](docs/DESIGN-DIRECTION.md).

## Architecture highlights

- **Enforced multi-tenancy:** tenant and branch context is derived server-side from site mappings and active memberships. Operational reads and writes are scoped by tenant, branch and capability.
- **Transactional pharmacy operations:** stock receipt, FEFO allocation, dispensing, sales, refunds and claims use transactional services, idempotency controls and immutable stock movements.
- **Offline-resilient workflows:** registered devices, signed mutations, nonce protection, conflict handling and controlled replay support intermittent connectivity.
- **Audit-ready traceability:** normalized append-only audit events carry actor, tenant, object, status and correlation context.
- **Multi-branch control:** branch selection, assignments, quotas, stock balances and reporting remain tenant-scoped.
- **Operational reporting:** inventory, clinical, sales, margin, audit and security reporting support CSV, XLSX and PDF delivery workflows.
- **Production-oriented infrastructure:** PHP 8.2, MySQL 8, Nginx, Redis 7, MailHog development mail capture and Docker health checks.

> PharmaSure provides technical safeguards that can support a regulated deployment. Compliance with HIPAA, local health-data laws or another framework also requires deployment, contractual and organizational controls and is not claimed by the software alone.

## Core modules

| Area | Capabilities |
|---|---|
| Platform | Tenancy, licensing, roles, branches, audit and application shell |
| Inventory | Drug catalogue, suppliers, receipts, batches, FEFO and stock movements |
| Clinical | Patients, prescriptions, review, dispensing and clinical history |
| Point of sale | Tills, barcode search, tenders, holds, receipts, refunds and closing |
| Claims | Insurers, schemes, cover, submission, adjudication and reconciliation |
| Reporting | Operational, clinical, financial, audit and scheduled exports |
| Offline | Devices, signed mutations, replay, conflicts and security monitoring |
| Integrations | Configurations, outbox delivery, callbacks and retry monitoring |

## Local development

```powershell
docker compose up -d
docker compose ps
```

- Application: `http://localhost:8080`
- phpMyAdmin: `http://localhost:8081`
- MailHog: `http://localhost:8025`

Persistent demonstration tenants and stock can be created with:

```powershell
docker compose run --rm -T wpcli eval-file scripts/seed-demo-tenants.php --url=http://localhost:8080/
```

## Security language

Use precise claims in product and sales material:

- Say **server-enforced tenant isolation**, not “cryptographic tenancy.”
- Say **audit-ready append-only trail**, not “HIPAA-ready” without a complete compliance assessment.
- Say **Redis available for caching and asynchronous infrastructure** unless a specific workflow demonstrably uses it.
- Distinguish application safeguards from deployment and organizational controls.

## License

Proprietary. All rights reserved.
