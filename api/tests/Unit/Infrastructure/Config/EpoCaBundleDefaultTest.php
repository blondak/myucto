<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Infrastructure\Config;

use MyInvoice\Infrastructure\Config\Config;
use PHPUnit\Framework\TestCase;

/**
 * Výchozí CA bundle pro ověření pečeti dodejky EPO.
 *
 * Motivace je z ostrého provozu: `epo.ca_bundle_path` bylo ve výchozím stavu prázdné,
 * ověření tedy spadlo na systémový CA store — a ten je bundle pro TLS, takže žádnou
 * českou kvalifikovanou autoritu neobsahuje. Kontrolní hlášení, které správce daně
 * bez potíží přijal, tak skončilo ve stavu „nejistý výsledek". Instalace, která sekci
 * `epo` v cfg.php vůbec nemá, proto musí dostat funkční bundle sama od sebe.
 */
final class EpoCaBundleDefaultTest extends TestCase
{
    private const RELATIVE_PATH = 'api/resources/epo/epo-ca-bundle.pem';

    private string $tmpRoot;

    protected function setUp(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/myinvoice-epo-ca-' . bin2hex(random_bytes(6));
        mkdir($this->tmpRoot, 0700, true);
        file_put_contents($this->tmpRoot . '/cfg.php', "<?php\n\nreturn [];\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->tmpRoot . '/cfg.php');
        @rmdir($this->tmpRoot);
    }

    public function testInstallationWithoutEpoSectionStillGetsTheBundle(): void
    {
        $config = Config::load($this->tmpRoot);

        self::assertSame(
            self::RELATIVE_PATH,
            $config->get('epo.ca_bundle_path'),
            'Prázdný cfg.php musí dostat výchozí bundle — jinak ostré podání skončí jako nejisté.'
        );
    }

    public function testExplicitConfigurationStillWins(): void
    {
        file_put_contents(
            $this->tmpRoot . '/cfg.php',
            "<?php\n\nreturn ['epo' => ['ca_bundle_path' => 'vlastni/bundle.pem']];\n"
        );

        $config = Config::load($this->tmpRoot);

        self::assertSame(
            'vlastni/bundle.pem',
            $config->get('epo.ca_bundle_path'),
            'Provozovatel s vlastním bundlem musí default přebít.'
        );
    }

    /**
     * Default je fail-closed: nastavená cesta bez souboru ověření NEZACHRÁNÍ pádem zpět
     * na jiný trust store. Smysl má proto jen tehdy, když se soubor s aplikací opravdu
     * nasazuje — což hlídá tenhle test, ne dobrá vůle.
     */
    public function testShippedBundleExistsAndParsesAsCertificates(): void
    {
        $path = dirname(__DIR__, 5) . '/' . self::RELATIVE_PATH;
        self::assertFileExists($path, 'Výchozí bundle musí být součástí repozitáře.');

        $pem = (string) file_get_contents($path);
        self::assertSame(
            1,
            preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $pem, $matches) > 0 ? 1 : 0,
            'Bundle musí obsahovat aspoň jeden certifikát.'
        );

        // `subject` u certifikátů s `organizationIdentifier` PHP nenaplní, proto se čte
        // ze zploštělého `name` — stejná past jako v cmd/update-epo-ca-bundle.php.
        $names = [];
        foreach ($matches[0] as $certificate) {
            $parsed = openssl_x509_parse($certificate, false);
            self::assertIsArray($parsed, 'Každá položka bundlu musí být čitelný certifikát.');
            self::assertGreaterThan(
                time(),
                (int) ($parsed['validTo_time_t'] ?? 0),
                'Vypršelá kotva v trust storu nic neověří a jen drží falešné varování.'
            );
            $names[] = (string) ($parsed['name'] ?? '');
        }

        // Pečeť EPO vydává I.CA — ověřeno na skutečné dodejce z ostrého provozu.
        self::assertNotEmpty(
            array_filter($names, static fn (string $n): bool => str_contains($n, 'I.CA')),
            'Bundle musí držet kořeny I.CA, jinak produkční dodejku neověří: ' . implode(' | ', $names)
        );
    }
}
