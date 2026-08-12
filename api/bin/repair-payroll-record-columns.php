<?php

declare(strict_types=1);

/**
 * Srovnání SLOUPCŮ `payroll_monthly_records` s rozpadem v `breakdown`.
 *
 * ── Co je špatně ────────────────────────────────────────────────────────────
 * Řádek nese rozpad dvakrát: jako JSON v `breakdown` a jako denormalizované sloupce
 * `tax_credit_taxpayer`, `tax_credit_children`, `advance_tax_final`, `net_final`.
 * Sloupce se zapsaly ve chvíli, kdy se sleva na poplatníka uplatňovala i bez
 * podepsaného prohlášení (§ 38k ZDP). Po opravě v
 * {@see \MyInvoice\Service\Accounting\Payroll\PayrollPostingService::employeeContext()}
 * počítá `breakdown` správně (`credit_total` 0, `advance_tax_withheld` 675), ale
 * sloupce v HISTORICKÝCH řádcích zůstaly na starých hodnotách (sleva 2 570,
 * sražená daň 0, čistá mzda o slevu vyšší). Přepočet se nespustí sám — snapshot
 * se přepisuje jen novým zaúčtováním téhož měsíce.
 *
 * Mzdový list §38j ({@see \MyInvoice\Service\Accounting\Payroll\PayrollSheetService})
 * bere `advance_tax` z JSONu, ale slevu, sraženou daň a čistou mzdu ze SLOUPCŮ —
 * a v jednom řádku sestavy pak stojí hrubá záloha z JSONu proti slevě ze sloupce,
 * která už neplatí. Výsledek si protiřečí sám se sebou i s deníkem.
 *
 * ── Co skript dělá ──────────────────────────────────────────────────────────
 * Přepočte ty čtyři sloupce Z `breakdown`, který je zdroj pravdy (je to tentýž
 * snapshot, ze kterého vznikla kontace v deníku):
 *
 *   tax_credit_taxpayer  ← breakdown.credit_taxpayer
 *   tax_credit_children  ← breakdown.credit_children
 *   advance_tax_final    ← breakdown.advance_tax_withheld   (fallback advance_tax)
 *   net_final            ← breakdown.net
 *
 * `breakdown`, `gross`, vazbu na deník ani samotný DENÍK NEMĚNÍ — kontace zůstává
 * beze změny, srovnává se jen druhá kopie týchž čísel.
 *
 * ── Kontrola konzistence JSONu ──────────────────────────────────────────────
 * Řádek, jehož `breakdown` nesedí sám se sebou (`gross − srážky − sražená daň ≠ net`,
 * nebo složky zdravotního nedají `health_total`), se přepíše taky — sloupce z něj
 * pořád vyjdou lépe než dosavadní zastaralé hodnoty — ale VYPÍŠE se jako varování.
 * Takový snapshot patří člověku: buď ho dorovnal
 * `backfill-payroll-records.php --reconcile` na ručně zaúčtovaný deník, nebo vznikl
 * jiným modelem zaokrouhlení a je potřeba rozhodnout, která verze platí.
 *
 * Použití:
 *   php api/bin/repair-payroll-record-columns.php --supplier=1                  # DRY-RUN
 *   php api/bin/repair-payroll-record-columns.php --supplier=1 --apply
 *   php api/bin/repair-payroll-record-columns.php --supplier=1 --year=2025 --apply
 *   php api/bin/repair-payroll-record-columns.php --supplier=1 --employee=1
 *
 * Argumenty:
 *   --supplier=<id>   (povinné) firma
 *   --employee=<id>   (volitelné) jen jeden zaměstnanec
 *   --year=<RRRR>     (volitelné) jen jeden rok
 *   --apply           ostrý běh (bez něj DRY-RUN — nic nezapíše)
 */

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;

/** @return string|null hodnota --key=value nebo null */
function argValue(array $argv, string $key): ?string
{
    foreach ($argv as $a) {
        if (str_starts_with($a, "--{$key}=")) {
            return substr($a, strlen($key) + 3);
        }
    }
    return null;
}

/**
 * Sloupcové hodnoty odvozené z rozpadu.
 *
 * `advance_tax_withheld` chybí ve snapshotech uložených před zavedením slev — tehdy
 * se žádná sleva neuplatňovala, takže sražená = hrubá záloha.
 *
 * @param array<string,mixed> $b
 * @return array{tax_credit_taxpayer:int,tax_credit_children:int,advance_tax_final:int,net_final:int}
 */
function columnsFromBreakdown(array $b): array
{
    $advance = (int) ($b['advance_tax_withheld'] ?? $b['advance_tax'] ?? 0);

    return [
        'tax_credit_taxpayer' => (int) ($b['credit_taxpayer'] ?? 0),
        'tax_credit_children' => (int) ($b['credit_children'] ?? 0),
        'advance_tax_final'   => $advance,
        'net_final'           => (int) ($b['net'] ?? 0),
    ];
}

/**
 * Vnitřní rozpory rozpadu — proč by přepočtené sloupce mohly být samy o sobě sporné.
 *
 * @param array<string,mixed> $b
 * @return list<string>
 */
function breakdownWarnings(array $b): array
{
    $out = [];
    $gross      = (int) ($b['gross'] ?? 0);
    $deductions = (int) ($b['employee_deductions'] ?? 0);
    $withheld   = (int) ($b['advance_tax_withheld'] ?? $b['advance_tax'] ?? 0);
    $net        = (int) ($b['net'] ?? 0);

    if ($gross - $deductions - $withheld !== $net) {
        $out[] = sprintf(
            'hrubá %d − srážky %d − daň %d = %d, ale net = %d',
            $gross, $deductions, $withheld, $gross - $deductions - $withheld, $net
        );
    }

    $healthTotal = (int) ($b['health_total'] ?? 0);
    if ($healthTotal > 0) {
        $parts = (int) ($b['employee_health'] ?? 0)
            + (int) ($b['health_min_topup'] ?? 0)
            + (int) ($b['employer_health'] ?? 0);
        if ($parts !== $healthTotal) {
            $out[] = sprintf('složky ZP dají %d, health_total = %d', $parts, $healthTotal);
        }
    }

    // Úhrn ZP musí být 13,5 % z vyměřovacího základu zaokrouhlených JEDNOU (sazba je
    // od roku 1993 neměnná, proto konstanta). Vyšší hodnota = složky se zaokrouhlily
    // nahoru každá zvlášť; takový snapshot je o korunu vedle proti odvodu na ZP.
    $assessmentBase = (int) ($b['assessment_base'] ?? 0);
    if ($assessmentBase > 0 && $healthTotal > 0) {
        $lawful = (int) ceil(round($assessmentBase * 0.135, 2));
        if ($healthTotal !== $lawful) {
            $out[] = sprintf(
                'health_total %d ≠ 13,5 %% z %d = %d (%+d Kč)',
                $healthTotal, $assessmentBase, $lawful, $healthTotal - $lawful
            );
        }
    }

    $creditTotal = (int) ($b['credit_total'] ?? 0);
    $creditParts = (int) ($b['credit_taxpayer'] ?? 0) + (int) ($b['credit_children'] ?? 0);
    if ($creditTotal !== $creditParts) {
        $out[] = sprintf('slevy %d + %d ≠ credit_total %d',
            (int) ($b['credit_taxpayer'] ?? 0), (int) ($b['credit_children'] ?? 0), $creditTotal);
    }

    return $out;
}

$supplierId = (int) (argValue($argv, 'supplier') ?? 0);
$employeeId = argValue($argv, 'employee');
$year       = argValue($argv, 'year');
$apply      = in_array('--apply', $argv, true);

if ($supplierId <= 0) {
    fwrite(STDERR, "Chybí --supplier=<id>.\nPoužití: php api/bin/repair-payroll-record-columns.php --supplier=<id> [--employee=<id>] [--year=RRRR] [--apply]\n");
    exit(2);
}
if ($year !== null && preg_match('/^\d{4}$/', $year) !== 1) {
    fwrite(STDERR, "Neplatný --year={$year} — očekává se RRRR.\n");
    exit(2);
}

$container = Bootstrap::buildApp()->getContainer();
$pdo       = $container->get(Connection::class)->pdo();

$nameStmt = $pdo->prepare('SELECT company_name FROM supplier WHERE id = ?');
$nameStmt->execute([$supplierId]);
$supplierName = $nameStmt->fetchColumn();
if ($supplierName === false) {
    fwrite(STDERR, "Firma #{$supplierId} neexistuje.\n");
    exit(2);
}

$sql = 'SELECT r.id, r.employee_id, r.year, r.month, r.gross, r.breakdown,
               r.tax_credit_taxpayer, r.tax_credit_children, r.advance_tax_final, r.net_final,
               e.full_name
          FROM payroll_monthly_records r
          JOIN payroll_employees e ON e.id = r.employee_id
         WHERE r.supplier_id = :sid';
$params = ['sid' => $supplierId];
if ($employeeId !== null) {
    $sql .= ' AND r.employee_id = :eid';
    $params['eid'] = (int) $employeeId;
}
if ($year !== null) {
    $sql .= ' AND r.year = :year';
    $params['year'] = (int) $year;
}
$sql .= ' ORDER BY r.employee_id, r.year, r.month';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$prefix = $apply ? '' : '[DRY-RUN] ';
$scope  = $year !== null ? "rok {$year}" : 'celá historie';
echo "{$prefix}Srovnání sloupců payroll_monthly_records dle breakdown — firma #{$supplierId} {$supplierName}, {$scope}.\n";
echo "Deník ani breakdown se NEMĚNÍ — přepisují se jen tax_credit_taxpayer, tax_credit_children, advance_tax_final, net_final.\n";
echo 'Záznamů v rozsahu: ' . count($rows) . "\n\n";

if ($rows === []) {
    echo "Žádný záznam — není co srovnávat.\n";
    exit(0);
}

$report  = ['fixed' => 0, 'ok' => 0, 'unreadable' => 0, 'suspect' => 0];
$detail  = [];
$suspect = [];

$update = $pdo->prepare(
    'UPDATE payroll_monthly_records
        SET tax_credit_taxpayer = :taxpayer,
            tax_credit_children = :children,
            advance_tax_final   = :advance,
            net_final           = :net
      WHERE id = :id'
);

if ($apply) {
    $pdo->beginTransaction();
}

try {
    foreach ($rows as $r) {
        $label = sprintf('%04d-%02d  #%d %s', (int) $r['year'], (int) $r['month'], (int) $r['employee_id'], (string) $r['full_name']);

        $breakdown = json_decode((string) $r['breakdown'], true);
        if (!is_array($breakdown) || !isset($breakdown['gross'])) {
            $report['unreadable']++;
            $detail[] = sprintf('  %s  SKIP  breakdown nelze přečíst — sloupce se nechávají být', $label);
            continue;
        }

        $want = columnsFromBreakdown($breakdown);
        $have = [
            'tax_credit_taxpayer' => (int) $r['tax_credit_taxpayer'],
            'tax_credit_children' => (int) $r['tax_credit_children'],
            'advance_tax_final'   => (int) $r['advance_tax_final'],
            'net_final'           => (int) $r['net_final'],
        ];

        $warnings = breakdownWarnings($breakdown);
        if ($warnings !== []) {
            $report['suspect']++;
            $suspect[] = sprintf('  %s  %s', $label, implode(' | ', $warnings));
        }

        if ($want === $have) {
            $report['ok']++;
            continue;
        }

        $changes = [];
        foreach ($want as $column => $value) {
            if ($have[$column] !== $value) {
                $changes[] = sprintf('%s %d→%d', $column, $have[$column], $value);
            }
        }

        if ($apply) {
            $update->execute([
                'taxpayer' => $want['tax_credit_taxpayer'],
                'children' => $want['tax_credit_children'],
                'advance'  => $want['advance_tax_final'],
                'net'      => $want['net_final'],
                'id'       => (int) $r['id'],
            ]);
        }

        $report['fixed']++;
        $detail[] = sprintf('  %s  FIX   %s', $label, implode(', ', $changes));
    }

    if ($apply) {
        $pdo->commit();
    }
} catch (\Throwable $e) {
    if ($apply && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "\nCHYBA — nic nezapsáno (rollback): " . $e->getMessage() . "\n");
    exit(1);
}

if ($detail !== []) {
    echo "═══ ZÁZNAMY ════════════════════════════════════════════════════\n";
    foreach ($detail as $line) {
        echo $line . "\n";
    }
}
if ($suspect !== []) {
    echo "═══ VAROVÁNÍ — rozpad si protiřečí sám ═════════════════════════\n";
    echo "  (sloupce se srovnaly dle breakdown, ale ten samotný nesedí — ověř proti deníku)\n";
    foreach ($suspect as $line) {
        echo $line . "\n";
    }
}
echo "═══ REPORT ═════════════════════════════════════════════════════\n";
printf("  záznamů:          %d\n", count($rows));
printf("  srovnáno:         %d\n", $report['fixed']);
printf("  už sedělo:        %d\n", $report['ok']);
printf("  nečitelný JSON:   %d\n", $report['unreadable']);
printf("  sporný rozpad:    %d\n", $report['suspect']);
echo "═══════════════════════════════════════════════════════════════\n";

if (!$apply) {
    echo "\n(dry-run — nic nebylo zapsáno; pro ostrý běh přidej --apply)\n";
} elseif ($report['fixed'] > 0) {
    echo "\nHotovo — mzdový list (Účetnictví → Sestavy → Mzdový list) už ukazuje totéž co rozpad a deník.\n";
}

exit(0);
