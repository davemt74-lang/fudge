<?php
final class Permissions
{
    private array $cache = [];
    public function __construct(private Database $db) {}

    public function allForUser(int $userId): array
    {
        if (isset($this->cache[$userId])) return $this->cache[$userId];

        $isOwner = (int)$this->db->scalar(
            'SELECT COUNT(*)
             FROM user_roles ur
             JOIN roles r ON r.id=ur.role_id
             WHERE ur.user_id=? AND r.slug="owner" AND r.is_active=1',
            [$userId]
        ) > 0;

        if ($isOwner) {
            $map = [];
            foreach ($this->db->all('SELECT permission_key FROM permissions') as $p) {
                $map[$p['permission_key']] = true;
            }
            return $this->cache[$userId] = $map;
        }

        $rolePermissionIds = array_flip(array_map(
            'intval',
            array_column(
                $this->db->all(
                    'SELECT DISTINCT rp.permission_id
                     FROM user_roles ur
                     JOIN roles r ON r.id=ur.role_id AND r.is_active=1
                     JOIN role_permissions rp ON rp.role_id=ur.role_id
                     WHERE ur.user_id=?',
                    [$userId]
                ),
                'permission_id'
            )
        ));

        $overrides = [];
        foreach ($this->db->all(
            'SELECT permission_id,effect FROM user_permission_overrides WHERE user_id=?',
            [$userId]
        ) as $row) {
            $overrides[(int)$row['permission_id']] = $row['effect'];
        }

        $map = [];
        foreach ($this->db->all('SELECT id,permission_key FROM permissions') as $permission) {
            $permissionId = (int)$permission['id'];
            $allowed = isset($rolePermissionIds[$permissionId]);

            if (($overrides[$permissionId] ?? null) === 'allow') $allowed = true;
            if (($overrides[$permissionId] ?? null) === 'deny') $allowed = false;

            $map[$permission['permission_key']] = $allowed;
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
