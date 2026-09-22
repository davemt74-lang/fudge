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
    $adminName = trim($_POST['admin_name'] ?? 'Owner');
    $adminEmail = strtolower(trim($_POST['admin_email'] ?? ''));
    $adminPassword = (string)($_POST['admin_password'] ?? '');

    try {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $database)) throw new RuntimeException('Database name may contain only letters, numbers and underscores.');
        if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid admin email address.');
        if (strlen($adminPassword) < 10) throw new RuntimeException('Admin password must be at least 10 characters.');

        $serverDsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port);
        $pdo = new PDO($serverDsn, $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `{$database}`");
        $runSqlFile = static function(PDO $pdo, string $path): void {
            $sql = file_get_contents($path);
            if ($sql === false) throw new RuntimeException('Could not read SQL file: '.$path);
            $sql = preg_replace('/^\s*--.*$/m', '', $sql);
            $statements = [];
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
                    $stmt = trim($buffer);
                    if ($stmt !== '') $statements[] = $stmt;
                    $buffer = '';
                    continue;
                }
                $buffer .= $ch;
            }
            $tail = trim($buffer);
            if ($tail !== '') $statements[] = $tail;
            foreach ($statements as $statement) $pdo->exec($statement);
        };
        $runSqlFile($pdo, dirname(__DIR__) . '/database/schema.sql');
        $runSqlFile($pdo, dirname(__DIR__) . '/database/seed.sql');

        $stmt = $pdo->prepare('INSERT INTO users (name,email,password_hash,job_title,status) VALUES (?,?,?,?,"active")');
        $stmt->execute([$adminName,$adminEmail,password_hash($adminPassword,PASSWORD_DEFAULT),'Owner']);
        $userId = (int)$pdo->lastInsertId();
        $ownerRoleId = (int)$pdo->query("SELECT id FROM roles WHERE slug='owner'")->fetchColumn();
        $stmt = $pdo->prepare('INSERT INTO user_roles (user_id,role_id) VALUES (?,?)');
        $stmt->execute([$userId,$ownerRoleId]);

        $config = [
            'app' => [
                'name' => 'Fudge Donuts Ops',
                'base_url' => '',
                'timezone' => 'America/Phoenix',
                'debug' => false,
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
        if (file_put_contents($configPath, $php, LOCK_EX) === false) throw new RuntimeException('Could not write config/config.php. Check directory permissions.');
        header('Location: index.php?page=login&installed=1');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Install Fudge Donuts Ops</title><link rel="stylesheet" href="assets/app.css"></head>
<body class="install-body"><main class="install-card"><div class="brand-mark">FD</div><h1>Install Fudge Donuts Ops</h1><p class="muted">Create the database, load starter data, and create the first owner account.</p>
<?php if ($error): ?><div class="alert danger"><?=htmlspecialchars($error)?></div><?php endif; ?>
<form method="post" class="form-grid">
<label>Database host<input name="host" value="<?=htmlspecialchars($_POST['host'] ?? '127.0.0.1')?>" required></label>
<label>Port<input name="port" type="number" value="<?=htmlspecialchars($_POST['port'] ?? '3306')?>" required></label>
<label>Database name<input name="database" value="<?=htmlspecialchars($_POST['database'] ?? 'fudge_donuts')?>" required></label>
<label>Database user<input name="username" value="<?=htmlspecialchars($_POST['username'] ?? '')?>" required></label>
<label class="span-2">Database password<input name="password" type="password"></label>
<hr class="span-2"><h2 class="span-2">First owner</h2>
<label>Owner name<input name="admin_name" value="<?=htmlspecialchars($_POST['admin_name'] ?? '')?>" required></label>
<label>Email<input name="admin_email" type="email" value="<?=htmlspecialchars($_POST['admin_email'] ?? '')?>" required></label>
<label class="span-2">Password<input name="admin_password" type="password" minlength="10" required><small>At least 10 characters.</small></label>
<button class="btn primary span-2" type="submit">Install Platform</button>
</form></main></body></html>
