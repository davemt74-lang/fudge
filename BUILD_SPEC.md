# V1 Build Contract

## Operating loop

Ingredients → Suppliers/Pricing → Inventory → Recipes → Orders → Production → Packaging → Finished Goods → Fulfillment → Cost/Profit → AI analysis.

## Product rules

- Every operational catalog is seeded but editable.
- Every operational area supports manual entry even when automated features are added later.
- Historical transactions and recipe versions are preserved rather than overwritten.
- Inventory uses a transaction ledger.
- Supplier price changes are historized.
- Feature access is server-authorized with stable permission keys.
- LLMs are advisory by default and cannot silently modify business data.

## Primary modules

Dashboard, Orders, Customers, Production, Inventory, Ingredients, Packaging, Recipes, Flavors, Products, Suppliers, Pricing Updates, Team Members, Roles & Permissions, Reports, AI/LLM, Audit Log, Settings.

## Initial products

| Product | Price |
|---|---:|
| Single | $4.99 |
| 6-Pack | $24.99 |
| 12-Pack | $44.99 |

## Starter flavors

Classic Chocolate, Cookies & Cream, Peanut Butter Cup, Salted Caramel, S'mores, Birthday Cake, Cookie Butter, Cinnamon Crunch.

## Manual-management requirement

Preloaded data is starter data, not protected hardcoding. The owner must be able to add/edit operational records from the UI. Default records may later be archived where historical references require preservation.
