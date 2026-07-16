# PharmaSure WordPress - Quick Start & Architecture Overview

## 🚀 30-Second Setup

```bash
# 1. Copy environment file
cp .env.example .env

# 2. Start everything
docker-compose up -d

# 3. Wait ~30 seconds for MySQL to be ready

# 4. Initialize WordPress  
make init

# 5. Access:
# WordPress Admin: http://localhost:8080/wp-admin (admin / admin123)
# Database UI: http://localhost:8081 (pharmasure / pharmasure_pass)
# Email Inbox: http://localhost:8025
```

## 🏗️ Architecture at a Glance

```
┌─────────────────────────────────────────────────────────────┐
│  WordPress Multisite (Tenant Isolation)                     │
│                                                              │
│  ┌────────────────────────────────────────────────────────┐ │
│  │ pharmasure-core Plugin (Foundation)                    │ │
│  │ ├─ TenantContext (Server-side tenant enforcement)     │ │
│  │ ├─ LicenseManager (Cryptographic licensing)           │ │
│  │ ├─ AuditLogger (Immutable event log)                  │ │
│  │ └─ DatabaseMigrations (Versioned schema)              │ │
│  └────────────────────────────────────────────────────────┘ │
│                           ↓                                   │
│  ┌────────────────────────────────────────────────────────┐ │
│  │ Domain Plugins (Inventory, POS, Clinical, etc.)       │ │
│  │ All use TenantContext for isolation                   │ │
│  └────────────────────────────────────────────────────────┘ │
│                           ↓                                   │
│  ┌────────────────────────────────────────────────────────┐ │
│  │ Global MySQL Tables (Keyed by tenant_id)              │ │
│  │ ├─ ps_tenants, ps_branches                            │ │
│  │ ├─ ps_licences, ps_audit_events                       │ │
│  │ ├─ ps_drugs, ps_sales, ps_prescriptions               │ │
│  │ └─ ... (40+ tables)                                   │ │
│  └────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────┘
        ↓
  Redis (Sessions, Cache)
  MySQL (Persistence)
  MailHog (Email Testing)
```

## 🔐 Tenancy & Licensing: Robust Design

### Tenancy Isolation (Multi-level)
- ✅ Server derives tenant from authenticated user, **NEVER** from URL/form post
- ✅ Every database query includes `WHERE tenant_id = X` automatically
- ✅ Cross-tenant lookups by guessed ID return zero results
- ✅ Audit trail captures who accessed what when

### Licensing is Cryptographically Signed
- ✅ Server holds authoritative license database
- ✅ Offline clients get RS256-signed JWT tokens
- ✅ **Tokens cannot be forged** (would require private key)
- ✅ Tokens expire; sync re-issues new ones
- ✅ Revocation list prevents token reuse after suspension

### No Silent Failures
- ✅ License check failures return stable error codes, never leak data
- ✅ Features fail gracefully; never corrupt data
- ✅ Quota violations logged and audited
- ✅ Grace period behavior is explicit and configurable

## 📁 What's Included

| File/Directory | Purpose |
|---|---|
| [INTENT.md](INTENT.md) | **Complete specification** — read this for all requirements |
| [DEVELOPMENT.md](DEVELOPMENT.md) | **Developer guide** — plugin patterns, testing, migrations |
| `docker-compose.yml` | **Complete development stack** — WordPress, MySQL, Redis, MailHog |
| `wp-content/plugins/pharmasure-core/` | **Foundation plugin** — tenant context, licensing, audit |
| `.env.example` | **Configuration template** |
| `Makefile` | **Convenience commands** — `make init`, `make logs`, etc. |

## 📊 Implementation Status

| Component | Status |
|-----------|--------|
| Docker & local dev environment | ✅ Complete |
| pharmasure-core plugin | ✅ Complete |
| Tenancy system (TenantContext) | ✅ Complete |
| Licensing system (LicenseManager) | ✅ Complete |
| Database schema (40+ tables) | ✅ Complete |
| Migration framework | ✅ Complete |
| **pharmasure-tenancy** plugin | 📋 Next |
| **pharmasure-licensing** plugin | 📋 Next |
| **pharmasure-inventory** plugin | 📅 Week 2 |
| All other domain plugins | 📅 Weeks 3-9 |

## 🛠️ Key Design Decisions

**1. Multisite Hybrid Architecture**
- Each tenant is a WordPress site within Multisite
- Shared global tables keyed by `tenant_id` for pharmacy data
- Network Admin manages platform operations
- Native WordPress separation + explicit row ownership

**2. Tenant Context is Authoritative**
- Derived server-side from user membership
- Used on every request (singleton pattern)
- Cannot be overridden by client
- Enables strict isolation testing

**3. Licensing Tokens are Cryptographically Signed**
- RS256 asymmetric signing (server signs, clients verify)
- Clients cannot forge or modify tokens
- Private key never leaves server
- Offline grace period is limited and refreshed on sync

**4. Audit Trail is Immutable**
- Append-only event log
- Captures: actor, action, entity, before/after state, IP, timestamp
- Sensitive fields automatically redacted
- Required for compliance and security review

## 🧪 Testing Built-In

All plugins include:
- **Isolation tests**: Verify tenant/branch boundaries
- **Integration tests**: Cross-plugin workflows
- **Acceptance tests**: End-to-end per INTENT.md § 14

Run tests:
```bash
make test-isolation   # Verify tenant isolation
make test-license     # Verify licensing validation
```

## 🚀 Getting Started

### 1. Read the Full Specification
```bash
# Open INTENT.md — contains all requirements, architecture decisions, and acceptance criteria
open INTENT.md
```

### 2. Set Up Development Environment
```bash
cp .env.example .env
docker-compose up -d
make init
```

### 3. Verify Everything Works
```bash
# Check all containers are running
docker-compose ps

# Should show: wordpress, db, nginx, redis, mailhog, phpmyadmin all "Up"

# Test isolation
make test-isolation
```

### 4. Access the System
- **WordPress**: http://localhost:8080/wp-admin (admin / admin123)
- **Database**: http://localhost:8081 (pharmasure / pharmasure_pass)
- **Mail**: http://localhost:8025

### 5. Read Development Guide
```bash
open DEVELOPMENT.md
```

## 🔒 Security Checklist

Before deploying to production:
- [ ] `.env` values rotated from examples
- [ ] HTTPS enabled
- [ ] Database password changed
- [ ] License signing keys generated and stored securely
- [ ] Backup/disaster recovery tested
- [ ] Audit log export verified
- [ ] Cross-tenant access tests passed
- [ ] All API responses validated for data leakage

## 📚 Documentation Map

| Document | For |
|----------|-----|
| [INTENT.md](INTENT.md) | Product specification, requirements, acceptance criteria |
| [DEVELOPMENT.md](DEVELOPMENT.md) | How to build plugins, database migrations, testing patterns |
| [README.md](README.md) | Complete specification (original) |
| `docker-compose.yml` | Local dev infrastructure |
| `Makefile` | Convenience shortcuts |
| `.env.example` | Configuration reference |

## 🎯 Next Immediate Steps

1. ✅ **Foundation complete** — Docker, core plugin, licensing system
2. 📋 **Next: Build tenancy plugin** — Branch management, multisite integration
3. 📋 **Then: Build licensing plugin** — Feature entitlements, quota tracking  
4. 📅 **Then: Start domain plugins** — Inventory, POS, Clinical (in parallel)

## 🆘 Troubleshooting

### Services won't start?
```bash
docker-compose logs
docker-compose restart
```

### Database connection error?
```bash
# MySQL needs time to initialize
sleep 30
make init
```

### WP-CLI not responding?
```bash
docker-compose ps
docker-compose logs wpcli
```

### License validation failing?
```bash
# Check signing keys are in database
docker-compose exec db mysql -u pharmasure -ppharmasure_pass pharmasure \
  -e "SELECT * FROM wp_ps_licence_signing_keys;"
```

---

**Status**: Foundation & architecture phase ✅  
**Ready for**: Plugin development  
**Next milestone**: Tenancy plugin (Week 1)  
**Estimated full completion**: ~10 weeks  

**See also**: [INTENT.md](INTENT.md) for complete specification | [DEVELOPMENT.md](DEVELOPMENT.md) for dev guide
