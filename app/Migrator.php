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
                checksum CHAR(64) NULL,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $hasChecksum = (int)$this->db->scalar(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='schema_migrations' AND COLUMN_NAME='checksum'"
        ) > 0;
        if (!$hasChecksum) {
            $this->db->exec('ALTER TABLE schema_migrations ADD COLUMN checksum CHAR(64) NULL AFTER name');
        }
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

            $items[$migration['version']] = $migration + [
                'file' => $file,
                'checksum' => hash_file('sha256', $file),
            ];
        }

        return $items;
    }

    public function pending(): array
    {
        $this->assertNoDrift();
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
                    'INSERT INTO schema_migrations (version,name,checksum,applied_at) VALUES (?,?,?,NOW())',
                    [$migration['version'],$migration['name'],$migration['checksum']]
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

    public function assertNoDrift(): void
    {
        $this->ensureTable();
        $available = $this->available();
        $rows = $this->db->all('SELECT version,checksum FROM schema_migrations');
        foreach ($rows as $row) {
            $version = (string)$row['version'];
            if (!isset($available[$version])) continue;

            if (empty($row['checksum'])) {
                // Legacy development installs predate checksum tracking. Baseline once.
                $this->db->exec(
                    'UPDATE schema_migrations SET checksum=? WHERE version=? AND checksum IS NULL',
                    [$available[$version]['checksum'],$version]
                );
                continue;
            }

            if (!hash_equals((string)$row['checksum'], (string)$available[$version]['checksum'])) {
                throw new RuntimeException('Migration file changed after it was applied: ' . $version);
            }
        }
    }

    public function markAllAvailableApplied(): void
    {
        $this->ensureTable();

        foreach ($this->available() as $migration) {
            $this->db->exec(
                'INSERT IGNORE INTO schema_migrations (version,name,checksum,applied_at) VALUES (?,?,?,NOW())',
                [$migration['version'],$migration['name'],$migration['checksum']]
            );
        }
    }
}
