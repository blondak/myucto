<?php

declare(strict_types=1);

/**
 * Kontrola souběhu se starým účetním programem za měsíc z příkazové řádky (K1, K5–K13).
 *
 *   php api/bin/parallel-run-check.php --ico=12345678 --month=2026-10 --source=money_s3 \
 *       [--trial-balance=predvaha.csv] [--document-counts=pocty.csv] [--saldo=saldo.csv] \
 *       [--bank-balances=banky.csv] [--vat-return=dphdp3.xml] [--control-statement=dphkh1.xml] \
 *       [--assets=majetek.csv] [--cost-centers=strediska.csv] [--balance-sheet=rozvaha.csv] \
 *       [--income-statement=vysledovka.csv] [--statement-unit=1000] \
 *       [--money-backup=agenda.lz | --money-backup-dir=<rozbalená záloha>] [--save] [--json]
 *
 * Záloha Money S3 se čte bez zápisu do MyÚčta (předvaha a počty dokladů měsíce). Do
 * účetnictví kontrola nic nezapisuje; `--save` uloží protokol do historie cyklů (stránka
 * Účetnictví → Souběh se starým systémem), bez něj se jen vypíše.
 * `--source`: money_s3 (výchozí), pohoda, premier, other. Firmu lze místo IČO určit
 * přes --supplier-id=<id>.
 *
 * Návratové kódy: 0 = sedí, 3 = rozdíly nebo neúplná kontrola, 1 = chyba běhu,
 * 2 = chybné použití.
 */

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ParallelRunCheckRepository;
use MyInvoice\Service\Migration\MoneyS3\Ms3Backup;
use MyInvoice\Service\Migration\Shared\ParallelRun\ParallelRunException;
use MyInvoice\Service\Migration\Shared\ParallelRun\ParallelRunInput;
use MyInvoice\Service\Migration\Shared\ParallelRun\ParallelRunReconciliation;
use MyInvoice\Service\Migration\Shared\ParallelRun\ParallelRunService;
use MyInvoice\Service\Migration\Shared\ParallelRun\ParallelRunSources;

const PR_USAGE = <<<TXT
Použití:
  php api/bin/parallel-run-check.php --ico=<IČO>|--supplier-id=<id> --month=RRRR-MM [--source=money_s3|pohoda|premier|other]
      [--trial-balance=<csv>] [--document-counts=<csv>] [--saldo=<csv>] [--bank-balances=<csv>]
      [--vat-return=<xml>] [--control-statement=<xml>] [--assets=<csv>] [--cost-centers=<csv>]
      [--balance-sheet=<csv>] [--income-statement=<csv>] [--statement-unit=1|1000]
      [--money-backup=<lz>|--money-backup-dir=<adresář>] [--save] [--json]

TXT;

function prArg(array $argv, string $key): ?string
{
    foreach ($argv as $arg) {
        if (str_starts_with($arg, "--{$key}=")) {
            return substr($arg, strlen($key) + 3);
        }
    }
    return null;
}

function prFail(string $message, int $code = 2): never
{
    fwrite(STDERR, $message . "\n");
    exit($code);
}

function prRemoveDir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}

$ico = prArg($argv, 'ico');
$supplierArg = prArg($argv, 'supplier-id');
$month = prArg($argv, 'month');
$sourceKey = prArg($argv, 'source') ?? 'money_s3';
$save = in_array('--save', $argv, true);
$asJson = in_array('--json', $argv, true);
if (($ico === null && $supplierArg === null) || $month === null) {
    prFail(PR_USAGE);
}

$files = [];
$names = [];
foreach (ParallelRunInput::fileKinds() as $kind) {
    $path = prArg($argv, str_replace('_', '-', $kind));
    if ($path === null) {
        continue;
    }
    if (!is_file($path)) {
        prFail("Soubor {$path} neexistuje.");
    }
    $files[$kind] = (string) file_get_contents($path);
    $names[$kind] = basename($path);
}

$tempDir = null;
$backupDir = prArg($argv, 'money-backup-dir');
$backupLz = prArg($argv, 'money-backup');
if ($backupLz !== null) {
    if (!is_file($backupLz)) {
        prFail("Záloha {$backupLz} neexistuje.");
    }
    $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'parallel_run_' . bin2hex(random_bytes(6));
    Ms3Backup::extract($backupLz, $tempDir);
    $backupDir = $tempDir;
}
if ($files === [] && $backupDir === null) {
    prFail('Zadejte aspoň jeden výstup starého programu nebo zálohu agendy.');
}

try {
    $container = Bootstrap::buildContainer();
    $pdo = $container->get(Connection::class)->pdo();
    if ($supplierArg !== null) {
        $stmt = $pdo->prepare('SELECT id, company_name FROM supplier WHERE id = ?');
        $stmt->execute([(int) $supplierArg]);
    } else {
        $stmt = $pdo->prepare('SELECT id, company_name FROM supplier WHERE ic = ?');
        $stmt->execute([trim((string) $ico)]);
    }
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($suppliers) !== 1) {
        prFail(count($suppliers) === 0 ? 'Firma nenalezena.' : 'IČO odpovídá víc firmám — použijte --supplier-id=<id>.');
    }
    $supplierId = (int) $suppliers[0]['id'];

    [$year, $monthNo] = ParallelRunService::parseMonth($month);
    $source = (new ParallelRunSources())->get($sourceKey);
    $options = ['statement_unit' => (float) (prArg($argv, 'statement-unit') ?? 1)];
    if ($backupDir !== null) {
        if (!$source->readsBackup()) {
            prFail('Zálohu agendy umí kontrola číst jen u --source=money_s3.');
        }
        $options['backup_dir'] = $backupDir;
        $options['backup_sha256'] = $backupLz !== null ? (string) hash_file('sha256', $backupLz) : '';
    }
    $snapshot = $source->snapshot($year, $monthNo, $files, $names, $options);
    $result = $container->get(ParallelRunReconciliation::class)->run($supplierId, $year, $monthNo, $snapshot);
    if ($save) {
        $id = $container->get(ParallelRunCheckRepository::class)->create($supplierId, $result['month'], $source->key(), $result['status'], $snapshot->inputs, $result, null);
        $result['check_id'] = $id;
    }
} catch (ParallelRunException $e) {
    prFail($e->getMessage());
} finally {
    if ($tempDir !== null) {
        prRemoveDir($tempDir);
    }
}

if ($asJson) {
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION) . "\n";
} else {
    printf("%s — kontrola souběhu %s (%s): %s%s\n", (string) $suppliers[0]['company_name'], $result['month'], $result['source'], $result['status'],
        isset($result['check_id']) ? ', uloženo #' . $result['check_id'] : '');
    foreach ($result['warnings'] as $warning) {
        echo "  ! {$warning}\n";
    }
    foreach ($result['criteria'] as $c) {
        printf("  %-4s %-12s %s\n", $c['key'], $c['status'], $c['status'] === 'error' ? (string) ($c['message'] ?? '') : ($c['difference_count'] > 0 ? $c['difference_count'] . ' rozdílů' : ''));
        foreach (array_slice($c['differences'], 0, 20) as $d) {
            $values = implode(', ', array_map(static fn (array $v): string => sprintf('%s %s / %s', $v['field'],
                $v['mine'] === null ? '–' : number_format((float) $v['mine'], 2, ',', ' '),
                $v['theirs'] === null ? '–' : number_format((float) $v['theirs'], 2, ',', ' ')), $d['values']));
            printf("       %s%s: %s%s\n", $d['subject'], $d['label'] !== null && $d['label'] !== '' ? ' (' . $d['label'] . ')' : '', $values, $d['note'] !== null ? ' [' . $d['note'] . ']' : '');
        }
        if ($c['difference_count'] > 20) {
            printf("       … a dalších %d\n", $c['difference_count'] - 20);
        }
    }
}
exit($result['status'] === 'ok' ? 0 : 3);
