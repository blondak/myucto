<?php

declare(strict_types=1);

/**
 * Výjimky mapování účtů do výkazů pro konkrétní firmu z příkazové řádky.
 *
 * Import (idempotentní — výjimka se stejným účtem a stranou zůstatku se přepíše, ostatní
 * výjimky firmy zůstanou; opakované spuštění se stejným souborem nic nezmění):
 *   php api/bin/statement-overrides.php --ico=12345678 --file=vyjimky.csv [--year=2025] [--replace] [--dry-run]
 *
 * Návrh výjimek z podaného přiznání DPPO (nic nezapisuje):
 *   php api/bin/statement-overrides.php --ico=12345678 --suggest --year=2025 --filed=podane.xml [--json]
 *
 * Výpis uložených výjimek:
 *   php api/bin/statement-overrides.php --ico=12345678 --list [--year=2025]
 *
 * Soubor CSV: account_prefix;row_code;balance_condition;note[;statement_type[;valid_from_year[;valid_to_year]]]
 *   oddělovač středník nebo čárka, hlavička volitelná, prázdné řádky a řádky začínající # se
 *   přeskočí. `account_prefix` smí končit hvězdičkou (351.* = všechny analytiky 351).
 * Soubor JSON: pole objektů se stejnými klíči.
 * `statement_type` (balance_sheet | income_statement | income_statement_purpose) je povinný
 * jen u kódu řádku, který existuje v rozvaze i ve výsledovce (A., C. …); jinak se odvodí.
 * alid_from_year / alid_to_year omezí platnost výjimky na účetní období (prázdné = bez omezení).
 * `--replace` nahradí celou sadu výjimek dotčených výkazů obsahem souboru.
 * Firmu lze místo IČO určit přes --supplier-id=<id>.
 *
 * Návratové kódy: 0 = hotovo, 1 = chyba běhu, 2 = chybné použití nebo neplatná data.
 */

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\StatementDefinitionRepository;
use MyInvoice\Repository\StatementOverrideRepository;
use MyInvoice\Service\Accounting\Reports\ReportException;
use MyInvoice\Service\Accounting\Reports\StatementOverrideService;
use MyInvoice\Service\Accounting\Reports\StatementOverrideSuggester;

const USAGE = <<<TXT
Použití:
  php api/bin/statement-overrides.php --ico=<IČO>|--supplier-id=<id> --file=<csv|json> [--year=RRRR] [--replace] [--dry-run]
  php api/bin/statement-overrides.php --ico=<IČO>|--supplier-id=<id> --suggest --year=RRRR --filed=<podané DPPO XML> [--json]
  php api/bin/statement-overrides.php --ico=<IČO>|--supplier-id=<id> --list [--year=RRRR]

TXT;

function soArg(array $argv, string $key): ?string
{
    foreach ($argv as $arg) {
        if (str_starts_with($arg, "--{$key}=")) {
            return substr($arg, strlen($key) + 3);
        }
    }
    return null;
}

function soFlag(array $argv, string $key): bool
{
    return in_array("--{$key}", $argv, true);
}

function soYear(mixed $value): ?int
{
    $value = trim((string) ($value ?? ''));
    return $value === '' ? null : (int) $value;
}

function soFail(string $message, int $code = 2): never
{
    fwrite(STDERR, $message . "\n");
    exit($code);
}

/**
 * @return list<array{line:int, account_prefix:string, row_code:string, balance_condition:string, note:?string, statement_type:?string, target:string, valid_from_year:?int, valid_to_year:?int}>
 */
function soReadFile(string $path): array
{
    if (!is_file($path)) {
        soFail("Soubor {$path} neexistuje.");
    }
    $raw = (string) file_get_contents($path);
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
    $items = [];

    if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'json') {
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            soFail("Soubor {$path} není platné JSON pole.");
        }
        foreach (array_values($data) as $i => $row) {
            if (!is_array($row)) {
                soFail(sprintf('JSON položka %d není objekt.', $i + 1));
            }
            $items[] = [
                'line'              => $i + 1,
                'account_prefix'    => trim((string) ($row['account_prefix'] ?? '')),
                'row_code'          => trim((string) ($row['row_code'] ?? '')),
                'balance_condition' => trim((string) ($row['balance_condition'] ?? '')) ?: 'any',
                'note'              => isset($row['note']) && trim((string) $row['note']) !== '' ? trim((string) $row['note']) : null,
                'statement_type'    => isset($row['statement_type']) && trim((string) $row['statement_type']) !== '' ? trim((string) $row['statement_type']) : null,
                'target'            => trim((string) ($row['target'] ?? '')) ?: 'gross',
                'valid_from_year'   => soYear($row['valid_from_year'] ?? null),
                'valid_to_year'     => soYear($row['valid_to_year'] ?? null),
            ];
        }
        return $items;
    }

    $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
    foreach ($lines as $n => $line) {
        if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
            continue;
        }
        $delimiter = str_contains($line, ';') ? ';' : ',';
        $cells = array_map('trim', str_getcsv($line, $delimiter, '"', ''));
        if (strtolower($cells[0] ?? '') === 'account_prefix') {
            continue;
        }
        $items[] = [
            'line'              => $n + 1,
            'account_prefix'    => $cells[0] ?? '',
            'row_code'          => $cells[1] ?? '',
            'balance_condition' => ($cells[2] ?? '') !== '' ? $cells[2] : 'any',
            'note'              => ($cells[3] ?? '') !== '' ? $cells[3] : null,
            'statement_type'    => ($cells[4] ?? '') !== '' ? $cells[4] : null,
            'target'            => 'gross',
            'valid_from_year'   => soYear($cells[5] ?? null),
            'valid_to_year'     => soYear($cells[6] ?? null),
        ];
    }
    return $items;
}

$ico = soArg($argv, 'ico');
$supplierArg = soArg($argv, 'supplier-id');
$yearArg = soArg($argv, 'year');
$file = soArg($argv, 'file');
$filed = soArg($argv, 'filed');
$suggest = soFlag($argv, 'suggest');
$list = soFlag($argv, 'list');
$dryRun = soFlag($argv, 'dry-run');
$replace = soFlag($argv, 'replace');
$asJson = soFlag($argv, 'json');

if (($ico === null && $supplierArg === null) || (!$suggest && !$list && $file === null)) {
    soFail(USAGE);
}
if ($yearArg !== null && preg_match('/^\d{4}$/', $yearArg) !== 1) {
    soFail('--year musí být rok RRRR.');
}

$container = Bootstrap::buildContainer();
$pdo = $container->get(Connection::class)->pdo();
/** @var StatementDefinitionRepository $definitions */
$definitions = $container->get(StatementDefinitionRepository::class);
/** @var StatementOverrideRepository $repository */
$repository = $container->get(StatementOverrideRepository::class);
/** @var StatementOverrideService $service */
$service = $container->get(StatementOverrideService::class);
/** @var AccountingPeriodRepository $periods */
$periods = $container->get(AccountingPeriodRepository::class);

if ($supplierArg !== null) {
    $stmt = $pdo->prepare('SELECT id, company_name FROM supplier WHERE id = ?');
    $stmt->execute([(int) $supplierArg]);
} else {
    $stmt = $pdo->prepare('SELECT id, company_name FROM supplier WHERE ic = ?');
    $stmt->execute([trim((string) $ico)]);
}
$suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (count($suppliers) !== 1) {
    soFail(count($suppliers) === 0
        ? 'Firma nenalezena.'
        : 'IČO odpovídá víc firmám — použijte --supplier-id=<id>.');
}
$supplierId = (int) $suppliers[0]['id'];
$companyName = (string) $suppliers[0]['company_name'];

$year = $yearArg !== null ? (int) $yearArg : (int) date('Y');
$period = $periods->findByYear($supplierId, $year);
$asOf = $period !== null ? (string) $period['ends_on'] : sprintf('%04d-12-31', $year);

try {
    if ($suggest) {
        if ($yearArg === null || $filed === null) {
            soFail('--suggest potřebuje --year=RRRR a --filed=<cesta k XML podaného přiznání>.');
        }
        if ($period === null) {
            soFail("Firma nemá účetní období za rok {$year}.");
        }
        if (!is_file($filed)) {
            soFail("Soubor {$filed} neexistuje.");
        }
        /** @var StatementOverrideSuggester $suggester */
        $suggester = $container->get(StatementOverrideSuggester::class);
        $result = $suggester->suggestFromXml($supplierId, (int) $period['id'], (string) file_get_contents($filed));
        if ($asJson) {
            echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            exit(0);
        }
        printf("Návrhy výjimek — %s (#%d), rok %d\n", $companyName, $supplierId, $year);
        printf("  rozdílných řádků přílohy: %d, návrhů: %d\n\n", count($result['differences']), count($result['suggestions']));
        foreach ($result['differences'] as $d) {
            printf("  %s ř. %-3d %-12s aplikace %8d  podané %8d  rozdíl %+d\n",
                $d['sentence'], $d['c_radku'], (string) ($d['row_code'] ?? '?'), $d['app'], $d['filed'], $d['diff']);
        }
        if ($result['suggestions'] !== []) {
            echo "\n";
            foreach ($result['suggestions'] as $s) {
                printf("  %s%-10s %-12s → %-12s %6d tis.  %s\n",
                    $s['ambiguous'] ? '? ' : '  ',
                    $s['account_code'], $s['from_row_code'], $s['to_row_code'], $s['amount_thousands'], $s['reason']);
            }
            echo "\n# CSV pro import (account_prefix;row_code;balance_condition;note;statement_type), nejisté jsou zakomentované:\n";
            foreach ($result['suggestions'] as $s) {
                foreach ($s['overrides'] as $o) {
                    printf("%s%s;%s;%s;Návrh z podaného přiznání %d;%s\n",
                        $s['ambiguous'] ? '# ' : '',
                        $o['account_prefix'], $o['row_code'], $o['balance_condition'], $year, $s['statement_type']);
                }
            }
        }
        exit(0);
    }

    if ($list) {
        printf("Výjimky mapování — %s (#%d), verze platné k %s\n", $companyName, $supplierId, $asOf);
        foreach (StatementOverrideService::TYPES as $type) {
            $version = $definitions->findVersion($type, $asOf);
            if ($version === null) {
                continue;
            }
            $items = $repository->forVersion($supplierId, (int) $version['id']);
            printf("\n%s (%s): %d\n", $type, (string) $version['version_code'], count($items));
            foreach ($items as $o) {
                printf("  %-10s %-6s → %-12s %-11s %s\n", $o['account_prefix'], $o['balance_condition'], $o['row_code'],
                    ($o['valid_from_year'] ?? $o['valid_to_year']) === null ? '' : (($o['valid_from_year'] ?? '…') . '–' . ($o['valid_to_year'] ?? '…')),
                    (string) ($o['note'] ?? ''));
            }
        }
        exit(0);
    }

    $items = soReadFile((string) $file);
    if ($items === []) {
        soFail("Soubor {$file} neobsahuje žádnou výjimku.");
    }

    $versions = [];
    $rowsByType = [];
    foreach (StatementOverrideService::TYPES as $type) {
        $version = $definitions->findVersion($type, $asOf);
        if ($version === null) {
            continue;
        }
        $versions[$type] = $version;
        $rowsByType[$type] = array_flip(array_map(
            static fn (array $r): string => (string) $r['row_code'],
            $definitions->rows((int) $version['id']),
        ));
    }

    // Rozdělení podle výkazu: explicitní statement_type, jinak ten z rozvahy / druhové
    // výsledovky, ve kterém kód řádku existuje (účelová VZZ jen výslovně).
    $errors = [];
    $byType = [];
    foreach ($items as $item) {
        $type = $item['statement_type'];
        if ($type !== null && !isset($versions[$type])) {
            $errors[] = sprintf('řádek %d: neznámý statement_type „%s".', $item['line'], $type);
            continue;
        }
        if ($type === null) {
            $candidates = array_values(array_filter(
                ['balance_sheet', 'income_statement'],
                static fn (string $t): bool => isset($rowsByType[$t][$item['row_code']]),
            ));
            if (count($candidates) !== 1) {
                $errors[] = sprintf(
                    count($candidates) === 0
                        ? 'řádek %d: řádek výkazu „%s" neexistuje.'
                        : 'řádek %d: řádek „%s" je v rozvaze i ve výsledovce — doplňte statement_type.',
                    $item['line'],
                    $item['row_code'],
                );
                continue;
            }
            $type = $candidates[0];
        }
        $byType[$type][] = $item;
    }
    if ($errors !== []) {
        soFail("Neplatná data:\n  " . implode("\n  ", $errors));
    }

    $prefix = $dryRun ? '[DRY-RUN] ' : '';
    printf("%sVýjimky mapování — %s (#%d), verze platné k %s\n", $prefix, $companyName, $supplierId, $asOf);
    $changedTotal = 0;
    foreach ($byType as $type => $typeItems) {
        $version = $versions[$type];
        $versionId = (int) $version['id'];
        $existing = [];
        foreach ($repository->forVersion($supplierId, $versionId) as $o) {
            $existing[$o['account_prefix'] . '|' . $o['balance_condition'] . '|' . ($o['valid_from_year'] ?? '')] = $o;
        }

        $merged = $replace ? [] : $existing;
        $report = ['added' => 0, 'changed' => 0, 'unchanged' => 0, 'removed' => 0];
        $lines = [];
        $seen = [];
        foreach ($typeItems as $item) {
            $key = rtrim($item['account_prefix'], '*') . '|' . $item['balance_condition'] . '|' . ($item['valid_from_year'] ?? '');
            $seen[$key] = true;
            $new = [
                'account_prefix'    => rtrim($item['account_prefix'], '*'),
                'row_code'          => $item['row_code'],
                'target'            => $item['target'],
                'balance_condition' => $item['balance_condition'],
                'sign'              => 1,
                'note'              => $item['note'],
                'valid_from_year'   => $item['valid_from_year'],
                'valid_to_year'     => $item['valid_to_year'],
            ];
            $old = $existing[$key] ?? null;
            if ($old === null) {
                $report['added']++;
                $lines[] = sprintf('  + %-10s %-6s → %s', $new['account_prefix'], $new['balance_condition'], $new['row_code']);
            } elseif ($old['row_code'] === $new['row_code'] && (string) $old['target'] === $new['target']
                && (string) ($old['note'] ?? '') === (string) ($new['note'] ?? '')
                && ($old['valid_to_year'] ?? null) === $new['valid_to_year']) {
                $report['unchanged']++;
            } else {
                $report['changed']++;
                $lines[] = sprintf('  ~ %-10s %-6s %s → %s', $new['account_prefix'], $new['balance_condition'], $old['row_code'], $new['row_code']);
            }
            $merged[$key] = $new;
        }
        if ($replace) {
            foreach ($existing as $key => $o) {
                if (!isset($seen[$key])) {
                    $report['removed']++;
                    $lines[] = sprintf('  - %-10s %-6s → %s', $o['account_prefix'], $o['balance_condition'], $o['row_code']);
                }
            }
        }

        // Validace celé výsledné sady (řádky verze, duplicity, párování saldových stran).
        $service->validateSet($supplierId, $version, array_values($merged));

        printf("\n%s (%s): přidáno %d, změněno %d, beze změny %d, odebráno %d\n",
            $type, (string) $version['version_code'], $report['added'], $report['changed'], $report['unchanged'], $report['removed']);
        foreach ($lines as $line) {
            echo $line . "\n";
        }
        $changes = $report['added'] + $report['changed'] + $report['removed'];
        $changedTotal += $changes;
        if (!$dryRun && $changes > 0) {
            $service->save($supplierId, $versionId, array_values($merged), null);
        }
    }

    if ($dryRun) {
        echo "\nDry-run nic nezapsal; pro uložení spusťte bez --dry-run.\n";
    } elseif ($changedTotal === 0) {
        echo "\nVše už bylo uložené, nic se nezměnilo.\n";
    }
    exit(0);
} catch (ReportException $e) {
    soFail('Chyba: ' . $e->getMessage(), 2);
} catch (Throwable $e) {
    soFail('Chyba: ' . $e->getMessage(), 1);
}
