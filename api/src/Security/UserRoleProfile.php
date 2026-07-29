<?php

declare(strict_types=1);

namespace MyInvoice\Security;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Upstream nese roli jako prostý sloupec `users.role`; MyÚčto ji má jemnozrnnou
 * v tabulce `roles` a frontend na tom staví (`role.type`, `role.system_key`,
 * `is_superadmin`). Aby se stejný SELECT neopisoval v každé auth akci zvlášť,
 * žije tvar odpovědi tady a auth kód z upstreamu si ho jen zavolá.
 */
final class UserRoleProfile
{
    /** @var array<int, array<string, mixed>> */
    private array $memo = [];

    public function __construct(private readonly Connection $db) {}

    /**
     * @return array{id:int,name:string,type:string,is_active:bool,system_key:?string}
     */
    public function forUser(int $userId): array
    {
        if ($userId <= 0) {
            return self::empty();
        }
        if (isset($this->memo[$userId])) {
            /** @var array{id:int,name:string,type:string,is_active:bool,system_key:?string} */
            return $this->memo[$userId];
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT u.role_id, r.name AS role_name, r.role_type,
                    r.is_active AS role_active, r.system_key
               FROM users u
               JOIN roles r ON r.id = u.role_id
              WHERE u.id = ?'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return $this->memo[$userId] = self::empty();
        }

        return $this->memo[$userId] = [
            'id'         => (int) $row['role_id'],
            'name'       => (string) $row['role_name'],
            'type'       => (string) $row['role_type'],
            'is_active'  => (bool) $row['role_active'],
            'system_key' => $row['system_key'] !== null ? (string) $row['system_key'] : null,
        ];
    }

    public function isSuperadmin(int $userId): bool
    {
        return $this->forUser($userId)['system_key'] === 'superadmin';
    }

    /**
     * Doplní uživatelské pole o `role_id`, `role_summary` a `is_superadmin`,
     * jak je čeká PermissionMiddleware a PermissionResolver.
     *
     * @param array<string,mixed> $user
     * @return array<string,mixed>
     */
    public function enrich(array $user): array
    {
        $userId = (int) ($user['id'] ?? 0);
        $profile = $this->forUser($userId);
        if ($profile['id'] === 0) {
            return $user;
        }

        $user['role_id']       = $profile['id'];
        $user['role_summary']  = $profile;
        $user['is_superadmin'] = $profile['system_key'] === 'superadmin';
        return $user;
    }

    /**
     * @return array{id:int,name:string,type:string,is_active:bool,system_key:?string}
     */
    private static function empty(): array
    {
        return ['id' => 0, 'name' => '', 'type' => '', 'is_active' => false, 'system_key' => null];
    }
}
