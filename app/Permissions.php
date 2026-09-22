<?php
final class Permissions
{
    private array $cache = [];
    public function __construct(private Database $db) {}

    public function allForUser(int $userId): array
    {
        if (isset($this->cache[$userId])) return $this->cache[$userId];
        $isOwner = (int)$this->db->scalar('SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=? AND r.slug="owner" AND r.is_active=1', [$userId]) > 0;
        if ($isOwner) {
            $map = [];
            foreach ($this->db->all('SELECT permission_key FROM permissions') as $p) $map[$p['permission_key']] = true;
            return $this->cache[$userId] = $map;
        }
        $rows = $this->db->all(
            'SELECT p.permission_key,
                    MAX(CASE WHEN ump.effect = "deny" THEN 2 WHEN ump.effect = "allow" THEN 1 ELSE 0 END) individual_effect,
                    MAX(CASE WHEN rp.permission_id IS NOT NULL THEN 1 ELSE 0 END) role_allow
             FROM permissions p
             LEFT JOIN role_permissions rp ON rp.permission_id = p.id
             LEFT JOIN user_roles ur ON ur.role_id = rp.role_id AND ur.user_id = ?
             LEFT JOIN user_permission_overrides ump ON ump.permission_id = p.id AND ump.user_id = ?
             GROUP BY p.id, p.permission_key',
            [$userId, $userId]
        );
        $map = [];
        foreach ($rows as $r) {
            $map[$r['permission_key']] = ((int)$r['individual_effect'] === 2) ? false : (((int)$r['individual_effect'] === 1) || ((int)$r['role_allow'] === 1));
        }
        return $this->cache[$userId] = $map;
    }

    public function can(int $userId, string $permission): bool
    {
        return (bool)($this->allForUser($userId)[$permission] ?? false);
    }

    public function require(int $userId, string $permission): void
    {
        if (!$this->can($userId, $permission)) {
            http_response_code(403);
            exit('You do not have permission to perform this action.');
        }
    }
}
