<?php
return [
    'version' => '20260921_006',
    'name' => 'Add production execution QC labor and material consumption',
    'up' => static function(PDO $pdo): void {
        $columnExists = static function(PDO $pdo, string $table, string $column): bool {
            $stmt=$pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?'
            );
            $stmt->execute([$table,$column]);
            return (int)$stmt->fetchColumn()>0;
        };
        $indexExists = static function(PDO $pdo, string $table, string $index): bool {
            $stmt=$pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?'
            );
            $stmt->execute([$table,$index]);
            return (int)$stmt->fetchColumn()>0;
        };
        $fkExists = static function(PDO $pdo, string $table, string $constraint): bool {
            $stmt=$pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=?'
            );
            $stmt->execute([$table,$constraint]);
            return (int)$stmt->fetchColumn()>0;
        };

        if(!$indexExists($pdo,'production_batch_items','uq_batch_flavor')){
            $pdo->exec(
                "UPDATE production_batch_items a
                 JOIN (
                   SELECT batch_id,flavor_id,MIN(id) keep_id,
                          SUM(planned_quantity) planned_total,
                          SUM(COALESCE(actual_quantity,0)) actual_total,
                          SUM(waste_quantity) waste_total
                   FROM production_batch_items
                   GROUP BY batch_id,flavor_id
                   HAVING COUNT(*)>1
                 ) d ON d.batch_id=a.batch_id AND d.flavor_id=a.flavor_id AND a.id=d.keep_id
                 SET a.planned_quantity=d.planned_total,
                     a.actual_quantity=CASE WHEN d.actual_total=0 THEN NULL ELSE d.actual_total END,
                     a.waste_quantity=d.waste_total"
            );
            $pdo->exec(
                "DELETE a FROM production_batch_items a
                 JOIN production_batch_items b
                   ON a.batch_id=b.batch_id
                  AND a.flavor_id=b.flavor_id
                  AND a.id>b.id"
            );
            $pdo->exec(
                'ALTER TABLE production_batch_items
                 ADD UNIQUE KEY uq_batch_flavor(batch_id,flavor_id)'
            );
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS production_batch_steps (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                batch_id BIGINT UNSIGNED NOT NULL,
                step_key VARCHAR(60) NOT NULL,
                label VARCHAR(120) NOT NULL,
                sort_order INT NOT NULL,
                status ENUM('pending','in_progress','completed','skipped') NOT NULL DEFAULT 'pending',
                started_at DATETIME NULL,
                completed_at DATETIME NULL,
                started_by BIGINT UNSIGNED NULL,
                completed_by BIGINT UNSIGNED NULL,
                notes VARCHAR(1000) NULL,
                UNIQUE KEY uq_batch_step(batch_id,step_key),
                CONSTRAINT fk_pbs_batch FOREIGN KEY(batch_id) REFERENCES production_batches(id) ON DELETE CASCADE,
                CONSTRAINT fk_pbs_started_by FOREIGN KEY(started_by) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_pbs_completed_by FOREIGN KEY(completed_by) REFERENCES users(id) ON DELETE SET NULL,
                INDEX idx_pbs_batch_status(batch_id,status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS production_batch_materials (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                batch_id BIGINT UNSIGNED NOT NULL,
                item_type ENUM('ingredient','packaging') NOT NULL,
                item_id BIGINT UNSIGNED NOT NULL,
                theoretical_quantity DECIMAL(16,4) NOT NULL DEFAULT 0,
                actual_quantity DECIMAL(16,4) NOT NULL DEFAULT 0,
                variance_quantity DECIMAL(16,4) NOT NULL DEFAULT 0,
                unit VARCHAR(30) NOT NULL,
                status ENUM('planned','committed') NOT NULL DEFAULT 'planned',
                committed_at DATETIME NULL,
                committed_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_batch_material(batch_id,item_type,item_id),
                CONSTRAINT fk_pbm_batch FOREIGN KEY(batch_id) REFERENCES production_batches(id) ON DELETE CASCADE,
                CONSTRAINT fk_pbm_user FOREIGN KEY(committed_by) REFERENCES users(id) ON DELETE SET NULL,
                INDEX idx_pbm_item(item_type,item_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS production_consumption_allocations (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                batch_material_id BIGINT UNSIGNED NOT NULL,
                lot_id BIGINT UNSIGNED NULL,
                quantity DECIMAL(16,4) NOT NULL,
                unit VARCHAR(30) NOT NULL,
                inventory_transaction_id BIGINT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_pca_material FOREIGN KEY(batch_material_id) REFERENCES production_batch_materials(id) ON DELETE CASCADE,
                CONSTRAINT fk_pca_lot FOREIGN KEY(lot_id) REFERENCES ingredient_lots(id) ON DELETE SET NULL,
                CONSTRAINT fk_pca_tx FOREIGN KEY(inventory_transaction_id) REFERENCES inventory_transactions(id),
                INDEX idx_pca_material(batch_material_id),
                INDEX idx_pca_lot(lot_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS production_qc_checks (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                batch_id BIGINT UNSIGNED NOT NULL,
                batch_item_id BIGINT UNSIGNED NULL,
                check_key VARCHAR(80) NOT NULL,
                label VARCHAR(190) NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                result ENUM('pending','pass','fail','na') NOT NULL DEFAULT 'pending',
                notes VARCHAR(1000) NULL,
                checked_by BIGINT UNSIGNED NULL,
                checked_at DATETIME NULL,
                UNIQUE KEY uq_qc_check(batch_id,batch_item_id,check_key),
                CONSTRAINT fk_pqc_batch FOREIGN KEY(batch_id) REFERENCES production_batches(id) ON DELETE CASCADE,
                CONSTRAINT fk_pqc_batch_item FOREIGN KEY(batch_item_id) REFERENCES production_batch_items(id) ON DELETE CASCADE,
                CONSTRAINT fk_pqc_user FOREIGN KEY(checked_by) REFERENCES users(id) ON DELETE SET NULL,
                INDEX idx_pqc_result(batch_id,result)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS waste_reasons (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(160) NOT NULL UNIQUE,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS production_waste (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                batch_id BIGINT UNSIGNED NOT NULL,
                batch_item_id BIGINT UNSIGNED NOT NULL,
                reason_id BIGINT UNSIGNED NULL,
                quantity INT NOT NULL,
                notes VARCHAR(1000) NULL,
                created_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_pw_batch FOREIGN KEY(batch_id) REFERENCES production_batches(id) ON DELETE CASCADE,
                CONSTRAINT fk_pw_batch_item FOREIGN KEY(batch_item_id) REFERENCES production_batch_items(id) ON DELETE CASCADE,
                CONSTRAINT fk_pw_reason FOREIGN KEY(reason_id) REFERENCES waste_reasons(id) ON DELETE SET NULL,
                CONSTRAINT fk_pw_user FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL,
                INDEX idx_pw_batch(batch_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS batch_assignments (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                batch_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                assignment_role VARCHAR(120) NULL,
                station VARCHAR(120) NULL,
                created_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_batch_assignment(batch_id,user_id),
                CONSTRAINT fk_ba_batch FOREIGN KEY(batch_id) REFERENCES production_batches(id) ON DELETE CASCADE,
                CONSTRAINT fk_ba_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_ba_creator FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS labor_sessions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                batch_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                station VARCHAR(120) NULL,
                hourly_rate_snapshot DECIMAL(10,2) NOT NULL DEFAULT 0,
                started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                ended_at DATETIME NULL,
                duration_minutes INT NULL,
                labor_cost DECIMAL(12,2) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_ls_batch FOREIGN KEY(batch_id) REFERENCES production_batches(id) ON DELETE CASCADE,
                CONSTRAINT fk_ls_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_ls_open(user_id,ended_at),
                INDEX idx_ls_batch(batch_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        if(!$columnExists($pdo,'finished_inventory','production_batch_item_id')){
            $pdo->exec(
                'ALTER TABLE finished_inventory
                 ADD COLUMN production_batch_item_id BIGINT UNSIGNED NULL AFTER batch_id'
            );
        }
        if(!$indexExists($pdo,'finished_inventory','uq_finished_batch_item')){
            $pdo->exec(
                'ALTER TABLE finished_inventory
                 ADD UNIQUE KEY uq_finished_batch_item(production_batch_item_id)'
            );
        }
        if(!$fkExists($pdo,'finished_inventory','fk_fi_batch_item')){
            $pdo->exec(
                'ALTER TABLE finished_inventory
                 ADD CONSTRAINT fk_fi_batch_item
                 FOREIGN KEY(production_batch_item_id) REFERENCES production_batch_items(id) ON DELETE SET NULL'
            );
        }

        $reasons=['Production Defect','QC Failure','Damaged','Dropped','Over / Under Weight','Sample / Tasting'];
        $stmt=$pdo->prepare('INSERT IGNORE INTO waste_reasons(name,is_active) VALUES(?,1)');
        foreach($reasons as $reason) $stmt->execute([$reason]);

        $permissions=[
            ['production.manage_materials','Production','Edit and commit batch material usage'],
            ['production.record_qc','Production','Record production QC checks'],
            ['production.record_waste','Production','Record finished-unit waste'],
            ['production.assign_team','Production','Assign team members to batches'],
            ['production.track_labor','Production','Clock labor against production batches'],
            ['production.complete_batch','Production','Complete batches and create finished inventory'],
        ];
        $stmt=$pdo->prepare('INSERT IGNORE INTO permissions(permission_key,module,label) VALUES(?,?,?)');
        foreach($permissions as $permission) $stmt->execute($permission);

        $grant=static function(PDO $pdo,string $roleSlug,array $keys): void {
            if(!$keys) return;
            $marks=implode(',',array_fill(0,count($keys),'?'));
            $stmt=$pdo->prepare(
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
        $grant($pdo,'production-team',[
            'production.record_qc','production.record_waste','production.track_labor'
        ]);
        $grant($pdo,'packing',['production.record_qc','production.track_labor']);

        $stmt=$pdo->prepare(
            "INSERT INTO platform_meta(meta_key,meta_value) VALUES('phase_4','complete')
             ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)"
        );
        $stmt->execute();
        $stmt=$pdo->prepare(
            "INSERT INTO platform_meta(meta_key,meta_value) VALUES('app_version','0.5.0')
             ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)"
        );
        $stmt->execute();
    },
];
