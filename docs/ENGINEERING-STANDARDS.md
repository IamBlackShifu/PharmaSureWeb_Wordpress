# PharmaSure Engineering Standards

These standards apply to first-party PharmaSure modules. They translate the security and architecture matrix into the contracts used by this repository.

## Compliance posture

PharmaSure is designed with safeguards that can support regulated pharmacy deployments. Do not describe the application as HIPAA-compliant solely because technical controls exist. A compliance claim requires the deployed environment, policies, contracts, risk analysis, incident processes and organizational controls to be assessed together.

## Tenant and branch isolation

- Client input never establishes `tenant_id` or `branch_id`.
- REST controllers derive scope from `TenantContext::instance()->get_tenant_id()` and `get_branch_id()` after authentication.
- A posted tenant, query parameter, unverified header, site slug or object ID is never authoritative scope.
- Every query against a tenant-owned table must constrain `tenant_id`; branch-owned records must additionally constrain `branch_id` where the operation is branch-specific.
- Object lookups include scope in the same query. Loading by `id` and checking tenant ownership afterward is not acceptable.
- Cross-tenant denial tests are required for each new service and endpoint.

## Persistence

- Pharmacy domain data uses versioned custom InnoDB tables, never posts, post meta, terms or options.
- Money is stored as integer minor units (`BIGINT`) unless a documented domain requirement needs sub-minor precision. This avoids binary and decimal rounding ambiguity in payment workflows.
- Measured quantities use an appropriate fixed decimal, normally `DECIMAL(18,3)` or `DECIMAL(18,4)` based on the dispensing unit.
- IDs use `BIGINT UNSIGNED`; UUIDs, hashes and idempotency keys use bounded ASCII columns sized for their real format.
- Tenant-owned high-volume tables normally include `(tenant_id, created_at)` plus query-specific status, branch and lookup indexes. Indexes must follow actual access paths; blindly duplicating indexes on child or global tables is discouraged.
- Values in SQL use `$wpdb->prepare()` placeholders. Input is also type-normalized and sanitized before persistence; prepared statements prevent injection but are not input validation.
- Multi-row domain mutations use a transaction, row locks where concurrency requires them, and an idempotency key for retryable operations.

## Auditing

- Every successful domain mutation emits one meaningful `pharmasure_audit_log` event after the transaction commits.
- Events include tenant, actor, event/action type, entity, correlation ID and safe before/after or detail context.
- Passwords, tokens, credentials, card data, clinical free text and unnecessary patient identifiers are not placed in audit details. `AuditLogger` applies a second redaction layer.
- Low-level rows that form one domain operation do not each emit redundant audit events. Migration and audit-table writes are exempt from recursive auditing.

## Licensing

- Domain entry points use `LicenseManager::enforce_entitlement( 'feature_slug' )` or the equivalent tenant-context gate before protected work.
- Explicit signed tokens fail closed on malformed data, unknown keys, tenant mismatch, invalid signatures, expiry or revocation. They never fall back to a database licence.
- Offline tokens are RS256-signed and bounded by their issued expiry/grace claims. Grace operation is observable through warnings and audit/security reporting.
- Quotas are checked before a write and updated atomically with the licensed operation where applicable.

## REST endpoints

- Routes use the existing `PharmaSure\<Module>\Rest` namespace and `/pharmasure/v1` API namespace.
- Every route has a permission callback that authenticates, resolves tenant/branch context, checks capability and enforces entitlement.
- Controllers never forward a client-supplied tenant ID into a service.
- Validation failures use stable `WP_Error` codes and appropriate HTTP status data without revealing whether another tenant's object exists.

## Application design

- Follow [Design Direction](DESIGN-DIRECTION.md).
- Authenticated screens use the PharmaSure application shell, not native WordPress content patterns.
- Prefer split operational workspaces—navigation, work canvas and contextual inspector—when the task benefits from simultaneous context.
- Use compact tables, tabular numerals, monospace identifiers, explicit status text and restrained clinical tokens.
- Avoid generic gradient hero panels, excessive shadows, excessive rounding and undifferentiated card grids in operational screens.
- Keyboard flow, focus visibility, reduced motion, responsive behavior and non-color status cues are release requirements.

## Module delivery checklist

1. Migration and rollback/upgrade behavior.
2. Tenant-scoped transactional service.
3. REST controller with context, capability and entitlement enforcement.
4. PharmaSure-native UI and all operational states.
5. Success, validation, concurrency, replay and cross-tenant tests.
6. Audit events with sensitive-field review.
7. Query/index review against real access paths.
