<?php
return [
    'version' => '20260921_002',
    'name' => 'Add platform metadata table',
    'up' => static function(PDO $pdo): void {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS platform_meta (
                meta_key VARCHAR(120) PRIMARY KEY,
                meta_value TEXT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $stmt = $pdo->prepare('INSERT INTO platform_meta (meta_key,meta_value) VALUES (?,?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)');
        $stmt->execute(['schema_track','v1']);
    },
];
