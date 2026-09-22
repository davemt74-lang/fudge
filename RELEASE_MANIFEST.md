# Release Manifest — 0.2.0

## Build state

Initial repo-ready Fudge Donuts Ops foundation.

### Functional now
- Web installer + first Owner
- Login/logout + CSRF
- Team members
- Roles + feature permissions
- Individual permission overrides
- Protected Owner access
- Ingredients CRUD
- Packaging CRUD
- Inventory ledger + manual adjustments
- Suppliers CRUD
- Supplier item linking
- Update Pricing + price history
- Products + pack pricing
- Flavor CRUD
- Versioned recipe structure + starter recipes
- Production batch creation/status
- Customer CRUD
- Manual order entry
- AI provider/model settings
- AES-256-GCM API-key storage
- OpenAI-compatible / Anthropic LLM execution path
- Operations Assistant
- AI usage logging
- Dashboard, reports, audit log, settings

### Seeded business data
- 15 starter ingredients
- 5 packaging items
- 3 suppliers
- Supplier reference pricing
- Single / 6-Pack / 12-Pack products
- $44.99 12-Pack
- 8 launch flavors
- Chocolate Fudge Base + Chocolate Glaze + 8 finished recipes
- 10 team roles
- Feature permission catalog
- LLM provider records and AI feature routes

### Verification gates
The repository now runs these checks in GitHub Actions on PHP 8.1 and PHP 8.3:
- PHP syntax
- Encryption round trip
- Schema contract
- Permission contract
- Migration manager contract
- Secret hygiene

The current V1 foundation PR should not be treated as the final installable V1 release until those checks are green and the later MySQL/MariaDB integration and end-to-end release gates are added.

### Install / upgrade policy
- Do **not** install development phases individually.
- Perform one fresh install when V1 is complete.
- The installer asks only for database connection details and the first Owner user.
- No manual application security/API key is required during install.
- Future releases are applied by uploading files, visiting `upgrade.php`, and clicking the update button.
- Applied migration versions are recorded in `schema_migrations`.



## Phase 2A — Purchasing, Receiving & Inventory Reconciliation

### Added
- Purchase order creation by supplier
- Purchase-order line items using supplier-item package/cost snapshots
- Submit / partial receive / full receive / cancel workflow
- Over-receipt prevention
- Receiving sessions and receiving-item audit records
- Ingredient lot creation on receipt, with automatic internal lot IDs when vendor lots are absent
- Optional expiration dates and FEFO-ready lot data
- Packaging receiving without artificial food lots
- Inventory ledger postings for every receipt
- Physical inventory count snapshots
- Save-progress count workflow
- Completion gate requiring every item to be counted
- Reconciliation transactions for count variances
- Count history
- Reorder suggestions using reorder point → target/par quantity
- Estimated reorder cost from available supplier unit pricing
- Purchasing and lot permissions
- Purchasing access added to Manager, Inventory/Purchasing, Bookkeeping and Viewer role defaults as appropriate
- Admin seed corrected to receive the full permission catalog
- Phase 2A migration and CI contracts

### V1 install behavior
The fresh V1 installer receives the current Phase 2A schema directly and baselines all shipped migrations. Existing installed systems will receive Phase 2A through `upgrade.php`.
