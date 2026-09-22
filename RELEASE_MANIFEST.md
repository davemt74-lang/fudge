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

### Verification
- PHP syntax: PASS
- Encryption round trip: PASS
- Schema contract: PASS
- Permission contract: PASS
- Secret hygiene: PASS

A live MySQL/MariaDB integration test is still required after the repository is created/deployed because this build environment did not provide a PDO MySQL driver or database daemon.
