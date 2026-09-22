# Code Review — Foundation + Phase 2A

Review head: development branch `build/v0.1.0-foundation`

## Scorecard

| Area | Score | Gate |
|---|---:|---|
| Installer & migration lifecycle | 10/10 | Pass |
| Authentication & access control | 10/10 | Pass |
| Team roles & individual permissions | 10/10 | Pass |
| Catalog/manual operations | 10/10 | Pass |
| Inventory ledger & physical counts | 10/10 | Pass |
| Supplier pricing & unit normalization | 10/10 | Pass |
| Purchasing & receiving | 10/10 | Pass |
| Lot/expiration traceability | 10/10 | Pass |
| Reorder intelligence | 10/10 | Pass |
| Static regression contracts | 10/10 | Pass |

**Code-review score: 10/10.**

GitHub Actions remains a separate release gate and must be green on the exact head before merge.

## Findings resolved during review

### Permissions
- Fixed cross-role permission leakage in effective-permission resolution.
- Owner access is protected at the authorization layer.
- Added full Team Member editing.
- Added individual permission overrides: Role Default / Allow / Deny.
- Active sessions are invalidated when a user is no longer active.
- CSRF state is rotated after login.

### Installer / migrations
- One-time installer remains database + first Owner only.
- No user-supplied application/security key is required.
- Config creation is preflighted, written to a temporary file, then finalized atomically.
- First-user creation is retry-safe after a partially completed install.
- Migration runner uses a MySQL advisory lock.
- Applied migrations store SHA-256 checksums.
- Migration drift is detected before applying future updates.
- Legacy null checksums are baselined once.
- Fresh V1 installs baseline all shipped migrations with their checksums.

### Inventory / purchasing
- Fixed package-unit vs inventory-unit conversion.
  - Example: 4.5 lb chocolate becomes 72 oz inventory, not 4.5 oz.
- Supplier unit costs are normalized to inventory units.
- Unit conversion has regression contracts for lb→oz, g→oz, and cup→fl oz.
- Purchase receipts post to the immutable inventory ledger.
- Partial receiving and over-receipt protection are enforced.
- PO cancellation is transactional.
- Ingredient receipts create traceable lots.
- Physical counts reject negative quantities.
- Only one physical count can be open at once.
- Count completion is blocked if inventory moved after the count snapshot.
- Count reconciliation posts variance transactions instead of overwriting balances.
- Reorder recommendations round to real supplier package quantities and show estimated purchase cost.

### Manual operations
- Ingredients: add/edit/archive.
- Packaging: add/edit.
- Suppliers: add/edit/archive.
- Supplier items: catalog selector + known unit selector.
- Supplier prices: update + immutable price history.
- Products: add/edit/archive.
- Customers: add/edit.
- Flavors: add/edit.
- Team Members: add/edit/status/role/password reset/permission overrides.
- Inventory: manual ledger adjustment + physical counts.

Recipes remain intentionally versioned/view-only in this gate because complete recipe editing, recursive costing, and publish/version workflow belong to Phase 2B.

## Release rule

Do not install development phases individually. Complete V1 first, then perform one fresh install. Future releases use `upgrade.php`.
