<?php

declare(strict_types=1);

/**
 * Profil firmy z příkazové řádky: ručně vybudované nastavení (výjimky mapování výkazů,
 * volby výkazů a uzávěrky, daňový profil, dimenze, předkontace, pravidla banky), které
 * nový převod firmy z jiného programu nezaloží.
 *
 * Export (bez --file vypíše JSON na výstup):
 *   php api/bin/company-profile.php --ico=12345678|--supplier-id=<id> --export [--file=profil.json] [--sections=a,b]
 *
 * Nahrání (výchozí je ostré nahrání; --dry-run jen ukáže, co by se změnilo):
 *   php api/bin/company-profile.php --ico=12345678|--supplier-id=<id> --import --file=profil.json [--dry-run] [--sections=a,b] [--json]
 *
 * Typický opakovaný převod: export před smazáním firmy, převod, nahrání profilu.
 * Nahrání je idempotentní: opakované spuštění se stejným souborem nic nezmění.
 *
 * Sekce: company, tax_profile, accounting_settings, statement_overrides, dimensions,
 * dimension_defaults, dimension_rules, posting_rules, bank_rule_templates, bank_posting_rules.
 *
 * Návratové kódy: 0 = hotovo, 1 = chyba běhu, 2 = chybné použití nebo neplatný profil.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Settings\CompanyProfile\CompanyProfileException;
use MyInvoice\Service\Settings\CompanyProfile\CompanyProfileExporter;
use MyInvoice\Service\Settings\CompanyProfile\CompanyProfileImporter;

const CP_USAGE = <<<TXT
Použití:
  php api/bin/company-profile.php --ico=<IČO>|--supplier-id=<id> --export [--file=<json>] [--sections=a,b]
  php api/bin/company-profile.php --ico=<IČO>|--supplier-id=<id> --import --file=<json> [--dry-run] [--sections=a,b] [--json]

TXT;

function cpFail(string $message, int $code = 2): never
{
    fwrite(STDERR, $message . "\n");
    exit($code);
}

$opts = getopt('', ['ico:', 'supplier-id:', 'export', 'import', 'file:', 'dry-run', 'sections:', 'json']);
$export = array_key_exists('export', $opts);
$import = array_key_exists('import', $opts);
if ((!isset($opts['ico']) && !isset($opts['supplier-id'])) || $export === $import) {
    cpFail(CP_USAGE);
}
$file = isset($opts['file']) ? (string) $opts['file'] : null;
if ($import && $file === null) {
    cpFail('--import potřebuje --file=<profil.json>.');
}
$sections = isset($opts['sections'])
    ? array_values(array_filter(array_map('trim', explode(',', (string) $opts['sections']))))
    : null;

$container = Bootstrap::buildContainer();
$pdo = $container->get(Connection::class)->pdo();
if (isset($opts['supplier-id'])) {
    $stmt = $pdo->prepare('SELECT id, company_name FROM supplier WHERE id = ?');
    $stmt->execute([(int) $opts['supplier-id']]);
} else {
    $stmt = $pdo->prepare('SELECT id, company_name FROM supplier WHERE ic = ?');
    $stmt->execute([trim((string) $opts['ico'])]);
}
$suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (count($suppliers) !== 1) {
    cpFail(count($suppliers) === 0 ? 'Firma nenalezena.' : 'IČO odpovídá víc firmám, použijte --supplier-id=<id>.');
}
$supplierId = (int) $suppliers[0]['id'];
$companyName = (string) $suppliers[0]['company_name'];

try {
    if ($export) {
        /** @var CompanyProfileExporter $exporter */
        $exporter = $container->get(CompanyProfileExporter::class);
        $profile = $exporter->export($supplierId, $sections);
        $json = json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if ($file === null) {
            echo $json;
            exit(0);
        }
        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            cpFail("Adresář {$dir} nejde založit.", 1);
        }
        file_put_contents($file, $json);
        fprintf(STDERR, "Profil firmy %s (#%d) uložen do %s\n", $companyName, $supplierId, $file);
        foreach ($profile['sections'] as $name => $data) {
            $count = match ($name) {
                'dimensions' => count($data['types'] ?? []),
                'statement_overrides' => array_sum(array_map(static fn (array $g): int => count($g['items']), $data)),
                default => count($data),
            };
            fprintf(STDERR, "  %-22s %d\n", $name, $count);
        }
        exit(0);
    }

    if (!is_file((string) $file)) {
        cpFail("Soubor {$file} neexistuje.");
    }
    $raw = (string) file_get_contents((string) $file);
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
    $profile = json_decode($raw, true);
    if (!is_array($profile)) {
        cpFail("Soubor {$file} není platný JSON.");
    }
    /** @var CompanyProfileImporter $importer */
    $importer = $container->get(CompanyProfileImporter::class);
    $dryRun = array_key_exists('dry-run', $opts);
    $result = $importer->import($supplierId, $profile, $dryRun, $sections);
    if (array_key_exists('json', $opts)) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        exit(0);
    }
    printf("%sProfil firmy → %s (#%d)\n", $dryRun ? '[DRY-RUN] ' : '', $companyName, $supplierId);
    foreach ($result['warnings'] as $w) {
        printf("  ! %s\n", $w);
    }
    foreach ($result['sections'] as $name => $s) {
        printf("\n%s: přidáno %d, změněno %d, odebráno %d, beze změny %d\n", $name, $s['created'], $s['updated'], $s['removed'], $s['unchanged']);
        foreach ($s['changes'] as $line) {
            printf("  %s\n", $line);
        }
        foreach ($s['warnings'] as $w) {
            printf("  ! %s\n", $w);
        }
    }
    if ($dryRun) {
        echo "\nDry-run nic nezapsal; pro nahrání spusťte bez --dry-run.\n";
    } elseif ($result['changed'] === 0) {
        echo "\nVše už bylo nastavené, nic se nezměnilo.\n";
    }
    exit(0);
} catch (CompanyProfileException $e) {
    cpFail('Chyba' . ($e->section !== null ? " v sekci {$e->section}" : '') . ': ' . $e->getMessage(), 2);
} catch (Throwable $e) {
    cpFail('Chyba: ' . $e->getMessage(), 1);
}
