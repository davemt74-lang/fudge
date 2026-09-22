<?php
final class Migrator
{
    private const LOCK_NAME = 'fudge_donuts_schema_upgrade';

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
            if (
                !is_array($migration) ||
                empty($migration['version']) ||
                empty($migration['name']) ||
                !isset($migration['up']) ||
                !is_callable($migration['up'])
            ) {
                throw new RuntimeException('Invalid migration file: ' . basename($file));
            }

            if (isset($items[$migration['version']])) {
                throw new RuntimeException('Duplicate migration version: ' . $migration['version']);
            }

            $items[$migration['version']] = $migration + ['file' => $file];
        }

        return $items;
    }

    public function pending(): array
    {
        $applied = array_flip($this->appliedVersions());
        return array_filter(
            $this->available(),
            fn(array $m): bool => !isset($applied[$m['version']])
        );
    }

    public function applyPending(): array
    {
        $this->ensureTable();

        $locked = (int)$this->db->scalar('SELECT GET_LOCK(?, 5)', [self::LOCK_NAME]) === 1;
        if (!$locked) {
            throw new RuntimeException('Another database upgrade is already running. Try again after it finishes.');
        }

        try {
            $appliedNow = [];

            foreach ($this->pending() as $migration) {
                // MySQL/MariaDB may auto-commit DDL. Migration up() functions must therefore
                // be safe to retry (for example using IF NOT EXISTS / existence checks).
                ($migration['up'])($this->db->pdo());

                $this->db->exec(
                    'INSERT INTO schema_migrations (version,name,applied_at) VALUES (?,?,NOW())',
                    [$migration['version'],$migration['name']]
                );

                $appliedNow[] = [
                    'version'=>$migration['version'],
                    'name'=>$migration['name'],
                ];
            }

            return $appliedNow;
        } finally {
            try {
                $this->db->scalar('SELECT RELEASE_LOCK(?)', [self::LOCK_NAME]);
            } catch (Throwable $ignored) {
            }
        }
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
