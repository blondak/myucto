<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Security\PermissionCatalog;
use PHPUnit\Framework\TestCase;

final class PermissionCatalogParityTest extends TestCase
{
    public function testBackendAndFrontendPermissionKeysStayInParity(): void
    {
        $frontend = self::frontendPermissionKeys();
        $backend = array_keys((new PermissionCatalog())->all());
        sort($frontend);
        sort($backend);

        self::assertSame($backend, $frontend, 'PERMISSION_KEYS musí přesně odpovídat backendovému PermissionCatalogu.');
    }

    public function testEveryRouterPermissionKeyExistsInFrontendCatalog(): void
    {
        $source = self::readRepoFile('web/src/router/index.ts');
        self::assertMatchesRegularExpression(
            '/const routePermissions:[^{]+\{(?<map>.*?)\n\}/s',
            $source,
        );
        preg_match('/const routePermissions:[^{]+\{(?<map>.*?)\n\}/s', $source, $match);
        preg_match_all('/:\s*\[\s*[\'\"](?<key>[a-z0-9_.]+)[\'\"]/', $match['map'], $keys);

        $unknown = array_values(array_diff(array_unique($keys['key']), self::frontendPermissionKeys()));
        self::assertSame([], $unknown, 'Router používá permission klíče mimo PERMISSION_KEYS.');
    }

    public function testFrontendClientPermissionKeysFollowBackendRoleTypes(): void
    {
        $source = self::readRepoFile('web/src/security/permissions.ts');
        self::assertMatchesRegularExpression(
            '/CLIENT_PERMISSION_KEYS\s*=\s*\[(?<keys>.*?)]\s*as const/s',
            $source,
        );
        preg_match('/CLIENT_PERMISSION_KEYS\s*=\s*\[(?<keys>.*?)]\s*as const/s', $source, $match);
        preg_match_all('/[\'"](?<key>[a-z0-9_.]+)[\'"]/', $match['keys'], $keys);

        $frontend = array_values(array_unique($keys['key']));
        $backend = [];
        foreach ((new PermissionCatalog())->all() as $key => $definition) {
            if (in_array('client', $definition['role_types'], true)) $backend[] = $key;
        }
        sort($frontend);
        sort($backend);

        self::assertSame($backend, $frontend, 'CLIENT_PERMISSION_KEYS musí odpovídat role_types=client.');
    }

    /** @return list<string> */
    private static function frontendPermissionKeys(): array
    {
        $source = self::readRepoFile('web/src/security/permissions.ts');
        self::assertMatchesRegularExpression('/PERMISSION_KEYS\s*=\s*\[(?<keys>.*?)]\s*as const/s', $source);
        preg_match('/PERMISSION_KEYS\s*=\s*\[(?<keys>.*?)]\s*as const/s', $source, $match);
        preg_match_all('/[\'\"](?<key>[a-z0-9_.]+)[\'\"]/', $match['keys'], $keys);

        return array_values(array_unique($keys['key']));
    }

    private static function readRepoFile(string $relativePath): string
    {
        $path = dirname(__DIR__, 3) . '/' . $relativePath;
        $contents = file_get_contents($path);
        self::assertNotFalse($contents, "Nelze načíst $relativePath.");
        return $contents;
    }
}
