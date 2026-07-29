<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Security\PermissionCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Staticke pojistky auth-kriticke migrace 1074. Test zamerne neotevira DB:
 * plny datovy backfill se overuje pouze nad nahodnou izolovanou databazi.
 */
final class DynamicRolesMigrationTest extends TestCase
{
    public function testSchemaChangesAreResumable(): void
    {
        $sql = $this->sql();

        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS roles', $sql);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS role_permissions', $sql);
        self::assertMatchesRegularExpression(
            '/ALTER TABLE users\s+ADD COLUMN IF NOT EXISTS role_id[\s\S]+ADD KEY IF NOT EXISTS idx_users_role_id[\s\S]+FOREIGN KEY IF NOT EXISTS \(role_id\)/',
            $sql,
        );
        self::assertMatchesRegularExpression(
            '/ALTER TABLE user_suppliers\s+ADD COLUMN IF NOT EXISTS role_id[\s\S]+ADD KEY IF NOT EXISTS idx_usersup_role_id[\s\S]+FOREIGN KEY IF NOT EXISTS \(role_id\)/',
            $sql,
        );
    }

    public function testSeedAndBackfillsAreAtomicAndOneShot(): void
    {
        $sql = $this->sql();
        $start = strpos($sql, 'START TRANSACTION;');
        $commit = strrpos($sql, 'COMMIT;');
        $bootstrap = strpos($sql, 'SET @rbac_bootstrap := ROW_COUNT();');

        self::assertIsInt($start);
        self::assertIsInt($commit);
        self::assertIsInt($bootstrap);
        self::assertLessThan($bootstrap, $start);
        self::assertLessThan($commit, $bootstrap);
        self::assertSame(5, substr_count($sql, '@rbac_bootstrap = 1'));

        self::assertStringContainsString(
            "WHERE NOT EXISTS (SELECT 1 FROM roles WHERE system_key = 'superadmin')",
            $sql,
        );
        self::assertStringContainsString(
            'WHERE @rbac_bootstrap = 1;',
            $sql,
            'Preset permissions musi byt vlozeny jen pri prvnim bootstrapu.',
        );
    }

    public function testPresetKeysBelongToCatalogAndSuperadminHasNoRows(): void
    {
        $sql = $this->sql();
        preg_match_all(
            "/\('(accountant|readonly|client)','([^']+)',([12])\)/",
            $sql,
            $matches,
            PREG_SET_ORDER,
        );

        self::assertNotEmpty($matches);
        $catalog = (new PermissionCatalog())->all();
        $seen = [];
        foreach ($matches as $match) {
            $identity = $match[1] . ':' . $match[2];
            self::assertArrayNotHasKey($identity, $seen, "Duplicitni preset $identity");
            $seen[$identity] = true;
            self::assertArrayHasKey($match[2], $catalog, "Neznamy permission klic {$match[2]}");
            self::assertContains(
                $match[1] === 'client' ? 'client' : 'staff',
                $catalog[$match[2]]['role_types'],
                "Permission {$match[2]} neni povolena pro preset {$match[1]}",
            );
        }

        self::assertSame([], array_filter(
            $matches,
            static fn (array $match): bool => $match[1] === 'superadmin',
        ));

        foreach ([
            'accounting', 'bank', 'documents', 'reports', 'stock', 'eshop',
            'purchase_invoices.scan', 'purchase_invoices.payment_orders',
            'clients.public_links', 'utilities',
        ] as $forbiddenClientKey) {
            self::assertArrayNotHasKey('client:' . $forbiddenClientKey, $seen);
        }
    }

    public function testLegacyBackfillPreservesMembershipSemantics(): void
    {
        $sql = $this->sql();

        foreach ([
            "WHEN 'admin' THEN 'superadmin'",
            "WHEN 'accountant' THEN 'accountant'",
            "WHEN 'readonly' THEN 'readonly'",
            "WHEN 'client' THEN 'client'",
        ] as $mapping) {
            self::assertStringContainsString($mapping, $sql);
        }

        self::assertStringContainsString(
            "JOIN users u ON u.id = us.user_id AND u.role IN ('accountant','readonly')",
            $sql,
            'Legacy per-firma override klienta/admina se nesmi prevest na staff role_id.',
        );
        self::assertMatchesRegularExpression(
            "/INSERT INTO user_suppliers \(user_id, supplier_id, role, role_id\)[\s\S]+CROSS JOIN supplier s[\s\S]+u\.role IN \('accountant','readonly'\)[\s\S]+NOT EXISTS/",
            $sql,
        );
        self::assertStringContainsString(
            'SELECT CASE WHEN @rbac_bootstrap <> 1 OR COUNT(*) = 0 THEN 1 ELSE 0 END',
            $sql,
            'Fail guard smi blokovat jen prvni bootstrap, ne resumovany beh po castecne aplikaci DDL.',
        );
        self::assertStringContainsString('FROM users' . "\n" . 'WHERE is_active = 1' . "\n" . '  AND role_id IS NULL;', $sql,
            'Fail guard musi podle planu kontrolovat vsechny aktivni uzivatele.');
    }

    public function testResetPreservesSystemRolesAndKeepModePreservesAssignments(): void
    {
        $path = dirname(__DIR__, 2) . '/bin/reset.php';
        $source = file_get_contents($path);
        self::assertIsString($source, 'Reset skript musi existovat a jit nacist.');

        self::assertStringContainsString(
            "'role_permissions'            => 'role_id NOT IN (SELECT id FROM roles WHERE system_key IS NOT NULL)'",
            $source,
        );
        self::assertStringContainsString("'roles'                       => 'system_key IS NULL'", $source);
        self::assertStringContainsString(
            "'roles', 'role_permissions', 'user_suppliers'",
            $source,
        );
    }

    private function sql(): string
    {
        $path = dirname(__DIR__, 3) . '/db/migrations/1074_dynamic_roles_permissions.sql';
        $sql = file_get_contents($path);
        self::assertIsString($sql, 'Migrace 1074 musi existovat a jit nacist.');
        return $sql;
    }
}
