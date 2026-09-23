<?php

declare(strict_types=1);

/**
 * Převzetí podaného přiznání k DPPO (EPO XML DPPDP9) do rozpracovaného přiznání firmy
 * a do evidence daňových ztrát, viz {@see \MyInvoice\Service\Migration\Shared\FiledDppoImporter}.
 *
 *   php api/bin/tax-return-import.php --ico=12345678 --filed=podane.xml [--year=2025]
 *   php api/bin/tax-return-import.php --ico=12345678 --filed=adresar-s-xml [--replace-draft] [--dry-run]
 *
 * Adresář: projde všechny *.xml (i v podadresářích), přiznání cizí firmy přeskočí a za
 * každý rok vezme poslední podání (dodatečné před opravným před řádným, u více
 * dodatečných pozdější datum zjištění). Roky zpracuje vzestupně, aby ztráta vzniklá
 * v jednom roce navázala na její uplatnění v dalších letech.
 *
 * Volby:
 *   --replace-draft    přepíše vstupy existujícího rozpracovaného přiznání (jen vstupy,
 *                      které převzetí vlastní); bez ní se existující přiznání nemění
 *   --no-losses        nezapisuje do evidence daňových ztrát
 *   --loss=RRRR:KČ     ztráta roku, za který podání není (vznikla před převzetím); lze opakovat
 *   --dry-run          jen náhled, nic nezapisuje
 *   --json             výstup jako JSON
 * Firmu lze místo IČO určit přes --supplier-id=<id>.
 *
 * Návratové kódy: 0 = hotovo, 1 = chyba běhu, 2 = chybné použití nebo neplatná data.
 */

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\Shared\FiledDppoFiling;
use MyInvoice\Service\Migration\Shared\FiledDppoImporter;
use MyInvoice\Service\Tax\Return\DppoEpoXmlParser;
use MyInvoice\Service\Tax\Return\TaxLossService;
use MyInvoice\Service\Tax\Return\TaxReturnException;

const USAGE = <<<TXT
Použití:
  php api/bin/tax-return-import.php --ico=<IČO>|--supplier-id=<id> --filed=<xml|adresář> [--year=RRRR]
      [--replace-draft] [--no-losses] [--loss=RRRR:KČ ...] [--dry-run] [--json]

TXT;

/** @return list<string> */
function triArgs(array $argv, string $key): array
{
    $out = [];
    foreach ($argv as $arg) {
        if (str_starts_with($arg, "--{$key}=")) {
            $out[] = substr($arg, strlen($key) + 3);
        }
    }
    return $out;
}

function triArg(array $argv, string $key): ?string
{
    return triArgs($argv, $key)[0] ?? null;
}

function triFail(string $message, int $code = 2): never
{
    fwrite(STDERR, $message . "\n");
    exit($code);
}

/** @return list<string> */
function triFiles(string $path): array
{
    if (is_file($path)) {
        return [$path];
    }
    if (!is_dir($path)) {
        triFail("Soubor ani adresář {$path} neexistuje.");
    }
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && strtolower($f->getExtension()) === 'xml') {
            $files[] = $f->getPathname();
        }
    }
    sort($files);
    return $files;
}

$ico = triArg($argv, 'ico');
$supplierArg = triArg($argv, 'supplier-id');
$filed = triArg($argv, 'filed');
$yearArg = triArg($argv, 'year');
$dryRun = in_array('--dry-run', $argv, true);
$json = in_array('--json', $argv, true);
$replaceDraft = in_array('--replace-draft', $argv, true);
$registerLosses = !in_array('--no-losses', $argv, true);
if (($ico === null && $supplierArg === null) || $filed === null) {
    fwrite(STDERR, USAGE);
    exit(2);
}
if ($yearArg !== null && preg_match('/^\d{4}$/', $yearArg) !== 1) {
    triFail('--year musí být rok RRRR.');
}
$openingLosses = [];
foreach (triArgs($argv, 'loss') as $spec) {
    if (preg_match('/^(\d{4}):(\d+(?:[.,]\d{1,2})?)$/', $spec, $m) !== 1) {
        triFail("--loss={$spec}: očekávám RRRR:ČÁSTKA.");
    }
    $openingLosses[(int) $m[1]] = (float) str_replace(',', '.', $m[2]);
}

$container = Bootstrap::buildContainer();
$pdo = $container->get(Connection::class)->pdo();
/** @var FiledDppoImporter $importer */
$importer = $container->get(FiledDppoImporter::class);
/** @var DppoEpoXmlParser $parser */
$parser = $container->get(DppoEpoXmlParser::class);
/** @var TaxLossService $losses */
$losses = $container->get(TaxLossService::class);

if ($supplierArg !== null) {
    $stmt = $pdo->prepare('SELECT id, company_name, ic FROM supplier WHERE id = ?');
    $stmt->execute([(int) $supplierArg]);
} else {
    $stmt = $pdo->prepare('SELECT id, company_name, ic FROM supplier WHERE ic = ?');
    $stmt->execute([trim((string) $ico)]);
}
$suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (count($suppliers) !== 1) {
    triFail(count($suppliers) === 0 ? 'Firma nenalezena.' : 'IČO odpovídá víc firmám — použijte --supplier-id=<id>.');
}
$supplierId = (int) $suppliers[0]['id'];
$supplierIc = ltrim(trim((string) $suppliers[0]['ic']), '0');

// Za každý rok poslední podání této firmy.
$entries = [];
$skipped = [];
foreach (triFiles($filed) as $file) {
    $xml = (string) file_get_contents($file);
    try {
        $parsed = $parser->parse($xml);
        $year = $importer->filedYear($parsed);
        $filing = FiledDppoFiling::parse($xml);
    } catch (TaxReturnException $e) {
        $skipped[] = ['file' => $file, 'reason' => $e->getMessage()];
        continue;
    }
    if ($yearArg !== null && $year !== (int) $yearArg) {
        continue;
    }
    $entries[] = ['filing' => $filing, 'file' => $file, 'xml' => $xml];
}
$byYear = FiledDppoFiling::latestPerYear($entries, $supplierIc, static fn (array $e): string => $e['file']);
if ($byYear === []) {
    triFail('Žádné podané přiznání DPPDP9 této firmy' . ($yearArg !== null ? " za rok {$yearArg}" : '') . '.');
}

$report = ['supplier_id' => $supplierId, 'company' => (string) $suppliers[0]['company_name'], 'dry_run' => $dryRun, 'opening_losses' => [], 'years' => [], 'skipped' => $skipped];
try {
    if (!$dryRun) {
        foreach ($openingLosses as $originYear => $amount) {
            $losses->registerOpeningLoss($supplierId, 'po', $originYear, $amount);
            $report['opening_losses'][] = ['year' => $originYear, 'amount' => $amount];
        }
    }
    foreach ($byYear as $year => $entry) {
        $result = $dryRun
            ? $importer->preview($supplierId, $entry['xml'], $year)
            : $importer->apply($supplierId, $entry['xml'], null, $year, 'radne', 1, $replaceDraft, $registerLosses);
        $mismatches = [];
        foreach ($result['diff']['rows'] as $row) {
            if (!$row['match']) {
                $mismatches[] = ['line' => $row['line'], 'kind' => $row['kind'], 'our' => $row['our_value'], 'filed' => $row['filed_value'], 'diff' => $row['diff']];
            }
        }
        $report['years'][] = [
            'year' => $year,
            'file' => $entry['file'],
            'forma' => $result['filing']['dapdpp_forma'],
            'status' => $result['status'] ?? 'preview',
            'input_mismatches' => $result['input_mismatches'],
            'mismatches' => $mismatches,
            'losses' => $result['losses'],
            'notices' => $result['notices'],
            'blocked' => $result['blocked'],
        ];
    }
} catch (\Throwable $e) {
    triFail($e->getMessage(), $e instanceof TaxReturnException && $e->httpStatus < 500 ? 2 : 1);
}

if ($json) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
    exit(0);
}
printf("%sPřevzetí podaného DPPO — %s (#%d)\n", $dryRun ? '[náhled] ' : '', $report['company'], $supplierId);
foreach ($report['opening_losses'] as $l) {
    printf("  ztráta roku %d doplněna do evidence: %s Kč\n", $l['year'], number_format($l['amount'], 2, ',', ' '));
}
foreach ($report['years'] as $y) {
    printf("\n%d (%s, forma %s): %s\n", $y['year'], basename($y['file']), $y['forma'], $y['blocked'] ?? $y['status']);
    printf("  ztráta vzniklá %s Kč, uplatněná %s Kč; rozdíly na řádcích ze vstupů: %d\n",
        number_format($y['losses']['year_loss'], 0, ',', ' '), number_format($y['losses']['applied'], 0, ',', ' '), $y['input_mismatches']);
    foreach ($y['mismatches'] as $m) {
        printf("  ř. %-4d %-10s MyÚčto %15s  podání %15s  rozdíl %15s\n", $m['line'], $m['kind'],
            number_format($m['our'], 2, ',', ' '), number_format($m['filed'], 2, ',', ' '), number_format($m['diff'], 2, ',', ' '));
    }
    foreach ($y['notices'] as $n) {
        echo '  - ', $n, "\n";
    }
}
foreach ($skipped as $s) {
    printf("\nPřeskočeno %s: %s\n", $s['file'], $s['reason']);
}
exit(0);
