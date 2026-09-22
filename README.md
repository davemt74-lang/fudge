# Fudge Donuts Ops

A self-contained PHP/MySQL operations platform for a small-batch Fudge Donut business.

## What is included in this first build

- One-step web installer that creates the database, seeds starter data, and creates the first Owner account.
- Authentication with password hashing, session hardening and CSRF protection.
- Team Members with default roles, feature-level permissions, and individual allow/deny overrides.
- Server-side permission enforcement for operational actions.
- Preloaded editable ingredient catalog, packaging catalog, suppliers, products, flavors, recipes, roles, permissions and LLM providers.
- Ledger-based ingredient and packaging inventory with manual stock adjustments.
- Supplier records, supplier-item links, manual Update Pricing workflow, normalized unit cost and immutable price history.
- Products preloaded as Single ($4.99), 6-Pack ($24.99), and 12-Pack ($44.99).
- Starter Fudge Donut flavor catalog and starter Chocolate Fudge Base / Chocolate Glaze / finished recipes.
- Versioned recipe structure with reusable recipe components.
- Production batch creation and status tracking.
- Manual customer and order entry.
- Encrypted-at-rest LLM API key storage, provider/model settings, feature routing, usage logs, and an Operations Assistant that can call OpenAI-compatible or Anthropic APIs.
- Dashboard, reports foundation, settings and audit log.

## Requirements

- PHP 8.1+
- MySQL 8 or MariaDB 10.6+
- PDO MySQL extension
- OpenSSL extension
- cURL extension if using LLM calls
- Web server document root pointed at `/public`

## Install

1. Upload the project.
2. Point the site/document root at `public/`.
3. Ensure `config/` is writable for initial installation.
4. Visit `/install.php`.
5. Enter MySQL credentials and create the first Owner account.
6. After installation, make `config/config.php` non-writable by the web user where your hosting setup allows it.

The installer creates the database if the supplied database user has permission. Otherwise create an empty database first and use credentials with table-creation permissions.

## First operational setup

The catalogs are preloaded, but opening inventory is intentionally **zero**. Go to **Inventory** and record an `Initial Count` for ingredients and packaging. This creates auditable opening ledger entries instead of inventing stock.

Then review:

- Ingredients and package sizes
- Supplier prices
- Wrappers/stickers/box pricing
- Starter recipe quantities
- Flavor target weights
- Product prices
- Team roles and feature permissions
- LLM provider/model/API key settings if AI is enabled

## LLM security

API keys are encrypted with AES-256-GCM using the application key generated at installation. The plaintext key is never stored and is never rendered back to the browser. The UI only retains the last four characters for identification.

The Operations Assistant currently receives a bounded operational snapshot (open orders, active batches, low-stock count, today's sales, available finished units) plus the user's prompt. It is advisory only and does not mutate inventory, pricing, recipes, orders or permissions.

## Inventory model

Inventory totals are derived from `inventory_transactions`. Do not update an on-hand number directly. Receive stock, production use, waste, damage, samples and count corrections are all ledger transactions.

## Permission model

Effective permission precedence:

1. Role permissions establish defaults.
2. Individual `allow` grants an exception.
3. Individual `deny` overrides the role and blocks the permission.

The Owner role is protected and always retains every permission.

## Starter products

- Single Fudge Donut — $4.99
- Build a 6-Pack — $24.99
- Build a 12-Pack — $44.99

All prices are editable in the UI.

## Tests

Run:

```bash
bash tests/run.sh
```

The container used to create this package did not include a PDO MySQL driver or MySQL daemon, so this release was syntax/static tested here rather than integration-tested against a live MySQL instance. The test script includes PHP lint, encryption round-trip, expected-schema checks, permission-contract checks and secret-leak checks.

## Next build phases

This package establishes the production foundation. The next development passes should deepen, rather than replace, the current data model:

1. Full mix-and-match 6/12 box composer on order entry and customer storefront.
2. Recipe COGS recursion through nested recipes and live supplier price changes.
3. Production demand aggregation from orders and automatic ingredient requirements.
4. Purchase orders, receiving, lots, FIFO and expiration workflows.
5. Finished-goods reservation/depletion and visual packing station.
6. QC checklists, yield variance, waste approvals and labor clock-in/out.
7. Flavor-level profitability, pack margin and price-impact simulation.
8. Labels/QR codes and print layouts.
9. Production forecasting and AI purchasing recommendations.
10. Release hardening against MySQL/MariaDB with end-to-end browser tests.
