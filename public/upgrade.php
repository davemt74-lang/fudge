<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/Migrator.php';

$auth->requireLogin();
require_permission('settings.manage');

$migrator = new Migrator($db, dirname(__DIR__) . '/database/migrations');
$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Security::validateCsrf();
    try {
        $applied = $migrator->applyPending();
        if ($applied) {
            $success = count($applied) . ' update' . (count($applied) === 1 ? '' : 's') . ' installed successfully.';
            foreach ($applied as $migration) {
                audit('system.migration_applied', 'schema_migration', null, null, $migration);
            }
        } else {
            $success = 'The database is already up to date.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

try {
    $available = $migrator->available();
    $appliedVersions = array_flip($migrator->appliedVersions());
    $pending = $migrator->pending();
} catch (Throwable $e) {
    $available = [];
    $appliedVersions = [];
    $pending = [];
    $error = $e->getMessage();
}

function upgrade_h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Database Upgrade · Fudge Donuts Ops</title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body class="install-body">
<main class="install-card">
<div class="kicker">System Maintenance</div>
<h1>Database Upgrade</h1>
<p class="muted">Install database updates after uploading a newer version of Fudge Donuts Ops.</p>

<?php if ($error): ?><div class="alert danger"><?=upgrade_h($error)?></div><?php endif; ?>
<?php if ($success): ?><div class="alert success"><?=upgrade_h($success)?></div><?php endif; ?>

<div class="card" style="box-shadow:none;margin:18px 0">
<div class="list-row"><strong>Installed migrations</strong><span><?=count($appliedVersions)?></span></div>
<div class="list-row"><strong>Available migrations</strong><span><?=count($available)?></span></div>
<div class="list-row"><strong>Updates waiting</strong><span class="badge <?=count($pending)?'warn':'good'?>"><?=count($pending)?></span></div>
</div>

<?php if ($available): ?>
<div class="table-card" style="box-shadow:none;margin-bottom:18px">
<table>
<thead><tr><th>Version</th><th>Update</th><th>Status</th></tr></thead>
<tbody>
<?php foreach ($available as $m): ?>
<tr>
<td><span class="code"><?=upgrade_h($m['version'])?></span></td>
<td><?=upgrade_h($m['name'])?></td>
<td><?php if (isset($appliedVersions[$m['version']])): ?><span class="badge good">Installed</span><?php else: ?><span class="badge warn">Waiting</span><?php endif; ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>

<form method="post">
<input type="hidden" name="_csrf" value="<?=upgrade_h(Security::csrfToken())?>">
<button class="btn primary" type="submit" <?=count($pending)===0?'disabled':''?>><?=count($pending)?'Install '.count($pending).' Update'.(count($pending)===1?'':'s'):'Database Up to Date'?></button>
<a class="btn" href="index.php">Back to Dashboard</a>
</form>

<p class="muted" style="margin-top:18px">Workflow: upload the new application files, visit <span class="code">upgrade.php</span>, review pending updates, then click the update button once.</p>
</main>
</body>
</html>
