# Release Manifest — 0.4.0

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


## Phase 2B — Recursive Recipe Costing & Supplier Cost Intelligence

### Added
- Recursive recipe costing across ingredients, packaging and nested sub-recipes
- Cycle detection
- Controlled unit conversion across every cost boundary
- Cost completeness / warning propagation
- Missing prices never masquerade as complete margins
- Versioned recipe editor
- Clone-current-published → draft workflow
- Edit recipe yield, notes, components, quantities and units
- Structural validation before publish
- Published nested-recipe requirement
- Historical recipe versions retained when a new version publishes
- Duplicate recipe component protection
- Product-level packaging BOM
- Average/minimum/maximum flavor cost basis in costing service
- Live Single / 6-Pack / 12-Pack direct COGS
- Live product contribution and margin when underlying costs are complete
- Recipe and product cost snapshots
- Before/after snapshots for supplier-price changes
- Before/after snapshots for product-price changes
- Before/after snapshots for product packaging BOM changes
- Recent cost-impact reporting
- Supplier price history now preserves old and new normalized unit cost
- Starter Fudge Donut BOMs for all launch flavors
- Starter 6-pack and 12-pack product packaging BOMs
- Costing permissions and audit coverage

### Cost truthfulness rule
If a required ingredient or packaging cost is unavailable, the platform marks the recipe/product as **Needs Pricing**. Partial material cost may be displayed for diagnosis, but the result is not treated as a complete margin.

### Release gate
Phase 2B is considered code-review complete only after its costing-specific static contracts and exact-head PHP 8.1 / 8.3 CI pass.


## Phase 3 — Order Demand & Production Planning

### Added
- Order-item flavor allocation for Singles, 6-Packs and 12-Packs
- Capacity enforcement so flavor allocations cannot exceed ordered units
- Archived flavor allocations can be removed but cannot receive new quantities
- Production-plan date windows up to 31 days
- Demand aggregation by flavor across eligible orders
- Recursive recipe expansion into ingredient and packaging requirements
- Product-level box packaging requirements
- Inventory-on-hand and open-PO quantities incorporated into shortage analysis
- Draft production plans with blocking issues and warnings
- Editable planned quantities above confirmed order demand
- Material requirements automatically recalculate when planned production changes
- Production-plan source fingerprints across orders, flavor allocations, recipes, packaging, flavor state, inventory and purchasing
- Stale-plan lock protection
- Duplicate-order protection across locked/active plans
- Locked-plan revalidation at production launch
- Plan → Production Batch handoff
- Linked orders move into Production status when the plan launches
- Production batches retain their source production-plan ID
- Nested transaction/savepoint support for atomic plan creation and edits
- Production Planning permissions, audit coverage and dedicated workspace

### Planning safety rules
- A plan cannot lock with unallocated order units or blocking issues.
- Material shortages are visible warnings so purchasing can happen against a locked production schedule.
- A locked plan cannot launch if its source data changed after lock.
- The same order cannot be committed to multiple locked/active production plans.
- Only draft or locked plans can be cancelled.
- Every launched plan creates at most one linked production batch.

### V1 install behavior
The fresh V1 schema creates production planning tables before the production-batch foreign key is declared. Existing installations receive the same model through migration `20260921_005_production_planning.php`.
