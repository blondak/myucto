<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzOfficialExampleSourceCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecPackageCatalog;
use PHPUnit\Framework\TestCase;

final class JmhzOfficialExampleSourceCatalogTest extends TestCase
{
    public function testSelfConsistentManifestTamperDoesNotBypassTrustAnchor(): void
    {
        [$manifest, $specManifest] = $this->manifests();
        $manifest['payload']['examples'][0]['reason_code'] = 'tampered';
        $row = $manifest['payload']['examples'][0];
        unset($row['row_hash']);
        $manifest['payload']['examples'][0]['row_hash'] = hash('sha256', CanonicalJson::encode($row));
        $manifest['manifest_sha256'] = hash('sha256', CanonicalJson::encode($manifest['payload']));

        $this->expectException(\UnexpectedValueException::class);
        JmhzOfficialExampleSourceCatalog::validateManifest($manifest, $specManifest);
    }

    public function testUnknownExampleIsRejected(): void
    {
        $catalog = JmhzOfficialExampleSourceCatalog::load();

        $this->expectException(\OutOfBoundsException::class);
        $catalog->example('missing');
    }

    /**
     * @return array{
     *   array{manifest_sha256:string,payload:array<string, mixed>},
     *   array{manifest_sha256:string,payload:array<string, mixed>}
     * }
     */
    private function manifests(): array
    {
        $root = dirname(__DIR__, 5) . '/api/resources/payroll/jmhz';
        $manifest = json_decode(
            (string) file_get_contents($root . '/examples-2026-04-13/manifest.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($manifest);
        self::assertIsString($manifest['manifest_sha256'] ?? null);
        self::assertIsArray($manifest['payload'] ?? null);
        $specManifest = (new JmhzSpecPackageCatalog($root))->load(
            JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY,
            JmhzSpecPackageCatalog::DEFAULT_MANIFEST_SHA256,
        );

        return [[
            'manifest_sha256' => $manifest['manifest_sha256'],
            'payload' => $manifest['payload'],
        ], $specManifest];
    }
}
