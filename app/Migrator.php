<?php
final class Migrator
{
    public function __construct(private Database $db, private string $migrationsPath) {}

    public function ensureTable(): void
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                version VARCHAR(64) NOT NULL UNIQUE,
                name VARCHAR(190) NOT NULL,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function appliedVersions(): array
    {
        $this->ensureTable();
        $rows = $this->db->all('SELECT version FROM schema_migrations ORDER BY version');
        return array_column($rows, 'version');
    }

    public function available(): array
    {
        $files = glob(rtrim($this->migrationsPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.php') ?: [];
        sort($files, SORT_STRING);
        $items = [];
        foreach ($files as $file) {
            $migration = require $file;
            if (!is_array($migration) || empty($migration['version']) || empty($migration['name']) || !isset($migration['up']) || !is_callable($migration['up'])) {
                throw new RuntimeException('Invalid migration file: ' . basename($file));
            }
            $items[$migration['version']] = $migration + ['file' => $file];
        }
        return $items;
    }

    public function pending(): array
    {
        $applied = array_flip($this->appliedVersions());
        return array_filter($this->available(), fn(array $m): bool => !isset($applied[$m['version']]));
    }

    public function applyPending(): array
    {
        $this->ensureTable();
        $appliedNow = [];
        foreach ($this->pending() as $migration) {
            $this->db->transaction(function(Database $db) use ($migration): void {
                ($migration['up'])($db->pdo());
                $db->exec(
                    'INSERT INTO schema_migrations (version,name,applied_at) VALUES (?,?,NOW())',
                    [$migration['version'],$migration['name']]
                );
            });
            $appliedNow[] = ['version'=>$migration['version'],'name'=>$migration['name']];
        }
        return $appliedNow;
    }

    public function markAllAvailableApplied(): void
    {
        $this->ensureTable();
        foreach ($this->available() as $migration) {
            $this->db->exec(
                'INSERT IGNORE INTO schema_migrations (version,name,applied_at) VALUES (?,?,NOW())',
                [$migration['version'],$migration['name']]
            );
        }
    }
}
