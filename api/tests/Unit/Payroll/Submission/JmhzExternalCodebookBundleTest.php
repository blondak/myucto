<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzExternalCodebookCatalog;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 5) . '/tools/JmhzExternalCodebookPackageBuilder.php';

final class JmhzExternalCodebookBundleTest extends TestCase
{
    public function testBundleHasExactSourcesCountsAndDeterministicManifest(): void
    {
        $root = dirname(__DIR__, 5);
        $directory = $root . '/api/resources/payroll/jmhz/external-codebooks-2026-08-13';
        self::assertSame(
            '940d3ebef6d42294da79c7611654a59aef5beead3a48ffbdffdac9d0f1c58886',
            hash_file('sha256', $directory . '/CIS1186_CS_2026-08-13.csv'),
        );
        self::assertSame(
            'b4f130984c94904d083306b19e47f146e6e703847d315219daf97589a7526d44',
            hash_file('sha256', $directory . '/sb-2025-511-priloha-2-fragment-1093782.ttl'),
        );
        $manifest = json_decode(
            (string) file_get_contents($directory . '/manifest.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        JmhzExternalCodebookCatalog::validateManifest($manifest, true);
        self::assertSame(6254, $manifest['payload']['counts']['municipalities']);
        self::assertSame(250, $manifest['payload']['counts']['countries']);
        self::assertSame(6504, $manifest['payload']['counts']['entries']);

        $temporary = tempnam(sys_get_temp_dir(), 'jmhz-codebooks-');
        self::assertIsString($temporary);
        try {
            (new \JmhzExternalCodebookPackageBuilder())->build($directory, $temporary);
            self::assertSame(
                hash_file('sha256', $directory . '/manifest.json'),
                hash_file('sha256', $temporary),
            );
        } finally {
            @unlink($temporary);
        }
    }
}
