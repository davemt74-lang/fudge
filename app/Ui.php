<?php
final class Ui
{
    public static function layoutStart(string $title, string $active = ''): void
    {
        $u = user();
        $initial = $u ? strtoupper(substr($u['name'],0,1)) : 'F';
        $nav = [
            'dashboard'=>['Dashboard','dashboard.view'],
            'orders'=>['Orders','orders.view'],
            'production'=>['Production','production.view'],
            'inventory'=>['Inventory','inventory.view'],
            'ingredients'=>['Ingredients','ingredients.view'],
            'packaging'=>['Packaging','packaging.view'],
            'recipes'=>['Recipes','recipes.view'],
            'flavors'=>['Flavors','flavors.view'],
            'products'=>['Products','products.view'],
            'suppliers'=>['Suppliers','suppliers.view'],
            'customers'=>['Customers','customers.view'],
            'team'=>['Team','team.view'],
            'reports'=>['Reports','reports.view'],
            'ai'=>['AI / LLM','ai.view'],
            'audit'=>['Audit Log','audit.view'],
        ];
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.h($title).' · Fudge Donuts Ops</title><link rel="stylesheet" href="assets/app.css"></head><body><div class="app"><aside class="sidebar">';
        echo '<div class="brand"><div class="brand-mark">FD</div><div><strong>Fudge Donuts</strong><div class="muted" style="color:#a99d96">Operations</div></div></div><nav class="nav">';
        echo '<div class="nav-title">Operations</div>';
        foreach ($nav as $key=>$item) {
            if (!can($item[1])) continue;
            $class = $active === $key ? 'active' : '';
            echo '<a class="'.$class.'" href="?page='.h($key).'">'.h($item[0]).'</a>';
        }
        if (can('settings.manage')) {
            echo '<div class="nav-title">System</div><a href="upgrade.php">Database Upgrade</a>';
        }
        echo '</nav></aside><main class="main"><header class="topbar"><div class="page-title">'.h($title).'</div><div class="user-menu"><div class="avatar">'.h($initial).'</div><div><strong>'.h($u['name'] ?? '').'</strong><div class="muted">'.h($u['job_title'] ?? '').'</div></div><a class="btn small" href="?action=logout">Sign out</a></div></header><div class="content">';
        foreach (pull_flashes() as $f) echo '<div class="alert '.h($f['type']).'">'.h($f['message']).'</div>';
    }

    public static function layoutEnd(): void
    {
        echo '</div></main></div><script src="assets/app.js"></script></body></html>';
    }

    public static function pageHead(string $title, string $subtitle = '', string $button = ''): void
    {
        echo '<div class="page-head"><div><h1>'.h($title).'</h1>';
        if ($subtitle !== '') echo '<div class="muted">'.h($subtitle).'</div>';
        echo '</div>'.$button.'</div>';
    }

    public static function formField(string $name, string $label, mixed $value = '', string $type='text', bool $required=false, string $extra=''): void
    {
        echo '<label>'.h($label);
        if ($type === 'textarea') {
            echo '<textarea name="'.h($name).'" '.($required?'required':'').' '. $extra .'>'.h($value).'</textarea>';
        } else {
            echo '<input type="'.h($type).'" name="'.h($name).'" value="'.h($value).'" '.($required?'required':'').' '. $extra .'>';
        }
        echo '</label>';
    }

    public static function csrf(): string
    {
        return '<input type="hidden" name="_csrf" value="'.h(Security::csrfToken()).'">';
    }

    public static function statusBadge(string $status): string
    {
        $class = in_array($status,['active','paid','ready','fulfilled','completed','published','success'],true) ? 'good' : (in_array($status,['cancelled','suspended','error'],true) ? 'bad' : 'warn');
        return '<span class="badge '.$class.'">'.h(ucwords(str_replace(['-','_'],' ',$status))).'</span>';
    }
}
