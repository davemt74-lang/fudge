INSERT IGNORE INTO roles (name,slug,description,is_system,is_active) VALUES
('Owner','owner','Full platform access',1,1),
('Admin','admin','Administrative access',1,1),
('Manager','manager','Operations management',1,1),
('Production Lead','production-lead','Lead production operations',1,1),
('Production Team','production-team','Production floor access',1,1),
('Packing / Fulfillment','packing','Packing and fulfillment',1,1),
('Inventory / Purchasing','inventory-purchasing','Inventory and supplier purchasing',1,1),
('Sales / Customer Service','sales','Orders and customers',1,1),
('Bookkeeping','bookkeeping','Financial reporting access',1,1),
('Viewer','viewer','Read-only selected access',1,1);

INSERT IGNORE INTO permissions (permission_key,module,label) VALUES
('dashboard.view','Dashboard','View dashboard'),
('ingredients.view','Ingredients','View ingredients'),
('ingredients.manage','Ingredients','Add/edit ingredients'),
('packaging.view','Packaging','View packaging'),
('packaging.manage','Packaging','Add/edit packaging'),
('inventory.view','Inventory','View inventory'),
('inventory.adjust','Inventory','Adjust inventory'),
('inventory.receive','Inventory','Receive stock'),
('inventory.count','Inventory','Perform inventory counts'),
('inventory.view_cost','Inventory','View inventory costs'),
('suppliers.view','Suppliers','View suppliers'),
('suppliers.manage','Suppliers','Add/edit suppliers'),
('suppliers.update_prices','Suppliers','Update supplier pricing'),
('suppliers.view_price_history','Suppliers','View supplier price history'),
('suppliers.bulk_update_prices','Suppliers','Bulk update supplier pricing'),
('recipes.view','Recipes','View recipes'),
('recipes.edit','Recipes','Edit recipes'),
('recipes.publish','Recipes','Publish recipe versions'),
('recipes.view_cost','Recipes','View recipe costs'),
('flavors.view','Flavors','View flavors'),
('flavors.manage','Flavors','Add/edit flavors'),
('products.view','Products','View products'),
('products.manage','Products','Add/edit products and prices'),
('production.view','Production','View production'),
('production.create_batch','Production','Create production batches'),
('production.update_batch','Production','Update production batches'),
('production.close_batch','Production','Close production batches'),
('orders.view','Orders','View orders'),
('orders.create','Orders','Create orders'),
('orders.edit','Orders','Edit orders'),
('orders.refund','Orders','Issue refunds'),
('customers.view','Customers','View customers'),
('customers.manage','Customers','Add/edit customers'),
('reports.view','Reports','View reports'),
('reports.view_profit','Reports','View profit and margin'),
('team.view','Team','View team members'),
('team.manage_members','Team','Manage team members'),
('team.manage_roles','Team','Manage roles and permissions'),
('ai.view','AI','View AI tools'),
('ai.use','AI','Use AI assistant'),
('ai.manage_providers','AI','Manage AI providers'),
('ai.manage_api_keys','AI','Manage AI API keys'),
('ai.view_usage','AI','View AI usage'),
('audit.view','Audit','View audit log'),
('settings.manage','Settings','Manage system settings'),
('purchasing.view','Purchasing','View purchase orders'),
('purchasing.manage','Purchasing','Create and edit purchase orders'),
('purchasing.submit','Purchasing','Submit purchase orders'),
('purchasing.receive','Purchasing','Receive purchase orders'),
('purchasing.cancel','Purchasing','Cancel purchase orders'),
('lots.view','Inventory','View ingredient lots and expiration'),
('lots.manage','Inventory','Manage ingredient lots');

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.slug='owner';

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.slug='admin';

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT r.id,p.id FROM roles r JOIN permissions p ON p.permission_key IN (
'dashboard.view','ingredients.view','ingredients.manage','packaging.view','packaging.manage','inventory.view','inventory.adjust','inventory.receive','inventory.count','inventory.view_cost',
'suppliers.view','suppliers.manage','suppliers.update_prices','suppliers.view_price_history',
'recipes.view','recipes.edit','recipes.publish','recipes.view_cost','flavors.view','flavors.manage','products.view','products.manage',
'production.view','production.create_batch','production.update_batch','production.close_batch',
'orders.view','orders.create','orders.edit','customers.view','customers.manage','reports.view','reports.view_profit',
'team.view','team.manage_members','ai.view','ai.use','ai.view_usage','audit.view','purchasing.view','purchasing.manage','purchasing.submit','purchasing.receive','purchasing.cancel','lots.view','lots.manage'
) WHERE r.slug='manager';

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT r.id,p.id FROM roles r JOIN permissions p ON p.permission_key IN (
'dashboard.view','production.view','production.create_batch','production.update_batch','production.close_batch',
'recipes.view','inventory.view','inventory.adjust','flavors.view'
) WHERE r.slug='production-lead';

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT r.id,p.id FROM roles r JOIN permissions p ON p.permission_key IN (
'dashboard.view','production.view','production.update_batch','recipes.view','inventory.view'
) WHERE r.slug='production-team';

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT r.id,p.id FROM roles r JOIN permissions p ON p.permission_key IN (
'dashboard.view','orders.view','orders.edit','customers.view','production.view'
) WHERE r.slug='packing';

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT r.id,p.id FROM roles r JOIN permissions p ON p.permission_key IN (
'dashboard.view','ingredients.view','ingredients.manage','packaging.view','packaging.manage','inventory.view','inventory.adjust','inventory.receive','inventory.count','inventory.view_cost',
'suppliers.view','suppliers.manage','suppliers.update_prices','suppliers.view_price_history','suppliers.bulk_update_prices',
'purchasing.view','purchasing.manage','purchasing.submit','purchasing.receive','purchasing.cancel','lots.view','lots.manage'
) WHERE r.slug='inventory-purchasing';

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT r.id,p.id FROM roles r JOIN permissions p ON p.permission_key IN (
'dashboard.view','orders.view','orders.create','orders.edit','customers.view','customers.manage','products.view','flavors.view'
) WHERE r.slug='sales';

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT r.id,p.id FROM roles r JOIN permissions p ON p.permission_key IN (
'dashboard.view','reports.view','reports.view_profit','orders.view','inventory.view_cost','suppliers.view','suppliers.view_price_history','purchasing.view','lots.view'
) WHERE r.slug='bookkeeping';

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT r.id,p.id FROM roles r JOIN permissions p ON p.permission_key IN (
'dashboard.view','orders.view','production.view','inventory.view','recipes.view','flavors.view','products.view','suppliers.view','team.view','purchasing.view','lots.view'
) WHERE r.slug='viewer';

INSERT IGNORE INTO ingredient_categories (name) VALUES
('Chocolate & Fudge'),('Dairy'),('Cookies & Crumbs'),('Candy'),('Sauces & Glazes'),('Nuts & Crunch'),('Decorations');

INSERT IGNORE INTO allergens (name) VALUES ('Milk'),('Soy'),('Wheat'),('Peanuts'),('Tree Nuts'),('Egg');

INSERT IGNORE INTO units (name,symbol,unit_type,base_multiplier) VALUES
('Each','each','count',1),
('Ounce','oz','weight',1),
('Pound','lb','weight',16),
('Gram','g','weight',0.035274),
('Fluid Ounce','fl oz','volume',1),
('Cup','cup','volume',8);

INSERT IGNORE INTO ingredients (name,sku,category_id,inventory_unit,reorder_point,target_stock,storage_location) VALUES
('Semi-Sweet Chocolate Chips','ING-CHOC-SS',(SELECT id FROM ingredient_categories WHERE name='Chocolate & Fudge'),'oz',160,480,'Pantry'),
('Sweetened Condensed Milk','ING-COND-MILK',(SELECT id FROM ingredient_categories WHERE name='Dairy'),'oz',84,252,'Pantry'),
('Unsalted Butter','ING-BUTTER',(SELECT id FROM ingredient_categories WHERE name='Dairy'),'oz',32,96,'Refrigerator'),
('Heavy Cream','ING-CREAM',(SELECT id FROM ingredient_categories WHERE name='Dairy'),'fl oz',32,96,'Refrigerator'),
('Vanilla Extract','ING-VANILLA',(SELECT id FROM ingredient_categories WHERE name='Sauces & Glazes'),'fl oz',4,12,'Pantry'),
('Fine Salt','ING-SALT',(SELECT id FROM ingredient_categories WHERE name='Decorations'),'oz',8,24,'Pantry'),
('Oreo Cookies','ING-OREO',(SELECT id FROM ingredient_categories WHERE name='Cookies & Crumbs'),'oz',32,96,'Pantry'),
('Peanut Butter Cups','ING-PBCUP',(SELECT id FROM ingredient_categories WHERE name='Candy'),'oz',24,72,'Pantry'),
('Caramel Sauce','ING-CARAMEL',(SELECT id FROM ingredient_categories WHERE name='Sauces & Glazes'),'oz',24,72,'Pantry'),
('Rainbow Sprinkles','ING-SPRINKLES',(SELECT id FROM ingredient_categories WHERE name='Decorations'),'oz',16,48,'Pantry'),
('Mini Marshmallows','ING-MARSH',(SELECT id FROM ingredient_categories WHERE name='Decorations'),'oz',16,48,'Pantry'),
('Graham Crackers','ING-GRAHAM',(SELECT id FROM ingredient_categories WHERE name='Cookies & Crumbs'),'oz',16,48,'Pantry'),
('Cookie Butter','ING-COOKIEBUTTER',(SELECT id FROM ingredient_categories WHERE name='Sauces & Glazes'),'oz',16,48,'Pantry'),
('Biscoff Cookies','ING-BISCOFF',(SELECT id FROM ingredient_categories WHERE name='Cookies & Crumbs'),'oz',16,48,'Pantry'),
('Cinnamon','ING-CINNAMON',(SELECT id FROM ingredient_categories WHERE name='Decorations'),'oz',4,12,'Pantry');

INSERT IGNORE INTO packaging_items (name,sku,inventory_unit,current_unit_cost,reorder_point,target_stock) VALUES
('Clear Individual Wrapper','PKG-WRAP','each',0.0300,250,1000),
('Branded Back Sticker','PKG-STICKER','each',0.0600,250,1000),
('6-Pack Box','PKG-BOX6','each',0.3000,25,100),
('12-Pack Box','PKG-BOX12','each',0.5000,25,100),
('Box Flavor Insert','PKG-INSERT','each',0.0500,50,200);

INSERT IGNORE INTO suppliers (name,website,notes) VALUES
('Costco Business','https://www.costcobusinessdelivery.com','Starter supplier record; verify local/current pricing before purchasing.'),
('Restaurant Depot','https://www.restaurantdepot.com','Starter supplier record; verify local/current pricing before purchasing.'),
('Packaging Supplier',NULL,'Replace with preferred packaging vendor.');

INSERT IGNORE INTO products (name,sku,product_type,box_capacity,price) VALUES
('Single Fudge Donut','FD-SINGLE','single',1,4.99),
('Build a 6-Pack','FD-6','box',6,24.99),
('Build a 12-Pack','FD-12','box',12,44.99);

INSERT IGNORE INTO flavors (name,slug,description,target_weight_oz,seasonal,is_active) VALUES
('Classic Chocolate','classic-chocolate','Chocolate fudge with glossy chocolate topping and chocolate finish.',3.5,0,1),
('Cookies & Cream','cookies-cream','Chocolate fudge with cookie crumble and cream-style finish.',3.5,0,1),
('Peanut Butter Cup','peanut-butter-cup','Chocolate fudge with peanut butter cup topping.',3.5,0,1),
('Salted Caramel','salted-caramel','Chocolate fudge with caramel drizzle and sea-salt finish.',3.5,0,1),
('S''mores','smores','Chocolate fudge with graham and marshmallow topping.',3.5,0,1),
('Birthday Cake','birthday-cake','Celebration-style fudge donut with colorful sprinkles.',3.5,0,1),
('Cookie Butter','cookie-butter','Fudge donut with cookie butter and cookie crumble.',3.5,0,1),
('Cinnamon Crunch','cinnamon-crunch','Fudge donut with cinnamon crumble finish.',3.5,0,1);

INSERT IGNORE INTO recipes (name,recipe_type) VALUES
('Chocolate Fudge Base','base'),
('Chocolate Glaze','component');

INSERT IGNORE INTO recipe_versions (recipe_id,version_number,yield_quantity,yield_unit,status,published_at)
SELECT id,1,12,'each','published',NOW() FROM recipes WHERE name='Chocolate Fudge Base';
INSERT IGNORE INTO recipe_versions (recipe_id,version_number,yield_quantity,yield_unit,status,published_at)
SELECT id,1,12,'each','published',NOW() FROM recipes WHERE name='Chocolate Glaze';

INSERT IGNORE INTO recipe_items (recipe_version_id,component_type,component_id,quantity,unit,sort_order)
SELECT rv.id,'ingredient',i.id,21,'oz',10 FROM recipe_versions rv JOIN recipes r ON r.id=rv.recipe_id JOIN ingredients i ON i.sku='ING-CHOC-SS' WHERE r.name='Chocolate Fudge Base' AND rv.version_number=1;
INSERT IGNORE INTO recipe_items (recipe_version_id,component_type,component_id,quantity,unit,sort_order)
SELECT rv.id,'ingredient',i.id,14,'oz',20 FROM recipe_versions rv JOIN recipes r ON r.id=rv.recipe_id JOIN ingredients i ON i.sku='ING-COND-MILK' WHERE r.name='Chocolate Fudge Base' AND rv.version_number=1;
INSERT IGNORE INTO recipe_items (recipe_version_id,component_type,component_id,quantity,unit,sort_order)
SELECT rv.id,'ingredient',i.id,1,'oz',30 FROM recipe_versions rv JOIN recipes r ON r.id=rv.recipe_id JOIN ingredients i ON i.sku='ING-BUTTER' WHERE r.name='Chocolate Fudge Base' AND rv.version_number=1;

INSERT IGNORE INTO recipe_items (recipe_version_id,component_type,component_id,quantity,unit,sort_order)
SELECT rv.id,'ingredient',i.id,6,'oz',10 FROM recipe_versions rv JOIN recipes r ON r.id=rv.recipe_id JOIN ingredients i ON i.sku='ING-CHOC-SS' WHERE r.name='Chocolate Glaze' AND rv.version_number=1;
INSERT IGNORE INTO recipe_items (recipe_version_id,component_type,component_id,quantity,unit,sort_order)
SELECT rv.id,'ingredient',i.id,1,'oz',20 FROM recipe_versions rv JOIN recipes r ON r.id=rv.recipe_id JOIN ingredients i ON i.sku='ING-BUTTER' WHERE r.name='Chocolate Glaze' AND rv.version_number=1;
INSERT IGNORE INTO recipe_items (recipe_version_id,component_type,component_id,quantity,unit,sort_order)
SELECT rv.id,'ingredient',i.id,1.5,'fl oz',30 FROM recipe_versions rv JOIN recipes r ON r.id=rv.recipe_id JOIN ingredients i ON i.sku='ING-CREAM' WHERE r.name='Chocolate Glaze' AND rv.version_number=1;

INSERT IGNORE INTO recipes (name,recipe_type,flavor_id)
SELECT CONCAT(name,' Fudge Donut'),'finished',id FROM flavors;

INSERT IGNORE INTO recipe_versions (recipe_id,version_number,yield_quantity,yield_unit,status,published_at)
SELECT r.id,1,1,'each','published',NOW() FROM recipes r WHERE r.recipe_type='finished';

INSERT IGNORE INTO llm_providers (name,provider_type,base_url,default_model,enabled,is_default) VALUES
('OpenAI','openai_compatible','https://api.openai.com/v1','',0,1),
('Anthropic','anthropic','https://api.anthropic.com','',0,0),
('OpenAI-Compatible / Custom','openai_compatible','http://127.0.0.1:11434/v1','',0,0);

INSERT IGNORE INTO llm_feature_settings (feature_key,enabled) VALUES
('operations_assistant',0),
('production_recommendations',0),
('inventory_analysis',0),
('supplier_price_analysis',0),
('recipe_assistant',0);

INSERT INTO platform_meta (meta_key,meta_value) VALUES ('app_version','0.5.0')
ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value);


INSERT INTO platform_meta (meta_key,meta_value) VALUES ('phase_2a','complete')
ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value);


-- Starter supplier-item references. These are editable operational seed values and
-- intentionally carry a fixed reference date so the UI does not imply they are live prices.
INSERT INTO supplier_items
(supplier_id,item_type,item_id,supplier_sku,package_description,package_quantity,package_unit,package_price,unit_cost,is_preferred,last_price_update)
SELECT s.id,'ingredient',i.id,'STARTER-CHOC-45LB','4.5 lb semi-sweet chocolate chips',4.5,'lb',13.61,0.189028,1,'2026-09-21 00:00:00'
FROM suppliers s JOIN ingredients i ON i.sku='ING-CHOC-SS'
WHERE s.name='Costco Business'
AND NOT EXISTS (SELECT 1 FROM supplier_items x WHERE x.supplier_id=s.id AND x.supplier_sku='STARTER-CHOC-45LB');

INSERT INTO supplier_items
(supplier_id,item_type,item_id,supplier_sku,package_description,package_quantity,package_unit,package_price,unit_cost,is_preferred,last_price_update)
SELECT s.id,'ingredient',i.id,'STARTER-COND-6X14','6 × 14 oz sweetened condensed milk',84,'oz',15.88,0.189048,1,'2026-09-21 00:00:00'
FROM suppliers s JOIN ingredients i ON i.sku='ING-COND-MILK'
WHERE s.name='Costco Business'
AND NOT EXISTS (SELECT 1 FROM supplier_items x WHERE x.supplier_id=s.id AND x.supplier_sku='STARTER-COND-6X14');

INSERT INTO supplier_items
(supplier_id,item_type,item_id,supplier_sku,package_description,package_quantity,package_unit,package_price,unit_cost,is_preferred,last_price_update)
SELECT s.id,'ingredient',i.id,'STARTER-BUTTER-1LB','1 lb unsalted butter',1,'lb',2.51,0.156875,1,'2026-09-21 00:00:00'
FROM suppliers s JOIN ingredients i ON i.sku='ING-BUTTER'
WHERE s.name='Restaurant Depot'
AND NOT EXISTS (SELECT 1 FROM supplier_items x WHERE x.supplier_id=s.id AND x.supplier_sku='STARTER-BUTTER-1LB');

INSERT INTO supplier_items
(supplier_id,item_type,item_id,supplier_sku,package_description,package_quantity,package_unit,package_price,unit_cost,is_preferred,last_price_update)
SELECT s.id,'ingredient',i.id,'STARTER-CREAM-32OZ','32 fl oz heavy cream',32,'fl oz',4.76,0.148750,1,'2026-09-21 00:00:00'
FROM suppliers s JOIN ingredients i ON i.sku='ING-CREAM'
WHERE s.name='Restaurant Depot'
AND NOT EXISTS (SELECT 1 FROM supplier_items x WHERE x.supplier_id=s.id AND x.supplier_sku='STARTER-CREAM-32OZ');

INSERT INTO supplier_items
(supplier_id,item_type,item_id,supplier_sku,package_description,package_quantity,package_unit,package_price,unit_cost,is_preferred,last_price_update)
SELECT s.id,'ingredient',i.id,'STARTER-OREO-6276','62.76 oz Oreo cookies',62.76,'oz',12.47,0.198693,1,'2026-09-21 00:00:00'
FROM suppliers s JOIN ingredients i ON i.sku='ING-OREO'
WHERE s.name='Costco Business'
AND NOT EXISTS (SELECT 1 FROM supplier_items x WHERE x.supplier_id=s.id AND x.supplier_sku='STARTER-OREO-6276');

INSERT INTO supplier_items
(supplier_id,item_type,item_id,supplier_sku,package_description,package_quantity,package_unit,package_price,unit_cost,is_preferred,last_price_update)
SELECT s.id,'ingredient',i.id,'STARTER-REESE-54','54 oz peanut butter cups',54,'oz',47.32,0.876296,1,'2026-09-21 00:00:00'
FROM suppliers s JOIN ingredients i ON i.sku='ING-PBCUP'
WHERE s.name='Costco Business'
AND NOT EXISTS (SELECT 1 FROM supplier_items x WHERE x.supplier_id=s.id AND x.supplier_sku='STARTER-REESE-54');

INSERT INTO supplier_items
(supplier_id,item_type,item_id,supplier_sku,package_description,package_quantity,package_unit,package_price,unit_cost,is_preferred,last_price_update)
SELECT s.id,'ingredient',i.id,'STARTER-SPRINKLES-7LB','7 lb rainbow sprinkles',7,'lb',21.34,0.190536,1,'2026-09-21 00:00:00'
FROM suppliers s JOIN ingredients i ON i.sku='ING-SPRINKLES'
WHERE s.name='Restaurant Depot'
AND NOT EXISTS (SELECT 1 FROM supplier_items x WHERE x.supplier_id=s.id AND x.supplier_sku='STARTER-SPRINKLES-7LB');

INSERT INTO supplier_items
(supplier_id,item_type,item_id,supplier_sku,package_description,package_quantity,package_unit,package_price,unit_cost,is_preferred,last_price_update)
SELECT s.id,'packaging',p.id,'STARTER-WRAP-1000','1,000 clear individual wrappers',1000,'each',28.98,0.028980,1,'2026-09-21 00:00:00'
FROM suppliers s JOIN packaging_items p ON p.sku='PKG-WRAP'
WHERE s.name='Packaging Supplier'
AND NOT EXISTS (SELECT 1 FROM supplier_items x WHERE x.supplier_id=s.id AND x.supplier_sku='STARTER-WRAP-1000');

INSERT INTO supplier_items
(supplier_id,item_type,item_id,supplier_sku,package_description,package_quantity,package_unit,package_price,unit_cost,is_preferred,last_price_update)
SELECT s.id,'packaging',p.id,'STARTER-STICKER-1000','1,000 branded back stickers',1000,'each',90.00,0.090000,1,'2026-09-21 00:00:00'
FROM suppliers s JOIN packaging_items p ON p.sku='PKG-STICKER'
WHERE s.name='Packaging Supplier'
AND NOT EXISTS (SELECT 1 FROM supplier_items x WHERE x.supplier_id=s.id AND x.supplier_sku='STARTER-STICKER-1000');

INSERT INTO supplier_items
(supplier_id,item_type,item_id,supplier_sku,package_description,package_quantity,package_unit,package_price,unit_cost,is_preferred,last_price_update)
SELECT s.id,'packaging',p.id,'STARTER-BOX6-200','200 six-pack boxes',200,'each',60.00,0.300000,1,'2026-09-21 00:00:00'
FROM suppliers s JOIN packaging_items p ON p.sku='PKG-BOX6'
WHERE s.name='Packaging Supplier'
AND NOT EXISTS (SELECT 1 FROM supplier_items x WHERE x.supplier_id=s.id AND x.supplier_sku='STARTER-BOX6-200');

INSERT INTO supplier_items
(supplier_id,item_type,item_id,supplier_sku,package_description,package_quantity,package_unit,package_price,unit_cost,is_preferred,last_price_update)
SELECT s.id,'packaging',p.id,'STARTER-BOX12-125','125 twelve-pack boxes',125,'each',62.25,0.498000,1,'2026-09-21 00:00:00'
FROM suppliers s JOIN packaging_items p ON p.sku='PKG-BOX12'
WHERE s.name='Packaging Supplier'
AND NOT EXISTS (SELECT 1 FROM supplier_items x WHERE x.supplier_id=s.id AND x.supplier_sku='STARTER-BOX12-125');


-- Phase 2B permissions and role defaults.
INSERT IGNORE INTO permissions (permission_key,module,label) VALUES
('costing.view','Costing','View recipe and product costs'),
('costing.snapshot','Costing','Capture cost snapshots'),
('costing.view_margin','Costing','View product margin');

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT r.id,p.id FROM roles r JOIN permissions p
WHERE r.slug IN ('owner','admin','manager')
AND p.permission_key IN ('costing.view','costing.snapshot','costing.view_margin');

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT r.id,p.id FROM roles r JOIN permissions p
WHERE r.slug='bookkeeping'
AND p.permission_key IN ('costing.view','costing.view_margin');

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT r.id,p.id FROM roles r JOIN permissions p
WHERE r.slug IN ('inventory-purchasing','production-lead')
AND p.permission_key='costing.view';

-- Product-level packaging BOM.
INSERT IGNORE INTO product_packaging_components (product_id,packaging_item_id,quantity,unit)
SELECT pr.id,p.id,1,'each' FROM products pr JOIN packaging_items p
WHERE pr.sku='FD-6' AND p.sku='PKG-BOX6';

INSERT IGNORE INTO product_packaging_components (product_id,packaging_item_id,quantity,unit)
SELECT pr.id,p.id,1,'each' FROM products pr JOIN packaging_items p
WHERE pr.sku='FD-12' AND p.sku='PKG-BOX12';

-- Complete the Chocolate Fudge Base starter BOM.
INSERT INTO recipe_items (recipe_version_id,component_type,component_id,quantity,unit,sort_order)
SELECT rv.id,'ingredient',i.id,0.1667,'fl oz',40
FROM recipe_versions rv
JOIN recipes r ON r.id=rv.recipe_id
JOIN ingredients i ON i.sku='ING-VANILLA'
WHERE r.name='Chocolate Fudge Base' AND rv.version_number=1
AND NOT EXISTS (
  SELECT 1 FROM recipe_items x
  WHERE x.recipe_version_id=rv.id AND x.component_type='ingredient' AND x.component_id=i.id
);

INSERT INTO recipe_items (recipe_version_id,component_type,component_id,quantity,unit,sort_order)
SELECT rv.id,'ingredient',i.id,0.05,'oz',50
FROM recipe_versions rv
JOIN recipes r ON r.id=rv.recipe_id
JOIN ingredients i ON i.sku='ING-SALT'
WHERE r.name='Chocolate Fudge Base' AND rv.version_number=1
AND NOT EXISTS (
  SELECT 1 FROM recipe_items x
  WHERE x.recipe_version_id=rv.id AND x.component_type='ingredient' AND x.component_id=i.id
);

-- Every finished Fudge Donut consumes one base portion, one glaze portion,
-- one clear wrapper, and one branded back sticker.
INSERT INTO recipe_items (recipe_version_id,component_type,component_id,quantity,unit,sort_order)
SELECT rv.id,'recipe',base.id,1,'each',10
FROM recipe_versions rv
JOIN recipes finished ON finished.id=rv.recipe_id AND finished.recipe_type='finished'
JOIN recipes base ON base.name='Chocolate Fudge Base'
WHERE rv.version_number=1
AND NOT EXISTS (
  SELECT 1 FROM recipe_items x
  WHERE x.recipe_version_id=rv.id AND x.component_type='recipe' AND x.component_id=base.id
);

INSERT INTO recipe_items (recipe_version_id,component_type,component_id,quantity,unit,sort_order)
SELECT rv.id,'recipe',glaze.id,1,'each',20
FROM recipe_versions rv
JOIN recipes finished ON finished.id=rv.recipe_id AND finished.recipe_type='finished'
JOIN recipes glaze ON glaze.name='Chocolate Glaze'
WHERE rv.version_number=1
AND NOT EXISTS (
  SELECT 1 FROM recipe_items x
  WHERE x.recipe_version_id=rv.id AND x.component_type='recipe' AND x.component_id=glaze.id
);

INSERT INTO recipe_items (recipe_version_id,component_type,component_id,quantity,unit,sort_order)
SELECT rv.id,'packaging',p.id,1,'each',90
FROM recipe_versions rv
JOIN recipes finished ON finished.id=rv.recipe_id AND finished.recipe_type='finished'
JOIN packaging_items p ON p.sku='PKG-WRAP'
WHERE rv.version_number=1
AND NOT EXISTS (
  SELECT 1 FROM recipe_items x
  WHERE x.recipe_version_id=rv.id AND x.component_type='packaging' AND x.component_id=p.id
);

INSERT INTO recipe_items (recipe_version_id,component_type,component_id,quantity,unit,sort_order)
SELECT rv.id,'packaging',p.id,1,'each',100
FROM recipe_versions rv
JOIN recipes finished ON finished.id=rv.recipe_id AND finished.recipe_type='finished'
JOIN packaging_items p ON p.sku='PKG-STICKER'
WHERE rv.version_number=1
AND NOT EXISTS (
  SELECT 1 FROM recipe_items x
  WHERE x.recipe_version_id=rv.id AND x.component_type='packaging' AND x.component_id=p.id
);

-- Flavor toppings.
INSERT INTO recipe_items (recipe_version_id,component_type,component_id,quantity,unit,sort_order)
SELECT rv.id,'ingredient',i.id,0.10,'oz',30
FROM recipe_versions rv JOIN recipes r ON r.id=rv.recipe_id JOIN flavors f ON f.id=r.flavor_id JOIN ingredients i ON i.sku='ING-CHOC-SS'
WHERE f.slug='classic-chocolate' AND rv.version_number=1
AND NOT EXISTS (SELECT 1 FROM recipe_items x WHERE x.recipe_version_id=rv.id AND x.component_type='ingredient' AND x.component_id=i.id);

INSERT INTO recipe_items (recipe_version_id,component_type,component_id,quantity,unit,sort_order)
SELECT rv.id,'ingredient',i.id,0.40,'oz',30
FROM recipe_versions rv JOIN recipes r ON r.id=rv.recipe_id JOIN flavors f ON f.id=r.flavor_id JOIN ingredients i ON i.sku='ING-OREO'
WHERE f.slug='cookies-cream' AND rv.version_number=1
AND NOT EXISTS (SELECT 1 FROM recipe_items x WHERE x.recipe_version_id=rv.id AND x.component_type='ingredient' AND x.component_id=i.id);

INSERT INTO recipe_items (recipe_version_id,component_type,component_id,quantity,unit,sort_order)
SELECT rv.id,'ingredient',i.id,0.40,'oz',30
FROM recipe_versions rv JOIN recipes r ON r.id=rv.recipe_id JOIN flavors f ON f.id=r.flavor_id JOIN ingredients i ON i.sku='ING-PBCUP'
WHERE f.slug='peanut-butter-cup' AND rv.version_number=1
AND NOT EXISTS (SELECT 1 FROM recipe_items x WHERE x.recipe_version_id=rv.id AND x.component_type='ingredient' AND x.component_id=i.id);

INSERT INTO recipe_items (recipe_version_id,component_type,component_id,quantity,unit,sort_order)
SELECT rv.id,'ingredient',i.id,0.25,'oz',30
FROM recipe_versions rv JOIN recipes r ON r.id=rv.recipe_id JOIN flavors f ON f.id=r.flavor_id JOIN ingredients i ON i.sku='ING-CARAMEL'
WHERE f.slug='salted-caramel' AND rv.version_number=1
AND NOT EXISTS (SELECT 1 FROM recipe_items x WHERE x.recipe_version_id=rv.id AND x.component_type='ingredient' AND x.component_id=i.id);

INSERT INTO recipe_items (recipe_version_id,component_type,component_id,quantity,unit,sort_order)
SELECT rv.id,'ingredient',i.id,0.25,'oz',30
FROM recipe_versions rv JOIN recipes r ON r.id=rv.recipe_id JOIN flavors f ON f.id=r.flavor_id JOIN ingredients i ON i.sku='ING-MARSH'
WHERE f.slug='smores' AND rv.version_number=1
AND NOT EXISTS (SELECT 1 FROM recipe_items x WHERE x.recipe_version_id=rv.id AND x.component_type='ingredient' AND x.component_id=i.id);

INSERT INTO recipe_items (recipe_version_id,component_type,component_id,quantity,unit,sort_order)
SELECT rv.id,'ingredient',i.id,0.20,'oz',40
FROM recipe_versions rv JOIN recipes r ON r.id=rv.recipe_id JOIN flavors f ON f.id=r.flavor_id JOIN ingredients i ON i.sku='ING-GRAHAM'
WHERE f.slug='smores' AND rv.version_number=1
AND NOT EXISTS (SELECT 1 FROM recipe_items x WHERE x.recipe_version_id=rv.id AND x.component_type='ingredient' AND x.component_id=i.id);

INSERT INTO recipe_items (recipe_version_id,component_type,component_id,quantity,unit,sort_order)
SELECT rv.id,'ingredient',i.id,0.15,'oz',30
FROM recipe_versions rv JOIN recipes r ON r.id=rv.recipe_id JOIN flavors f ON f.id=r.flavor_id JOIN ingredients i ON i.sku='ING-SPRINKLES'
WHERE f.slug='birthday-cake' AND rv.version_number=1
AND NOT EXISTS (SELECT 1 FROM recipe_items x WHERE x.recipe_version_id=rv.id AND x.component_type='ingredient' AND x.component_id=i.id);

INSERT INTO recipe_items (recipe_version_id,component_type,component_id,quantity,unit,sort_order)
SELECT rv.id,'ingredient',i.id,0.25,'oz',30
FROM recipe_versions rv JOIN recipes r ON r.id=rv.recipe_id JOIN flavors f ON f.id=r.flavor_id JOIN ingredients i ON i.sku='ING-COOKIEBUTTER'
WHERE f.slug='cookie-butter' AND rv.version_number=1
AND NOT EXISTS (SELECT 1 FROM recipe_items x WHERE x.recipe_version_id=rv.id AND x.component_type='ingredient' AND x.component_id=i.id);

INSERT INTO recipe_items (recipe_version_id,component_type,component_id,quantity,unit,sort_order)
SELECT rv.id,'ingredient',i.id,0.20,'oz',40
FROM recipe_versions rv JOIN recipes r ON r.id=rv.recipe_id JOIN flavors f ON f.id=r.flavor_id JOIN ingredients i ON i.sku='ING-BISCOFF'
WHERE f.slug='cookie-butter' AND rv.version_number=1
AND NOT EXISTS (SELECT 1 FROM recipe_items x WHERE x.recipe_version_id=rv.id AND x.component_type='ingredient' AND x.component_id=i.id);

INSERT INTO recipe_items (recipe_version_id,component_type,component_id,quantity,unit,sort_order)
SELECT rv.id,'ingredient',i.id,0.05,'oz',30
FROM recipe_versions rv JOIN recipes r ON r.id=rv.recipe_id JOIN flavors f ON f.id=r.flavor_id JOIN ingredients i ON i.sku='ING-CINNAMON'
WHERE f.slug='cinnamon-crunch' AND rv.version_number=1
AND NOT EXISTS (SELECT 1 FROM recipe_items x WHERE x.recipe_version_id=rv.id AND x.component_type='ingredient' AND x.component_id=i.id);

INSERT INTO platform_meta (meta_key,meta_value) VALUES ('phase_2b','complete')
ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value);


INSERT IGNORE INTO permissions(permission_key,module,label) VALUES
('planning.view','Production Planning','View production plans'),
('planning.manage','Production Planning','Create and rebuild production plans'),
('planning.lock','Production Planning','Lock production plans'),
('orders.allocate_flavors','Orders','Allocate order items to flavors');

INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT r.id,p.id FROM roles r JOIN permissions p
WHERE r.slug IN ('owner','admin','manager','production-lead')
AND p.permission_key IN ('planning.view','planning.manage','planning.lock','orders.allocate_flavors');

INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT r.id,p.id FROM roles r JOIN permissions p
WHERE r.slug IN ('production-team','inventory-purchasing','packing')
AND p.permission_key='planning.view';

INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT r.id,p.id FROM roles r JOIN permissions p
WHERE r.slug='sales'
AND p.permission_key IN ('planning.view','orders.allocate_flavors');

INSERT INTO platform_meta(meta_key,meta_value) VALUES('phase_3a','complete')
ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value);


-- Phase 4 production-execution defaults.
INSERT IGNORE INTO waste_reasons(name,is_active) VALUES
('Production Defect',1),
('QC Failure',1),
('Damaged',1),
('Dropped',1),
('Over / Under Weight',1),
('Sample / Tasting',1);

INSERT IGNORE INTO permissions(permission_key,module,label) VALUES
('production.manage_materials','Production','Edit and commit batch material usage'),
('production.record_qc','Production','Record production QC checks'),
('production.record_waste','Production','Record finished-unit waste'),
('production.assign_team','Production','Assign team members to batches'),
('production.track_labor','Production','Clock labor against production batches'),
('production.complete_batch','Production','Complete batches and create finished inventory');

INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT r.id,p.id FROM roles r JOIN permissions p
WHERE r.slug IN ('owner','admin','manager','production-lead')
AND p.permission_key IN (
  'production.manage_materials','production.record_qc','production.record_waste',
  'production.assign_team','production.track_labor','production.complete_batch'
);

INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT r.id,p.id FROM roles r JOIN permissions p
WHERE r.slug='production-team'
AND p.permission_key IN ('production.record_qc','production.record_waste','production.track_labor');

INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT r.id,p.id FROM roles r JOIN permissions p
WHERE r.slug='packing'
AND p.permission_key IN ('production.record_qc','production.track_labor');

INSERT INTO platform_meta(meta_key,meta_value) VALUES('phase_4','complete')
ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value);


-- Fresh-install nested recipe version binding. Published parent recipes retain the exact
-- nested recipe version they were built against.
UPDATE recipe_items ri
JOIN recipe_versions parent_rv ON parent_rv.id=ri.recipe_version_id
SET ri.component_recipe_version_id=(
  SELECT child_rv.id
  FROM recipe_versions child_rv
  WHERE child_rv.recipe_id=ri.component_id
    AND child_rv.status='published'
  ORDER BY child_rv.version_number DESC
  LIMIT 1
)
WHERE ri.component_type='recipe'
  AND parent_rv.status IN ('published','retired')
  AND ri.component_recipe_version_id IS NULL;
