# Release Manifest — 0.1.0

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

