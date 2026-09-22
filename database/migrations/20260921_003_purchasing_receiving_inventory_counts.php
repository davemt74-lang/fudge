<?php
return [
    'version' => '20260921_003',
    'name' => 'Add purchasing receiving lots and inventory counts',
    'up' => static function(PDO $pdo): void {
        $pdo->exec("
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $permissions = [
            ['purchasing.view','Purchasing','View purchase orders'],
            ['purchasing.manage','Purchasing','Create and edit purchase orders'],
            ['purchasing.submit','Purchasing','Submit purchase orders'],
            ['purchasing.receive','Purchasing','Receive purchase orders'],
            ['purchasing.cancel','Purchasing','Cancel purchase orders'],
            ['lots.view','Inventory','View ingredient lots and expiration'],
            ['lots.manage','Inventory','Manage ingredient lots'],
        ];
        $stmt = $pdo->prepare('INSERT IGNORE INTO permissions (permission_key,module,label) VALUES (?,?,?)');
        foreach ($permissions as $permission) $stmt->execute($permission);

        $grant = static function(PDO $pdo, string $roleSlug, array $keys): void {
            if (!$keys) return;
            $marks = implode(',', array_fill(0, count($keys), '?'));
            $sql = "INSERT IGNORE INTO role_permissions (role_id,permission_id)
                    SELECT r.id,p.id
                    FROM roles r JOIN permissions p
                    WHERE r.slug=? AND p.permission_key IN ($marks)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge([$roleSlug], $keys));
        };

        $ownerKeys = array_column($permissions, 0);
        $grant($pdo, 'owner', $ownerKeys);

        // Repair the original foundation seed: Admin is intended to be full-access.
        $pdo->exec("INSERT IGNORE INTO role_permissions (role_id,permission_id)
                    SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.slug='admin'");

        $grant($pdo, 'admin', $ownerKeys);
        $grant($pdo, 'manager', $ownerKeys);
        $grant($pdo, 'inventory-purchasing', $ownerKeys);
        $grant($pdo, 'bookkeeping', ['purchasing.view','lots.view']);
        $grant($pdo, 'viewer', ['purchasing.view','lots.view']);

        $stmt = $pdo->prepare("INSERT INTO platform_meta (meta_key,meta_value) VALUES ('phase_2a','complete')
                               ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        $stmt->execute();
    },
];
