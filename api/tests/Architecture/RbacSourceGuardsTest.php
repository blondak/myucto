<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class RbacSourceGuardsTest extends TestCase
{
    /**
     * Tyto soubory tvoří úzkou hranici, kde je identita systémové role součástí
     * načtení/serializace role nebo ochrany neměnného superadmin preset-u.
     * Běžné autorizační rozhodování musí používat permission klíče.
     *
     * @var list<string>
     */
    private const BACKEND_ROLE_IDENTITY_BOUNDARY = [
        'Action/Admin/UserAdminAction.php',
        'Action/Admin/UserSupplierAdminAction.php',
        'Action/Auth/LoginAction.php',
        'Middleware/AuthMiddleware.php',
        'Repository/RoleRepository.php',
        'Security/EffectiveRole.php',
        'Security/PermissionCatalog.php',
        'Security/PermissionResolver.php',
        'Security/RequestAuthorization.php',
        'Security/UserRoleProfile.php',
        'Service/Tenant/SupplierAccessResolver.php',
    ];

    /**
     * Správa rolí a uživatelů musí zobrazit typ role a chránit systémový
     * superadmin preset; auth store převádí typ role na klientský UX režim.
     * Nejde o permission guard zápisové akce.
     *
     * @var list<string>
     */
    private const FRONTEND_ROLE_IDENTITY_BOUNDARY = [
        'pages/admin/Roles.vue',
        'pages/admin/Users.vue',
        'stores/auth.ts',
    ];

    public function testNoNewHardcodedBackendRoleAuthorizationChecksAreIntroduced(): void
    {
        $src = dirname(__DIR__, 2) . '/src';
        $violations = [];
        foreach (self::phpFiles($src) as $path) {
            $relative = str_replace('\\', '/', substr($path, strlen($src) + 1));
            if (in_array($relative, self::BACKEND_ROLE_IDENTITY_BOUNDARY, true)) continue;
            foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $index => $line) {
                if (!preg_match('/(?:===|!==|==|!=|in_array\s*\()[^;]*(?:[\'\"](?:superadmin|accountant|readonly|client)[\'\"])/', $line)) continue;
                if (!preg_match('/\b(?:role|role_type|system_key|is_superadmin|legacy)\b/i', $line)) continue;
                $violations[] = $relative . ':' . ($index + 1) . ' ' . trim($line);
            }
        }

        self::assertSame([], $violations, 'Autorizační role-check musí používat PermissionResolver/PermissionChecker; výjimky patří jen na hranici identity role.');
    }

    public function testFrontendDoesNotAuthorizeByLegacyRoleName(): void
    {
        $src = dirname(__DIR__, 3) . '/web/src';
        $violations = [];
        foreach (self::sourceFiles($src, ['ts', 'vue']) as $path) {
            $relative = str_replace('\\', '/', substr($path, strlen($src) + 1));
            if (in_array($relative, self::FRONTEND_ROLE_IDENTITY_BOUNDARY, true)) continue;
            foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $index => $line) {
                if (!preg_match('/(?:===|!==|==|!=|includes\s*\(|in_array\s*\()[^;]*(?:[\'\"](?:superadmin|accountant|readonly|client)[\'\"])/', $line)) continue;
                if (!preg_match('/\b(?:role|role_type|system_key|is_superadmin)\b/i', $line)) continue;
                $violations[] = $relative . ':' . ($index + 1) . ' ' . trim($line);
            }
        }

        self::assertSame([], $violations, 'Frontend smí autorizovat jen přes auth.can() nebo explicitní isSuperadmin hranici, ne podle názvu legacy role.');
    }

    public function testConventionallyNamedWriteRoutesRequireWritePermission(): void
    {
        $router = file_get_contents(dirname(__DIR__, 3) . '/web/src/router/index.ts');
        self::assertNotFalse($router);
        preg_match('/const routePermissions:[^{]+\{(?<map>.*?)\n\}/s', $router, $mapMatch);
        preg_match_all('/[\'\"]?(?<name>[a-z0-9-]+)[\'\"]?\s*:\s*\[\s*[\'\"](?<key>[a-z0-9_.]+)[\'\"](?:\s*,\s*[\'\"](?<access>read|write)[\'\"])?\s*]/', $mapMatch['map'] ?? '', $entries, PREG_SET_ORDER);
        $permissions = [];
        foreach ($entries as $entry) $permissions[$entry['name']] = ($entry['access'] ?? '') ?: 'read';

        preg_match_all('/name:\s*[\'\"](?<name>[a-z0-9-]+)[\'\"][^\r\n]*component:/', $router, $routes);
        $missing = [];
        foreach (array_unique($routes['name']) as $name) {
            if (preg_match('/(?:-new|-edit)$/', $name) !== 1) continue;
            if (($permissions[$name] ?? null) !== 'write') $missing[] = $name;
        }

        self::assertSame([], $missing, 'Každá formulářová write route (*-new/*-edit) musí mít permission s access=write.');
    }

    public function testLastSuperadminGuardIsSerializedInDatabaseTransaction(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/Action/Admin/UserAdminAction.php');
        self::assertNotFalse($source);
        self::assertStringContainsString('beginTransaction()', $source);
        self::assertStringContainsString('ORDER BY u.id FOR UPDATE', $source);
        self::assertStringContainsString('guardedUserUpdate(', $source);
        self::assertStringContainsString('rollBack()', $source);
        self::assertStringContainsString('commit()', $source);
    }

    /** @return list<string> */
    private static function phpFiles(string $root): array
    {
        return self::sourceFiles($root, ['php']);
    }

    /** @param list<string> $extensions @return list<string> */
    private static function sourceFiles(string $root, array $extensions): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (!$file->isFile() || !in_array(strtolower($file->getExtension()), $extensions, true)) continue;
            $files[] = $file->getPathname();
        }
        sort($files);
        return $files;
    }
}
