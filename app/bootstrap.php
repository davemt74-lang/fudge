<?php
declare(strict_types=1);

$configFile = dirname(__DIR__) . '/config/config.php';
if (!is_file($configFile)) {
    if (PHP_SAPI !== 'cli' && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'install.php') {
        header('Location: install.php');
        exit;
    }
    return;
}
$config = require $configFile;
date_default_timezone_set($config['app']['timezone'] ?? 'America/Phoenix');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Security.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Permissions.php';
require_once __DIR__ . '/Services.php';
require_once __DIR__ . '/LlmService.php';

$db = new Database($config['db']);
$auth = new Auth($db);
$permissions = new Permissions($db);
$inventory = new InventoryService($db);
$pricing = new SupplierPricingService($db);
$llm = new LlmService($db, $config['app']['key']);

function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function redirect(string $url): never { header('Location: ' . $url); exit; }
function user(): ?array { global $auth; return $auth->user(); }
function can(string $permission): bool { global $permissions; $u = user(); return $u ? $permissions->can((int)$u['id'], $permission) : false; }
function require_permission(string $permission): void { global $permissions; $u = user(); if (!$u) redirect('?page=login'); $permissions->require((int)$u['id'], $permission); }
function flash(string $type, string $message): void { $_SESSION['flash'][] = ['type'=>$type,'message'=>$message]; }
function pull_flashes(): array { $f=$_SESSION['flash']??[]; unset($_SESSION['flash']); return $f; }
function audit(string $action, ?string $entityType, ?int $entityId, mixed $before, mixed $after): void {
    global $db;
    try {
        $db->insert('INSERT INTO audit_log (user_id,action,entity_type,entity_id,before_json,after_json,ip_address,created_at) VALUES (?,?,?,?,?,?,?,NOW())', [
            $_SESSION['user_id'] ?? null,$action,$entityType,$entityId,
            $before === null ? null : json_encode($before, JSON_UNESCAPED_SLASHES),
            $after === null ? null : json_encode($after, JSON_UNESCAPED_SLASHES),
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) { /* audit must never break primary workflow */ }
}
