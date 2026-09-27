<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Action\Admin\Import\PohodaMigrationAction;
use MyInvoice\Bootstrap;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\EffectiveRole;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Exportní nástroje průvodců POHODA a PAMICA: seznam a stažení souborů jednotlivě i v ZIPu.
 * Vystavují se jen skripty (.ps1, .cmd) a vzory konfigurace SQL (*.example.json), nic jiného ze složky;
 * vyplněná konfigurace s heslem (pohoda-sql.json) ani když leží vedle skriptů.
 */
#[Group('integration')]
final class PohodaMigrationToolTest extends TestCase
{
    private PohodaMigrationAction $action;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }
        try {
            $this->action = Bootstrap::buildContainer()->get(PohodaMigrationAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI unavailable: ' . $e->getMessage());
        }
    }

    public function testPohodaToolListsSqlExporterAndConfigSample(): void
    {
        $names = $this->names('pohoda');

        foreach (['Export-PohodaSQL.cmd', 'Export-PohodaSQL.ps1', 'pohoda-sql.example.json', 'Pohoda-Common.ps1', 'PohodaSql-Common.ps1',
            'Export-PohodaMdbAccounting.ps1', 'Export-PohodaMdb.ps1', 'Export-Pohoda.cmd'] as $expected) {
            self::assertContains($expected, $names);
        }
        self::assertSame($this->expectedFiles(['/tools/pohoda-export']), $names, 'Ze složky nástroje se vystaví jen .ps1, .cmd a *.example.json z jejího kořene.');
    }

    public function testPamicaToolIncludesSharedSqlConnection(): void
    {
        $names = $this->names('pamica');

        foreach (['Export-Pamica.cmd', 'Export-Pamica.ps1', 'Export-PamicaSQL.cmd', 'Export-PamicaSQL.ps1', 'pamica-sql.example.json', 'PohodaSql-Common.ps1'] as $expected) {
            self::assertContains($expected, $names);
        }
        $expected = $this->expectedFiles(['/tools/pamica-export']);
        $expected[] = 'PohodaSql-Common.ps1';
        sort($expected);
        self::assertSame($expected, $names, 'PAMICA dostane navíc jen společné připojení k SQL Serveru.');
    }

    public function testJsonSampleDownloadsAsFile(): void
    {
        $response = $this->call('toolDownload', ['name' => 'pohoda-sql.example.json']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/octet-stream', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('filename="pohoda-sql.example.json"', $response->getHeaderLine('Content-Disposition'));
        $body = (string) $response->getBody();
        self::assertSame((string) file_get_contents(Bootstrap::rootDir() . '/tools/pohoda-export/pohoda-sql.example.json'), $body);
        $sample = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('', $sample['user'], 'Vzor nemá přihlašovací údaje.');
        self::assertSame('', $sample['password'], 'Vzor nemá heslo.');

        $shared = $this->call('toolDownload', ['name' => 'PohodaSql-Common.ps1', 'variant' => 'pamica']);
        self::assertSame(200, $shared->getStatusCode());
    }

    public function testOtherFilesAreNotServed(): void
    {
        foreach (['../../cfg.php', 'tests', 'Test-PohodaMdbYearFilter.ps1', 'README.md', 'Export-Pamica.ps1'] as $name) {
            self::assertSame(404, $this->call('toolDownload', ['name' => $name])->getStatusCode(), $name);
        }
    }

    public function testFilledConfigNextToScriptsIsNotServed(): void
    {
        $filled = Bootstrap::rootDir() . '/tools/pohoda-export/pohoda-sql.json';
        if (is_file($filled)) {
            $this->markTestSkipped('Ve složce nástroje leží skutečná konfigurace, test ji nepřepíše.');
        }
        file_put_contents($filled, '{"host": "server", "user": "sa", "password": "fiktivni-heslo"}');
        try {
            self::assertNotContains('pohoda-sql.json', $this->names('pohoda'));
            self::assertSame(404, $this->call('toolDownload', ['name' => 'pohoda-sql.json'])->getStatusCode());
            $zip = (string) $this->call('toolDownload', [])->getBody();
            self::assertStringNotContainsString('fiktivni-heslo', $zip);
            self::assertStringNotContainsString('pohoda-export/pohoda-sql.json', $zip);
        } finally {
            @unlink($filled);
        }
    }

    public function testZipContainsExactlyListedFilesIncludingJson(): void
    {
        foreach (['pohoda', 'pamica'] as $variant) {
            $response = $this->call('toolDownload', ['variant' => $variant]);
            self::assertSame('application/zip', $response->getHeaderLine('Content-Type'));
            $tmp = (string) tempnam(sys_get_temp_dir(), 'tooltest');
            file_put_contents($tmp, (string) $response->getBody());
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($tmp) === true);
            $entries = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entries[] = (string) $zip->getNameIndex($i);
            }
            $zip->close();
            @unlink($tmp);
            sort($entries);
            $expected = array_map(static fn (string $n): string => $variant . '-export/' . $n, $this->names($variant));
            sort($expected);
            self::assertSame($expected, $entries);
            self::assertContains($variant . '-export/' . $variant . '-sql.example.json', $entries);
        }
    }

    /** @return list<string> */
    private function names(string $variant): array
    {
        $response = $this->call('tool', ['variant' => $variant]);
        self::assertSame(200, $response->getStatusCode());
        $data = (array) json_decode((string) $response->getBody(), true);
        $files = (array) ($data['data']['files'] ?? $data['files'] ?? []);
        $names = array_map(static fn (array $f): string => (string) $f['name'], $files);
        sort($names);
        return $names;
    }

    /**
     * @param list<string> $dirs
     * @return list<string>
     */
    private function expectedFiles(array $dirs): array
    {
        $out = [];
        foreach ($dirs as $dir) {
            foreach (glob(Bootstrap::rootDir() . $dir . '/*') ?: [] as $path) {
                if (is_file($path) && preg_match('/(\.ps1|\.cmd|\.example\.json)$/', $path) === 1) {
                    $out[] = basename($path);
                }
            }
        }
        sort($out);
        return $out;
    }

    /** @param array<string,string> $query */
    private function call(string $method, array $query): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/admin/imports/pohoda/tool')
            ->withQueryParams($query)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 1)
            ->withAttribute('auth.effective_role', new EffectiveRole(9, 'Test', 'staff', true, ['utilities.import' => 2]))
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 1]);
        return $this->action->{$method}($request, (new ResponseFactory())->createResponse());
    }
}
