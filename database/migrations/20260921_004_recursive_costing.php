<?php
return [
    'version' => '20260921_004',
    'name' => 'Add recursive recipe costing and product cost intelligence',
    'up' => static function(PDO $pdo): void {
        $hasColumn = static function(PDO $pdo, string $table, string $column): bool {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?'
            );
            $stmt->execute([$table,$column]);
            return (int)$stmt->fetchColumn() > 0;
        };

        if (!$hasColumn($pdo,'supplier_price_history','old_package_quantity')) {
            $pdo->exec('ALTER TABLE supplier_price_history ADD COLUMN old_package_quantity DECIMAL(14,4) NULL AFTER old_price');
        }
        if (!$hasColumn($pdo,'supplier_price_history','old_unit_cost')) {
            $pdo->exec('ALTER TABLE supplier_price_history ADD COLUMN old_unit_cost DECIMAL(14,6) NULL AFTER old_package_quantity');
        }
        if (!$hasColumn($pdo,'supplier_price_history','package_unit')) {
            $pdo->exec('ALTER TABLE supplier_price_history ADD COLUMN package_unit VARCHAR(30) NULL AFTER package_quantity');
        }

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='recipe_items' AND INDEX_NAME='uq_recipe_component'"
        );
        $stmt->execute();
        if ((int)$stmt->fetchColumn() === 0) {
            $pdo->exec(
                "DELETE a FROM recipe_items a
                 JOIN recipe_items b
                   ON a.recipe_version_id=b.recipe_version_id
                  AND a.component_type=b.component_type
                  AND a.component_id=b.component_id
                  AND a.id>b.id"
            );
            $pdo->exec(
                'ALTER TABLE recipe_items
                 ADD UNIQUE KEY uq_recipe_component(recipe_version_id,component_type,component_id)'
            );
        }

        $pdo->exec("
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $permissions = [
            ['costing.view','Costing','View recipe and product costs'],
            ['costing.snapshot','Costing','Capture cost snapshots'],
            ['costing.view_margin','Costing','View product margin'],
        ];
        $stmt = $pdo->prepare('INSERT IGNORE INTO permissions (permission_key,module,label) VALUES (?,?,?)');
        foreach ($permissions as $permission) $stmt->execute($permission);

        $grant = static function(PDO $pdo, string $roleSlug, array $keys): void {
            if (!$keys) return;
            $marks = implode(',', array_fill(0,count($keys),'?'));
            $stmt = $pdo->prepare(
                "INSERT IGNORE INTO role_permissions (role_id,permission_id)
                 SELECT r.id,p.id FROM roles r JOIN permissions p
                 WHERE r.slug=? AND p.permission_key IN ($marks)"
            );
            $stmt->execute(array_merge([$roleSlug],$keys));
        };
        $all = array_column($permissions,0);
        $grant($pdo,'owner',$all);
        $grant($pdo,'admin',$all);
        $grant($pdo,'manager',$all);
        $grant($pdo,'bookkeeping',['costing.view','costing.view_margin']);
        $grant($pdo,'inventory-purchasing',['costing.view']);
        $grant($pdo,'production-lead',['costing.view']);

        $pdo->exec("
            INSERT IGNORE INTO product_packaging_components (product_id,packaging_item_id,quantity,unit)
            SELECT pr.id,p.id,1,'each'
            FROM products pr JOIN packaging_items p
            WHERE pr.sku='FD-6' AND p.sku='PKG-BOX6'
        ");
        $pdo->exec("
            INSERT IGNORE INTO product_packaging_components (product_id,packaging_item_id,quantity,unit)
            SELECT pr.id,p.id,1,'each'
            FROM products pr JOIN packaging_items p
            WHERE pr.sku='FD-12' AND p.sku='PKG-BOX12'
        ");

        $stmt = $pdo->prepare(
            "INSERT INTO platform_meta (meta_key,meta_value) VALUES ('phase_2b','complete')
             ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)"
        );
        $stmt->execute();
    },
];
