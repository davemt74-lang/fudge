<?php
declare(strict_types=1);

$configPath = dirname(__DIR__) . '/config/config.php';
if (is_file($configPath)) {
    header('Location: index.php');
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $host = trim($_POST['host'] ?? '127.0.0.1');
    $port = (int)($_POST['port'] ?? 3306);
    $database = trim($_POST['database'] ?? 'fudge_donuts');
    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $ownerName = trim($_POST['owner_name'] ?? '');
    $ownerEmail = strtolower(trim($_POST['owner_email'] ?? ''));
    $ownerPassword = (string)($_POST['owner_password'] ?? '');

    try {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $database)) throw new RuntimeException('Database name may contain only letters, numbers and underscores.');
        if ($username === '') throw new RuntimeException('Enter the database username.');
        if ($ownerName === '') throw new RuntimeException('Enter the first user name.');
        if (!filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid first-user email address.');
        if (strlen($ownerPassword) < 10) throw new RuntimeException('First-user password must be at least 10 characters.');

        $serverDsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port);
        $pdo = new PDO($serverDsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `{$database}`");

        $runSqlFile = static function(PDO $pdo, string $path): void {
            $sql = file_get_contents($path);
            if ($sql === false) throw new RuntimeException('Could not read SQL file: ' . basename($path));
            $sql = preg_replace('/^\s*--.*$/m', '', $sql);
            $buffer = '';
            $quote = null;
            $len = strlen($sql);
            for ($i = 0; $i < $len; $i++) {
                $ch = $sql[$i];
                $next = $i + 1 < $len ? $sql[$i + 1] : '';
                if ($quote !== null) {
                    $buffer .= $ch;
                    if ($ch === $quote) {
                        if ($next === $quote) { $buffer .= $next; $i++; continue; }
                        if ($i === 0 || $sql[$i - 1] !== '\\') $quote = null;
                    }
                    continue;
                }
                if ($ch === "'" || $ch === '"' || $ch === '`') { $quote = $ch; $buffer .= $ch; continue; }
                if ($ch === ';') {
                    $statement = trim($buffer);
                    if ($statement !== '') $pdo->exec($statement);
                    $buffer = '';
                    continue;
                }
                $buffer .= $ch;
            }
            $tail = trim($buffer);
            if ($tail !== '') $pdo->exec($tail);
        };

        $runSqlFile($pdo, dirname(__DIR__) . '/database/schema.sql');
        $runSqlFile($pdo, dirname(__DIR__) . '/database/seed.sql');

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('INSERT INTO users (name,email,password_hash,job_title,status) VALUES (?,?,?,?,"active")');
            $stmt->execute([$ownerName,$ownerEmail,password_hash($ownerPassword,PASSWORD_DEFAULT),'Owner']);
            $userId = (int)$pdo->lastInsertId();

            $ownerRoleId = (int)$pdo->query("SELECT id FROM roles WHERE slug='owner'")->fetchColumn();
            if (!$ownerRoleId) throw new RuntimeException('Owner role seed is missing.');
            $stmt = $pdo->prepare('INSERT INTO user_roles (user_id,role_id) VALUES (?,?)');
            $stmt->execute([$userId,$ownerRoleId]);

            $migrationFiles = glob(dirname(__DIR__) . '/database/migrations/*.php') ?: [];
            sort($migrationFiles, SORT_STRING);
            $stmt = $pdo->prepare('INSERT IGNORE INTO schema_migrations (version,name,checksum,applied_at) VALUES (?,?,?,NOW())');
            foreach ($migrationFiles as $migrationFile) {
                $migration = require $migrationFile;
                if (is_array($migration) && !empty($migration['version']) && !empty($migration['name'])) {
                    $stmt->execute([$migration['version'],$migration['name'],hash_file('sha256',$migrationFile)]);
                }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        $config = [
            'app' => [
                'name' => 'Fudge Donuts Ops',
                'base_url' => '',
                'timezone' => 'America/Phoenix',
                'debug' => false,
                // Generated automatically. The installer never asks the user for a security/API key.
                'key' => bin2hex(random_bytes(32)),
            ],
            'db' => [
                'host' => $host,
                'port' => $port,
                'database' => $database,
                'username' => $username,
                'password' => $password,
                'charset' => 'utf8mb4',
            ],
        ];

        $php = "<?php\nreturn " . var_export($config, true) . ";\n";
        if (file_put_contents($configPath, $php, LOCK_EX) === false) {
            throw new RuntimeException('Could not write config/config.php. Make the config directory writable during installation.');
        }

        header('Location: index.php?page=login&installed=1');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Install Fudge Donuts Ops</title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body class="install-body">
<main class="install-card">
<div class="kicker">One-Time Setup</div>
<h1>Install Fudge Donuts Ops</h1>
<p class="muted">Enter the database connection and create the first Owner account. That's it.</p>

<?php if ($error): ?><div class="alert danger"><?=htmlspecialchars($error,ENT_QUOTES,'UTF-8')?></div><?php endif; ?>

<form method="post" class="form-grid">
<h2 class="span-2 section-title">Database</h2>
<label>Host<input name="host" value="<?=htmlspecialchars($_POST['host'] ?? '127.0.0.1')?>" required></label>
<label>Port<input name="port" type="number" value="<?=htmlspecialchars($_POST['port'] ?? '3306')?>" required></label>
<label>Database name<input name="database" value="<?=htmlspecialchars($_POST['database'] ?? 'fudge_donuts')?>" required></label>
<label>Database username<input name="username" value="<?=htmlspecialchars($_POST['username'] ?? '')?>" required></label>
<label class="span-2">Database password<input name="password" type="password" autocomplete="new-password"></label>

<hr class="span-2">
<h2 class="span-2 section-title">Create First User</h2>
<label>Name<input name="owner_name" value="<?=htmlspecialchars($_POST['owner_name'] ?? '')?>" required></label>
<label>Email<input name="owner_email" type="email" value="<?=htmlspecialchars($_POST['owner_email'] ?? '')?>" required></label>
<label class="span-2">Password<input name="owner_password" type="password" minlength="10" autocomplete="new-password" required><small>This user becomes the Owner and can create the rest of the team later.</small></label>

<div class="span-2 alert info">No security key or API key is required during installation. Internal encryption material is generated automatically. LLM provider keys are optional and can be added later from Admin → AI / LLM Settings.</div>
<button class="btn primary span-2" type="submit">Install Fudge Donuts Ops</button>
</form>
</main>
</body>
</html>
