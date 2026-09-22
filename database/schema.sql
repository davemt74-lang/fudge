SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  job_title VARCHAR(160) NULL,
  phone VARCHAR(40) NULL,
  avatar_url VARCHAR(500) NULL,
  hourly_rate DECIMAL(10,2) NULL,
  status ENUM('invited','active','suspended','inactive','former') NOT NULL DEFAULT 'active',
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS roles (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  slug VARCHAR(120) NOT NULL UNIQUE,
  description VARCHAR(500) NULL,
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permissions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  permission_key VARCHAR(160) NOT NULL UNIQUE,
  module VARCHAR(100) NOT NULL,
  label VARCHAR(160) NOT NULL,
  description VARCHAR(500) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
  role_id BIGINT UNSIGNED NOT NULL,
  permission_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY(role_id,permission_id),
  CONSTRAINT fk_rp_role FOREIGN KEY(role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_rp_perm FOREIGN KEY(permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_roles (
  user_id BIGINT UNSIGNED NOT NULL,
  role_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY(user_id,role_id),
  CONSTRAINT fk_ur_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ur_role FOREIGN KEY(role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_permission_overrides (
  user_id BIGINT UNSIGNED NOT NULL,
  permission_id BIGINT UNSIGNED NOT NULL,
  effect ENUM('allow','deny') NOT NULL,
  PRIMARY KEY(user_id,permission_id),
  CONSTRAINT fk_upo_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_upo_perm FOREIGN KEY(permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS units (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  symbol VARCHAR(20) NOT NULL UNIQUE,
  unit_type VARCHAR(50) NOT NULL DEFAULT 'count',
  base_multiplier DECIMAL(16,6) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS allergens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ingredient_categories (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL UNIQUE,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ingredients (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  sku VARCHAR(80) NULL UNIQUE,
  category_id BIGINT UNSIGNED NULL,
  inventory_unit VARCHAR(20) NOT NULL DEFAULT 'oz',
  reorder_point DECIMAL(14,3) NOT NULL DEFAULT 0,
  target_stock DECIMAL(14,3) NOT NULL DEFAULT 0,
  storage_location VARCHAR(160) NULL,
  shelf_life_days INT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_ing_cat FOREIGN KEY(category_id) REFERENCES ingredient_categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ingredient_allergens (
  ingredient_id BIGINT UNSIGNED NOT NULL,
  allergen_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY(ingredient_id,allergen_id),
  CONSTRAINT fk_ia_ing FOREIGN KEY(ingredient_id) REFERENCES ingredients(id) ON DELETE CASCADE,
  CONSTRAINT fk_ia_allergen FOREIGN KEY(allergen_id) REFERENCES allergens(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS packaging_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  sku VARCHAR(80) NULL UNIQUE,
  inventory_unit VARCHAR(20) NOT NULL DEFAULT 'each',
  current_unit_cost DECIMAL(12,4) NOT NULL DEFAULT 0,
  reorder_point DECIMAL(14,3) NOT NULL DEFAULT 0,
  target_stock DECIMAL(14,3) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS suppliers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  website VARCHAR(500) NULL,
  phone VARCHAR(50) NULL,
  email VARCHAR(190) NULL,
  notes TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS supplier_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id BIGINT UNSIGNED NOT NULL,
  item_type ENUM('ingredient','packaging') NOT NULL,
  item_id BIGINT UNSIGNED NOT NULL,
  supplier_sku VARCHAR(120) NULL,
  product_url VARCHAR(1000) NULL,
  package_description VARCHAR(255) NULL,
  package_quantity DECIMAL(14,4) NOT NULL DEFAULT 1,
  package_unit VARCHAR(30) NOT NULL DEFAULT 'each',
  package_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  unit_cost DECIMAL(14,6) NOT NULL DEFAULT 0,
  is_preferred TINYINT(1) NOT NULL DEFAULT 0,
  last_price_update DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_si_supplier FOREIGN KEY(supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
  INDEX idx_supplier_item_ref(item_type,item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS supplier_price_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_item_id BIGINT UNSIGNED NOT NULL,
  old_price DECIMAL(12,2) NULL,
  old_package_quantity DECIMAL(14,4) NULL,
  old_unit_cost DECIMAL(14,6) NULL,
  new_price DECIMAL(12,2) NOT NULL,
  package_quantity DECIMAL(14,4) NOT NULL,
  package_unit VARCHAR(30) NULL,
  unit_cost DECIMAL(14,6) NOT NULL,
  source_reference VARCHAR(1000) NULL,
  notes VARCHAR(1000) NULL,
  effective_at DATETIME NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sph_item FOREIGN KEY(supplier_item_id) REFERENCES supplier_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_sph_user FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ingredient_lots (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ingredient_id BIGINT UNSIGNED NOT NULL,
  supplier_item_id BIGINT UNSIGNED NULL,
  lot_number VARCHAR(120) NULL,
  received_at DATETIME NULL,
  expires_at DATETIME NULL,
  unit_cost DECIMAL(14,6) NULL,
  notes VARCHAR(500) NULL,
  CONSTRAINT fk_lot_ing FOREIGN KEY(ingredient_id) REFERENCES ingredients(id) ON DELETE CASCADE,
  CONSTRAINT fk_lot_si FOREIGN KEY(supplier_item_id) REFERENCES supplier_items(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inventory_transactions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_type ENUM('ingredient','packaging') NOT NULL,
  item_id BIGINT UNSIGNED NOT NULL,
  lot_id BIGINT UNSIGNED NULL,
  quantity_delta DECIMAL(16,4) NOT NULL,
  unit VARCHAR(30) NOT NULL,
  reason VARCHAR(120) NOT NULL,
  reference_type VARCHAR(80) NULL,
  reference_id BIGINT UNSIGNED NULL,
  notes VARCHAR(500) NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_inventory_item(item_type,item_id),
  CONSTRAINT fk_inv_user FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS flavors (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  slug VARCHAR(160) NOT NULL UNIQUE,
  description TEXT NULL,
  image_url VARCHAR(1000) NULL,
  target_weight_oz DECIMAL(8,3) NULL,
  seasonal TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS recipes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  recipe_type ENUM('base','component','finished') NOT NULL DEFAULT 'finished',
  flavor_id BIGINT UNSIGNED NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_recipe_flavor FOREIGN KEY(flavor_id) REFERENCES flavors(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS recipe_versions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  recipe_id BIGINT UNSIGNED NOT NULL,
  version_number INT NOT NULL,
  yield_quantity DECIMAL(12,3) NOT NULL DEFAULT 1,
  yield_unit VARCHAR(30) NOT NULL DEFAULT 'each',
  notes TEXT NULL,
  status ENUM('draft','published','retired') NOT NULL DEFAULT 'draft',
  published_at DATETIME NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_recipe_version(recipe_id,version_number),
  CONSTRAINT fk_rv_recipe FOREIGN KEY(recipe_id) REFERENCES recipes(id) ON DELETE CASCADE,
  CONSTRAINT fk_rv_user FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS recipe_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  recipe_version_id BIGINT UNSIGNED NOT NULL,
  component_type ENUM('ingredient','recipe','packaging') NOT NULL,
  component_id BIGINT UNSIGNED NOT NULL,
  quantity DECIMAL(14,4) NOT NULL,
  unit VARCHAR(30) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_recipe_component(recipe_version_id,component_type,component_id),
  CONSTRAINT fk_ri_version FOREIGN KEY(recipe_version_id) REFERENCES recipe_versions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS products (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  sku VARCHAR(100) NOT NULL UNIQUE,
  product_type ENUM('single','box','catering','wholesale') NOT NULL,
  box_capacity INT NULL,
  price DECIMAL(12,2) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS customers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  first_name VARCHAR(120) NOT NULL,
  last_name VARCHAR(120) NULL,
  email VARCHAR(190) NULL,
  phone VARCHAR(50) NULL,
  notes TEXT NULL,
  marketing_consent TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS orders (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_number VARCHAR(80) NOT NULL UNIQUE,
  customer_id BIGINT UNSIGNED NULL,
  status ENUM('new','paid','production','packing','ready','fulfilled','cancelled') NOT NULL DEFAULT 'new',
  sales_channel VARCHAR(80) NOT NULL DEFAULT 'manual',
  fulfillment_type ENUM('pickup','delivery','shipping') NOT NULL DEFAULT 'pickup',
  fulfillment_at DATETIME NULL,
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
  discount DECIMAL(12,2) NOT NULL DEFAULT 0,
  tax DECIMAL(12,2) NOT NULL DEFAULT 0,
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0,
  notes TEXT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_order_customer FOREIGN KEY(customer_id) REFERENCES customers(id) ON DELETE SET NULL,
  CONSTRAINT fk_order_user FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  quantity INT NOT NULL DEFAULT 1,
  unit_price DECIMAL(12,2) NOT NULL,
  line_total DECIMAL(12,2) NOT NULL,
  CONSTRAINT fk_oi_order FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_oi_product FOREIGN KEY(product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_item_flavors (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_item_id BIGINT UNSIGNED NOT NULL,
  flavor_id BIGINT UNSIGNED NOT NULL,
  quantity INT NOT NULL DEFAULT 1,
  CONSTRAINT fk_oif_item FOREIGN KEY(order_item_id) REFERENCES order_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_oif_flavor FOREIGN KEY(flavor_id) REFERENCES flavors(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS production_batches (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  batch_code VARCHAR(100) NOT NULL UNIQUE,
  scheduled_for DATE NULL,
  status ENUM('scheduled','prep','mixed','molded','chilling','glazed','topped','wrapped','boxed','completed','cancelled') NOT NULL DEFAULT 'scheduled',
  notes TEXT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_pb_user FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS production_batch_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  batch_id BIGINT UNSIGNED NOT NULL,
  flavor_id BIGINT UNSIGNED NOT NULL,
  recipe_version_id BIGINT UNSIGNED NULL,
  planned_quantity INT NOT NULL,
  actual_quantity INT NULL,
  waste_quantity INT NOT NULL DEFAULT 0,
  CONSTRAINT fk_pbi_batch FOREIGN KEY(batch_id) REFERENCES production_batches(id) ON DELETE CASCADE,
  CONSTRAINT fk_pbi_flavor FOREIGN KEY(flavor_id) REFERENCES flavors(id),
  CONSTRAINT fk_pbi_recipe FOREIGN KEY(recipe_version_id) REFERENCES recipe_versions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS finished_inventory (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  flavor_id BIGINT UNSIGNED NOT NULL,
  batch_id BIGINT UNSIGNED NULL,
  status ENUM('available','reserved','sold','waste','sample') NOT NULL DEFAULT 'available',
  quantity INT NOT NULL DEFAULT 0,
  made_at DATETIME NULL,
  sell_by_at DATETIME NULL,
  CONSTRAINT fk_fi_flavor FOREIGN KEY(flavor_id) REFERENCES flavors(id),
  CONSTRAINT fk_fi_batch FOREIGN KEY(batch_id) REFERENCES production_batches(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS llm_providers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  provider_type ENUM('openai_compatible','anthropic','local') NOT NULL,
  base_url VARCHAR(500) NOT NULL,
  default_model VARCHAR(190) NULL,
  timeout_seconds INT NOT NULL DEFAULT 30,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  is_default TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS llm_credentials (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  provider_id BIGINT UNSIGNED NOT NULL UNIQUE,
  encrypted_secret TEXT NOT NULL,
  secret_last4 VARCHAR(8) NULL,
  updated_by BIGINT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_llmc_provider FOREIGN KEY(provider_id) REFERENCES llm_providers(id) ON DELETE CASCADE,
  CONSTRAINT fk_llmc_user FOREIGN KEY(updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS llm_feature_settings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  feature_key VARCHAR(160) NOT NULL UNIQUE,
  provider_id BIGINT UNSIGNED NULL,
  model VARCHAR(190) NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  CONSTRAINT fk_lfs_provider FOREIGN KEY(provider_id) REFERENCES llm_providers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS llm_usage_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  provider_id BIGINT UNSIGNED NULL,
  feature_key VARCHAR(160) NOT NULL,
  model VARCHAR(190) NULL,
  user_id BIGINT UNSIGNED NULL,
  input_tokens INT NULL,
  output_tokens INT NULL,
  latency_ms INT NULL,
  status ENUM('success','error') NOT NULL,
  error_message VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_lul_provider FOREIGN KEY(provider_id) REFERENCES llm_providers(id) ON DELETE SET NULL,
  CONSTRAINT fk_lul_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  action VARCHAR(190) NOT NULL,
  entity_type VARCHAR(100) NULL,
  entity_id BIGINT UNSIGNED NULL,
  before_json JSON NULL,
  after_json JSON NULL,
  ip_address VARCHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_audit_entity(entity_type,entity_id),
  CONSTRAINT fk_audit_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS schema_migrations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  version VARCHAR(64) NOT NULL UNIQUE,
  name VARCHAR(190) NOT NULL,
  checksum CHAR(64) NULL,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS platform_meta (
  meta_key VARCHAR(120) PRIMARY KEY,
  meta_value TEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS purchase_orders (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  po_number VARCHAR(80) NOT NULL UNIQUE,
  supplier_id BIGINT UNSIGNED NOT NULL,
  status ENUM('draft','submitted','partial','received','cancelled') NOT NULL DEFAULT 'draft',
  ordered_at DATETIME NULL,
  expected_at DATE NULL,
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  notes TEXT NULL,
  created_by BIGINT UNSIGNED NULL,
  approved_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_po_supplier FOREIGN KEY(supplier_id) REFERENCES suppliers(id),
  CONSTRAINT fk_po_created_by FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_po_approved_by FOREIGN KEY(approved_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_po_status(status),
  INDEX idx_po_supplier(supplier_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchase_order_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  purchase_order_id BIGINT UNSIGNED NOT NULL,
  supplier_item_id BIGINT UNSIGNED NOT NULL,
  item_type ENUM('ingredient','packaging') NOT NULL,
  item_id BIGINT UNSIGNED NOT NULL,
  description VARCHAR(255) NOT NULL,
  ordered_packages DECIMAL(14,4) NOT NULL DEFAULT 1,
  package_quantity DECIMAL(14,4) NOT NULL DEFAULT 1,
  package_unit VARCHAR(30) NOT NULL DEFAULT 'each',
  package_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  line_total DECIMAL(12,2) NOT NULL DEFAULT 0,
  received_packages DECIMAL(14,4) NOT NULL DEFAULT 0,
  received_quantity DECIMAL(16,4) NOT NULL DEFAULT 0,
  inventory_unit VARCHAR(30) NOT NULL DEFAULT 'each',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_poi_po FOREIGN KEY(purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_poi_supplier_item FOREIGN KEY(supplier_item_id) REFERENCES supplier_items(id),
  INDEX idx_poi_po(purchase_order_id),
  INDEX idx_poi_item(item_type,item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS receiving_sessions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  purchase_order_id BIGINT UNSIGNED NOT NULL,
  received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  received_by BIGINT UNSIGNED NULL,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_receiving_po FOREIGN KEY(purchase_order_id) REFERENCES purchase_orders(id),
  CONSTRAINT fk_receiving_user FOREIGN KEY(received_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_receiving_po(purchase_order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS receiving_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  receiving_session_id BIGINT UNSIGNED NOT NULL,
  purchase_order_item_id BIGINT UNSIGNED NOT NULL,
  received_packages DECIMAL(14,4) NOT NULL DEFAULT 0,
  received_quantity DECIMAL(16,4) NOT NULL DEFAULT 0,
  lot_number VARCHAR(120) NULL,
  expires_at DATETIME NULL,
  unit_cost DECIMAL(14,6) NOT NULL DEFAULT 0,
  ingredient_lot_id BIGINT UNSIGNED NULL,
  inventory_transaction_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ri_session FOREIGN KEY(receiving_session_id) REFERENCES receiving_sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_ri_po_item FOREIGN KEY(purchase_order_item_id) REFERENCES purchase_order_items(id),
  CONSTRAINT fk_ri_lot FOREIGN KEY(ingredient_lot_id) REFERENCES ingredient_lots(id) ON DELETE SET NULL,
  CONSTRAINT fk_ri_inventory_tx FOREIGN KEY(inventory_transaction_id) REFERENCES inventory_transactions(id) ON DELETE SET NULL,
  INDEX idx_ri_session(receiving_session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_counts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  count_number VARCHAR(80) NOT NULL UNIQUE,
  status ENUM('open','completed','cancelled') NOT NULL DEFAULT 'open',
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  created_by BIGINT UNSIGNED NULL,
  completed_by BIGINT UNSIGNED NULL,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ic_created_by FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_ic_completed_by FOREIGN KEY(completed_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_ic_status(status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_count_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  inventory_count_id BIGINT UNSIGNED NOT NULL,
  item_type ENUM('ingredient','packaging') NOT NULL,
  item_id BIGINT UNSIGNED NOT NULL,
  expected_quantity DECIMAL(16,4) NOT NULL DEFAULT 0,
  counted_quantity DECIMAL(16,4) NULL,
  variance_quantity DECIMAL(16,4) NULL,
  unit VARCHAR(30) NOT NULL,
  counted_by BIGINT UNSIGNED NULL,
  counted_at DATETIME NULL,
  adjustment_transaction_id BIGINT UNSIGNED NULL,
  CONSTRAINT fk_ici_count FOREIGN KEY(inventory_count_id) REFERENCES inventory_counts(id) ON DELETE CASCADE,
  CONSTRAINT fk_ici_counted_by FOREIGN KEY(counted_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_ici_adjustment FOREIGN KEY(adjustment_transaction_id) REFERENCES inventory_transactions(id) ON DELETE SET NULL,
  UNIQUE KEY uq_count_item(inventory_count_id,item_type,item_id),
  INDEX idx_ici_item(item_type,item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS product_packaging_components (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id BIGINT UNSIGNED NOT NULL,
  packaging_item_id BIGINT UNSIGNED NOT NULL,
  quantity DECIMAL(14,4) NOT NULL DEFAULT 1,
  unit VARCHAR(30) NOT NULL DEFAULT 'each',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_product_packaging(product_id,packaging_item_id),
  CONSTRAINT fk_ppc_product FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_ppc_packaging FOREIGN KEY(packaging_item_id) REFERENCES packaging_items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recipe_cost_snapshots (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  recipe_version_id BIGINT UNSIGNED NOT NULL,
  material_cost DECIMAL(14,6) NOT NULL,
  yield_quantity DECIMAL(14,4) NOT NULL,
  unit_cost DECIMAL(14,6) NOT NULL,
  is_complete TINYINT(1) NOT NULL DEFAULT 1,
  warning_count INT NOT NULL DEFAULT 0,
  trigger_type VARCHAR(80) NOT NULL DEFAULT 'manual',
  trigger_reference VARCHAR(190) NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_rcs_recipe_version FOREIGN KEY(recipe_version_id) REFERENCES recipe_versions(id) ON DELETE CASCADE,
  CONSTRAINT fk_rcs_user FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_rcs_recipe_version(recipe_version_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_cost_snapshots (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id BIGINT UNSIGNED NOT NULL,
  direct_cogs DECIMAL(14,6) NOT NULL,
  selling_price DECIMAL(12,2) NOT NULL,
  gross_profit DECIMAL(14,6) NOT NULL,
  margin_pct DECIMAL(9,4) NOT NULL,
  is_complete TINYINT(1) NOT NULL DEFAULT 1,
  warning_count INT NOT NULL DEFAULT 0,
  flavor_cost_basis VARCHAR(40) NOT NULL DEFAULT 'average',
  trigger_type VARCHAR(80) NOT NULL DEFAULT 'manual',
  trigger_reference VARCHAR(190) NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pcs_product FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_pcs_user FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_pcs_product(product_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
