<?php
return [
    'version' => '20260921_005',
    'name' => 'Add order demand and production planning',
    'up' => static function(PDO $pdo): void {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS production_plans (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                plan_code VARCHAR(100) NOT NULL UNIQUE,
                start_date DATE NOT NULL,
                end_date DATE NOT NULL,
                status ENUM('draft','locked','in_production','completed','cancelled') NOT NULL DEFAULT 'draft',
                unallocated_units INT NOT NULL DEFAULT 0,
                blocking_issue_count INT NOT NULL DEFAULT 0,
                warning_count INT NOT NULL DEFAULT 0,
                source_fingerprint CHAR(64) NULL,
                built_at DATETIME NULL,
                notes TEXT NULL,
                created_by BIGINT UNSIGNED NULL,
                locked_by BIGINT UNSIGNED NULL,
                locked_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_plan_created_by FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_plan_locked_by FOREIGN KEY(locked_by) REFERENCES users(id) ON DELETE SET NULL,
                INDEX idx_plan_dates(start_date,end_date),
                INDEX idx_plan_status(status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS production_plan_orders (
                production_plan_id BIGINT UNSIGNED NOT NULL,
                order_id BIGINT UNSIGNED NOT NULL,
                PRIMARY KEY(production_plan_id,order_id),
                CONSTRAINT fk_ppo_plan FOREIGN KEY(production_plan_id) REFERENCES production_plans(id) ON DELETE CASCADE,
                CONSTRAINT fk_ppo_order FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS production_plan_items (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                production_plan_id BIGINT UNSIGNED NOT NULL,
                flavor_id BIGINT UNSIGNED NOT NULL,
                required_quantity INT NOT NULL DEFAULT 0,
                planned_quantity INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_plan_flavor(production_plan_id,flavor_id),
                CONSTRAINT fk_ppi_plan FOREIGN KEY(production_plan_id) REFERENCES production_plans(id) ON DELETE CASCADE,
                CONSTRAINT fk_ppi_flavor FOREIGN KEY(flavor_id) REFERENCES flavors(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS production_plan_requirements (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                production_plan_id BIGINT UNSIGNED NOT NULL,
                item_type ENUM('ingredient','packaging') NOT NULL,
                item_id BIGINT UNSIGNED NOT NULL,
                required_quantity DECIMAL(16,4) NOT NULL DEFAULT 0,
                on_hand_at_build DECIMAL(16,4) NOT NULL DEFAULT 0,
                on_order_at_build DECIMAL(16,4) NOT NULL DEFAULT 0,
                shortage_at_build DECIMAL(16,4) NOT NULL DEFAULT 0,
                unit VARCHAR(30) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_plan_requirement(production_plan_id,item_type,item_id),
                CONSTRAINT fk_ppr_plan FOREIGN KEY(production_plan_id) REFERENCES production_plans(id) ON DELETE CASCADE,
                INDEX idx_ppr_item(item_type,item_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS production_plan_issues (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                production_plan_id BIGINT UNSIGNED NOT NULL,
                severity ENUM('blocking','warning') NOT NULL,
                issue_code VARCHAR(100) NOT NULL,
                message VARCHAR(1000) NOT NULL,
                order_id BIGINT UNSIGNED NULL,
                order_item_id BIGINT UNSIGNED NULL,
                flavor_id BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_ppissue_plan FOREIGN KEY(production_plan_id) REFERENCES production_plans(id) ON DELETE CASCADE,
                CONSTRAINT fk_ppissue_order FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
                CONSTRAINT fk_ppissue_order_item FOREIGN KEY(order_item_id) REFERENCES order_items(id) ON DELETE CASCADE,
                CONSTRAINT fk_ppissue_flavor FOREIGN KEY(flavor_id) REFERENCES flavors(id) ON DELETE SET NULL,
                INDEX idx_ppissue_plan(production_plan_id,severity)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='order_item_flavors' AND INDEX_NAME='uq_order_item_flavor'"
        );
        $stmt->execute();
        if ((int)$stmt->fetchColumn() === 0) {
            $pdo->exec(
                "UPDATE order_item_flavors a
                 JOIN (
                   SELECT order_item_id,flavor_id,MIN(id) keep_id,SUM(quantity) total_qty
                   FROM order_item_flavors
                   GROUP BY order_item_id,flavor_id
                   HAVING COUNT(*)>1
                 ) d ON d.order_item_id=a.order_item_id AND d.flavor_id=a.flavor_id AND a.id=d.keep_id
                 SET a.quantity=d.total_qty"
            );
            $pdo->exec(
                "DELETE a FROM order_item_flavors a
                 JOIN order_item_flavors b
                   ON a.order_item_id=b.order_item_id
                  AND a.flavor_id=b.flavor_id
                  AND a.id>b.id"
            );
            $pdo->exec(
                'ALTER TABLE order_item_flavors
                 ADD UNIQUE KEY uq_order_item_flavor(order_item_id,flavor_id)'
            );
        }

        $columnStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='production_batches' AND COLUMN_NAME='production_plan_id'"
        );
        $columnStmt->execute();
        if ((int)$columnStmt->fetchColumn() === 0) {
            $pdo->exec(
                'ALTER TABLE production_batches
                 ADD COLUMN production_plan_id BIGINT UNSIGNED NULL AFTER id,
                 ADD CONSTRAINT fk_batch_plan FOREIGN KEY(production_plan_id) REFERENCES production_plans(id) ON DELETE SET NULL,
                 ADD UNIQUE KEY uq_batch_plan(production_plan_id)'
            );
        }

        $permissions = [
            ['planning.view','Production Planning','View production plans'],
            ['planning.manage','Production Planning','Create and rebuild production plans'],
            ['planning.lock','Production Planning','Lock production plans'],
            ['orders.allocate_flavors','Orders','Allocate order items to flavors'],
        ];
        $stmt = $pdo->prepare('INSERT IGNORE INTO permissions(permission_key,module,label) VALUES(?,?,?)');
        foreach ($permissions as $permission) $stmt->execute($permission);

        $grant = static function(PDO $pdo, string $roleSlug, array $keys): void {
            if (!$keys) return;
            $marks = implode(',',array_fill(0,count($keys),'?'));
            $stmt = $pdo->prepare(
                "INSERT IGNORE INTO role_permissions(role_id,permission_id)
                 SELECT r.id,p.id FROM roles r JOIN permissions p
                 WHERE r.slug=? AND p.permission_key IN ($marks)"
            );
            $stmt->execute(array_merge([$roleSlug],$keys));
        };
        $all=array_column($permissions,0);
        $grant($pdo,'owner',$all);
        $grant($pdo,'admin',$all);
        $grant($pdo,'manager',$all);
        $grant($pdo,'production-lead',$all);
        $grant($pdo,'production-team',['planning.view']);
        $grant($pdo,'inventory-purchasing',['planning.view']);
        $grant($pdo,'packing',['planning.view']);
        $grant($pdo,'sales',['planning.view','orders.allocate_flavors']);

        $stmt=$pdo->prepare(
            "INSERT INTO platform_meta(meta_key,meta_value) VALUES('phase_3a','complete')
             ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)"
        );
        $stmt->execute();
    },
];
