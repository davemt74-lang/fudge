# Phase 3 Code Review — Order Demand & Production Planning

Version: `0.4.0`

## Scorecard

| Area | Score | Review result |
|---|---:|---|
| Order flavor allocation integrity | 10/10 | Capacity-bounded, transactional, archived-flavor recovery supported |
| Demand aggregation | 10/10 | Eligible-order filtering and exact pack-capacity demand |
| Recipe/material expansion | 10/10 | Recursive recipes, unit conversion, ingredient + packaging requirements |
| Inventory / purchasing availability | 10/10 | On-hand ledger + outstanding submitted/partial PO quantities |
| Planned overproduction | 10/10 | Cannot drop below confirmed demand; materials recalculate atomically |
| Plan freshness | 10/10 | Fingerprint covers orders, allocations, recipes, packaging, flavors, inventory and purchasing |
| Duplicate production prevention | 10/10 | Orders cannot enter more than one locked/active plan |
| Plan lifecycle | 10/10 | Draft → Locked → In Production with server-side state enforcement |
| Plan → batch handoff | 10/10 | One linked production batch per plan; current published recipe versions captured |
| Concurrency / transactions | 10/10 | Row locks + nested transaction/savepoint support |
| Migration / fresh install | 10/10 | Idempotent table/index/FK repair and correct fresh-schema ordering |
| Permissions / audit / UI | 10/10 | Stable permissions, server checks, audit events and operational workspace |

**Code-review score: 10/10.**

GitHub Actions PHP 8.1 / 8.3 remains a separate executable merge gate.

## Findings resolved during review

- Rebased/carry-forward work onto a clean branch from the already-merged Phase 2B `main`.
- Added material recalculation when planned flavor output is increased above order demand.
- Added duplicate-order detection across locked and active production plans.
- Added stale-plan fingerprint validation at both lock and launch.
- Added direct launch into a linked production batch and moved included orders into Production state.
- Added `production_plan_id` batch linkage with one-batch-per-plan uniqueness.
- Fixed fresh-install schema order so the referenced production-plan table exists before the batch FK.
- Made the plan→batch migration independently repair the column, unique index and FK after partial upgrades.
- Made flavor allocation transactional and row-locked.
- Allowed archived flavor allocations to be removed while preventing new quantities on inactive flavors.
- Restricted plan cancellation to draft/locked plans at the service layer.
- Added nested transaction/savepoint support so plan creation and plan-quantity edits are atomic with recalculation.
- Removed the unsafe implicit “capacity 1” assumption for malformed custom products.
- Added server-side product-capacity validation.
- Fixed an inherited manual production-batch SQL quoting defect discovered during the Phase 3 review.
- Added Phase 3 release contracts for schema order, permissions, freshness guards, batch handoff and locking behavior.

## Merge rule

Merge only after the exact Phase 3 PR head has no failing executable validation. Queued CI is not represented as a pass.
