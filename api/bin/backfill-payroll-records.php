<?php

declare(strict_types=1);

/**
 * Backfill měsíčních mzdových snapshotů pro MZDOVÝ LIST (§38j ZDP).
 *
 * Mzdový list ({@see \MyInvoice\Service\Accounting\Payroll\PayrollSheetService}) čte
 * VÝHRADNĚ z `payroll_monthly_records`. Ty se zapisují jen tehdy, když
 * {@see \MyInvoice\Service\Accounting\Payroll\PayrollPostingService::post()} dostane
 * `employee_id` — historické rekapitulace zaúčtované bez vazby na zaměstnance (a ručně
 * zaúčtované mzdy 2024–2026) proto nechají mzdový list prázdný, přestože deník je v pořádku.
 *
 * Tenhle skript vazbu doplní ZPĚTNĚ z deníku: pro každý zápis `source_type='manual'`
 * se `source_id` = RRRRMM dohledá hrubá mzda (MD 521/522), znovu se spočítá rozpad
 * {@see \MyInvoice\Service\Accounting\Payroll\PayrollCalculator} a uloží snapshot.
 * ZAÚČTOVÁNÍ SE NEMĚNÍ — skript do `journal_entries` ani `journal_entry_lines` nesahá.
 *
 * ── Kontrola proti deníku (proč se něco přeskočí) ────────────────────────────
 * Před zápisem se z přepočteného rozpadu sestaví kontace
 * ({@see PayrollCalculator::lines()}) a porovná s reálnými řádky zápisu. Když nesedí
 * na haléř, snapshot se NEZAPÍŠE (`ledger_mismatch`) — mzdový list by pak tvrdil něco
 * jiného než deník. Takový měsíc patří do ruky člověku: buď má jiné složky mzdy
 * (nemocenská, srážky, více zaměstnanců v jednom zápisu), nebo jiné sazby.
 *
 * ── --reconcile: korunové rozdíly v doplatku do min. VZ ─────────────────────
 * Doplatek do minimálního vyměřovacího základu vychází na PŮLKORUNU v každém roce
 * (2024: 2011,50 | 2025: 2200,50 | 2026: 2416,50), takže se u něj ručně účtované
 * mzdy rozcházejí o 1 Kč podle toho, kterým směrem účetní ten měsíc zaokrouhlila.
 * `--reconcile` takový rozdíl NEIGNORUJE — dorovná rozpad na deník: sociální a
 * zdravotní zůstanou zákonné (ceil), rozdíl se absorbuje do doplatku, protože právě
 * u něj je směr zaokrouhlení sporný. Dorovnat lze jen když hrubá mzda, pojistné
 * zaměstnavatele i záloha na daň sedí přesně a odvody se liší nejvýš o
 * {@see MAX_RECONCILE_DELTA} Kč; po úpravě se kontace znovu ověří proti deníku a
 * musí sednout na haléř, jinak se měsíc přeskočí. Mzdový list tak nikdy neukáže
 * jinou částku než deník.
 *
 * ── Párování zaměstnance ────────────────────────────────────────────────────
 * Zápis nese jen účet nákladu, ne osobu: MD 522 → `managing_partner`, MD 521 → `employee`.
 * Zaměstnanec se dohledá podle `taxpayer_type` v rámci firmy; je-li kandidátů víc,
 * měsíc se přeskočí (`ambiguous_employee`) a je potřeba `--employee=<id>`.
 *
 * ── Idempotence ─────────────────────────────────────────────────────────────
 * Zápis jde přes `upsert()` (unikát `uq_pmr_employee_period`), druhý běh nic neduplikuje.
 * Existující řádky se ve výchozím stavu NEPŘEPISUJÍ (`already_exists`) — na přepočet
 * slouží `--overwrite`.
 *
 * Použití:
 *   php api/bin/backfill-payroll-records.php --supplier=1                       # DRY-RUN
 *   php api/bin/backfill-payroll-records.php --supplier=1 --apply               # ostrý běh
 *   php api/bin/backfill-payroll-records.php --supplier=1 --from=2024-01 --to=2024-12
 *   php api/bin/backfill-payroll-records.php --supplier=1 --employee=1 --apply  # vynucené párování
 *   php api/bin/backfill-payroll-records.php --supplier=1 --apply --overwrite   # přepočet existujících
 *   php api/bin/backfill-payroll-records.php --supplier=1 --apply --reconcile   # + dorovnání korun dle deníku
 *
 * Argumenty:
 *   --supplier=<id>    (povinné) firma
 *   --employee=<id>    (volitelné) vynutí párování na konkrétního zaměstnance
 *   --from=<YYYY-MM>   (volitelné) jen měsíce od
 *   --to=<YYYY-MM>     (volitelné) jen měsíce do
 *   --apply            ostrý běh (bez něj DRY-RUN — nic nezapíše)
 *   --overwrite        přepsat i měsíce, které už snapshot mají
 *   --reconcile        dorovnat korunový rozdíl v doplatku do min. VZ dle deníku
 */

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PayrollEmployeeRepository;
use MyInvoice\Repository\PayrollMonthlyRecordRepository;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Service\Accounting\Payroll\PayrollCalculator;

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

/** YYYY-MM → RRRRMM; null zůstává null. */
function periodBound(?string $value, string $flag): ?int
{
    if ($value === null) {
        return null;
    }
    if (preg_match('/^(\d{4})-(\d{2})$/', $value, $m) !== 1 || (int) $m[2] < 1 || (int) $m[2] > 12) {
        fwrite(STDERR, "Neplatný --{$flag}={$value} — očekává se YYYY-MM.\n");
        exit(2);
    }
    return (int) $m[1] * 100 + (int) $m[2];
}

/**
 * Nejvyšší rozdíl odvodů (Kč), který --reconcile ještě dorovná. Sporné je jen
 * zaokrouhlení doplatku do min. VZ, a to je vždy otázka jedné koruny; víc už
 * znamená jinou složku mzdy, ne zaokrouhlení, a patří člověku.
 */
const MAX_RECONCILE_DELTA = 1;

/**
 * Úhrny zápisu podle účtů — nezávislé na pořadí řádků.
 *
 * @return array{gross:int, employer_total:int, advance_tax:int, employee_deductions:int}
 */
function ledgerAggregates(array $actual, string $expenseCode): array
{
    $sum = static function (array $lines, string $code, string $side): int {
        $total = 0;
        foreach ($lines as $l) {
            if ($l['account_code'] === $code && $l['side'] === $side) {
                $total += (int) round(((float) $l['amount']) * 100);
            }
        }
        return intdiv($total, 100);
    };

    // Na 336 visí pojistné obou stran dohromady; zaměstnancovo = úhrn − zaměstnavatelovo.
    $employerTotal = $sum($actual, '524', 'debit');

    return [
        'gross'               => $sum($actual, $expenseCode, 'debit'),
        'employer_total'      => $employerTotal,
        'advance_tax'         => $sum($actual, '342', 'credit'),
        'employee_deductions' => $sum($actual, '336', 'credit') - $employerTotal,
    ];
}

/**
 * Kontace jako porovnatelný multiset "kód|strana|haléře".
 *
 * Nulové řádky se vynechávají: {@see PayrollCalculator::lines()} je nikdy nevytvoří,
 * kdežto v deníku se u historických zápisů objevit můžou — bez téhle symetrie by
 * takový měsíc nešel spárovat, přestože všechny nenulové částky sedí.
 */
function lineFingerprint(array $lines): array
{
    $out = [];
    foreach ($lines as $l) {
        $halere = (int) round(((float) $l['amount']) * 100);
        if ($halere === 0) {
            continue;
        }
        $out[] = sprintf('%s|%s|%d', $l['account_code'], $l['side'], $halere);
    }
    sort($out);
    return $out;
}

$supplierId = (int) (argValue($argv, 'supplier') ?? 0);
$forcedEmp  = argValue($argv, 'employee');
$fromPeriod = periodBound(argValue($argv, 'from'), 'from');
$toPeriod   = periodBound(argValue($argv, 'to'), 'to');
$apply      = in_array('--apply', $argv, true);
$overwrite  = in_array('--overwrite', $argv, true);
$reconcile  = in_array('--reconcile', $argv, true);

if ($supplierId <= 0) {
    fwrite(STDERR, "Chybí --supplier=<id>.\nPoužití: php api/bin/backfill-payroll-records.php --supplier=<id> [--employee=<id>] [--from=YYYY-MM] [--to=YYYY-MM] [--apply] [--overwrite]\n");
    exit(2);
}

$container = Bootstrap::buildApp()->getContainer();
$pdo       = $container->get(Connection::class)->pdo();

/** @var PayrollEmployeeRepository $employeeRepo */
$employeeRepo = $container->get(PayrollEmployeeRepository::class);
/** @var PayrollMonthlyRecordRepository $recordRepo */
$recordRepo = $container->get(PayrollMonthlyRecordRepository::class);
/** @var TaxConstantsRepository $constantsRepo */
$constantsRepo = $container->get(TaxConstantsRepository::class);

$nameStmt = $pdo->prepare('SELECT company_name FROM supplier WHERE id = ?');
$nameStmt->execute([$supplierId]);
$supplierName = $nameStmt->fetchColumn();
if ($supplierName === false) {
    fwrite(STDERR, "Firma #{$supplierId} neexistuje.\n");
    exit(2);
}

// ── Zaměstnanci firmy (i neaktivní — backfill je historie) ────────────────────
$employees = $employeeRepo->listForTenant($supplierId);
if ($employees === []) {
    fwrite(STDERR, "Firma #{$supplierId} nemá v `payroll_employees` žádného zaměstnance — není na co navazovat.\n");
    exit(2);
}

$forcedEmployee = null;
if ($forcedEmp !== null) {
    $forcedEmployee = $employeeRepo->find($supplierId, (int) $forcedEmp);
    if ($forcedEmployee === null) {
        fwrite(STDERR, "Zaměstnanec #{$forcedEmp} u firmy #{$supplierId} neexistuje.\n");
        exit(2);
    }
}

/** @var array<string,list<array<string,mixed>>> $byType */
$byType = [];
foreach ($employees as $e) {
    $byType[(string) $e['taxpayer_type']][] = $e;
}

// ── Mzdové zápisy v deníku ────────────────────────────────────────────────────
// Rekapitulace pozná podle tvaru: source_type='manual', source_id=RRRRMM (měsíc 1–12)
// a MD na nákladovém účtu mzdy. Popis se záměrně nepoužívá — je jen textový.
$sql = "SELECT je.id, je.source_id, je.entry_date, coa.account_code AS expense_code, l.amount AS gross
          FROM journal_entries je
          JOIN journal_entry_lines l ON l.entry_id = je.id AND l.side = 'debit'
          JOIN chart_of_accounts coa ON coa.id = l.account_id
         WHERE je.supplier_id = :sid
           AND je.source_type = 'manual'
           AND coa.account_code IN ('521', '522')
           AND je.source_id BETWEEN 201801 AND 210012
           AND MOD(je.source_id, 100) BETWEEN 1 AND 12";
$params = ['sid' => $supplierId];
if ($fromPeriod !== null) {
    $sql .= ' AND je.source_id >= :from';
    $params['from'] = $fromPeriod;
}
if ($toPeriod !== null) {
    $sql .= ' AND je.source_id <= :to';
    $params['to'] = $toPeriod;
}
$sql .= ' ORDER BY je.source_id ASC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/** @var array<int,list<array<string,mixed>>> $entries seskupené po zápisu */
$entries = [];
foreach ($rows as $r) {
    $entries[(int) $r['id']][] = $r;
}

$prefix = $apply ? '' : '[DRY-RUN] ';
$scope  = ($fromPeriod !== null || $toPeriod !== null)
    ? sprintf('%s – %s', $fromPeriod !== null ? (string) $fromPeriod : 'začátek', $toPeriod !== null ? (string) $toPeriod : 'konec')
    : 'celá historie';
echo "{$prefix}Backfill mzdových snapshotů (mzdový list §38j) — firma #{$supplierId} {$supplierName}, {$scope}.\n";
echo 'Zaměstnanců v evidenci: ' . count($employees)
    . ($forcedEmployee !== null ? sprintf(' | vynucené párování: #%d %s', $forcedEmployee['id'], $forcedEmployee['full_name']) : '')
    . "\n";
echo 'Mzdových zápisů v deníku: ' . count($entries) . "\n";
echo "Deník se NEMĚNÍ — zapisuje se jen do payroll_monthly_records.\n\n";

if ($entries === []) {
    echo "Nenalezen žádný zápis mzdové rekapitulace — není co doplnit.\n";
    exit(0);
}

$report = ['written' => 0, 'skipped' => 0, 'reconciled' => 0];
$reasons = [];
$detail  = [];
$constantsCache = [];

$skip = static function (int $sourceId, string $reason, string $note = '') use (&$report, &$reasons, &$detail): void {
    $report['skipped']++;
    $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
    $detail[] = sprintf('  %d  SKIP  %-20s %s', $sourceId, $reason, $note);
};

if ($apply) {
    $pdo->beginTransaction();
}

try {
    foreach ($entries as $entryId => $expenseLines) {
        $sourceId = (int) $expenseLines[0]['source_id'];
        $year     = intdiv($sourceId, 100);
        $month    = $sourceId % 100;

        if (count($expenseLines) > 1) {
            $skip($sourceId, 'multiple_expense_lines', 'zápis má víc mzdových nákladových řádků — rozpad na osoby nelze odvodit');
            continue;
        }

        $gross = (float) $expenseLines[0]['gross'];
        $type  = $expenseLines[0]['expense_code'] === '522'
            ? PayrollCalculator::TYPE_MANAGING_PARTNER
            : PayrollCalculator::TYPE_EMPLOYEE;

        // ── Zaměstnanec ───────────────────────────────────────────────────────
        if ($forcedEmployee !== null) {
            $employee = $forcedEmployee;
            if ((string) $employee['taxpayer_type'] !== $type) {
                $skip($sourceId, 'type_mismatch', sprintf(
                    'zápis je %s (MD %s), ale #%d je %s',
                    $type,
                    $expenseLines[0]['expense_code'],
                    $employee['id'],
                    $employee['taxpayer_type']
                ));
                continue;
            }
        } else {
            $candidates = $byType[$type] ?? [];
            if ($candidates === []) {
                $skip($sourceId, 'no_employee', "žádný zaměstnanec typu {$type}");
                continue;
            }
            if (count($candidates) > 1) {
                $skip($sourceId, 'ambiguous_employee', sprintf(
                    '%d zaměstnanců typu %s — použij --employee=<id>',
                    count($candidates),
                    $type
                ));
                continue;
            }
            $employee = $candidates[0];
        }

        $employeeId = (int) $employee['id'];

        // ── Už existující snapshot ────────────────────────────────────────────
        $existing = $recordRepo->listForYear($supplierId, $employeeId, $year);
        if (isset($existing[$month]) && !$overwrite) {
            $skip($sourceId, 'already_exists', 'snapshot už existuje — přepis přes --overwrite');
            continue;
        }

        // ── Rozpad ────────────────────────────────────────────────────────────
        try {
            $constants = $constantsCache[$year] ??= $constantsRepo->forExactYear($year);
        } catch (\OutOfRangeException $e) {
            $skip($sourceId, 'no_tax_constants', $e->getMessage());
            continue;
        }

        try {
            $breakdown = PayrollCalculator::compute($gross, $constants);
        } catch (\InvalidArgumentException $e) {
            $skip($sourceId, 'compute_failed', $e->getMessage());
            continue;
        }

        // ── Kontrola proti deníku ─────────────────────────────────────────────
        $actualStmt = $pdo->prepare(
            'SELECT coa.account_code, l.side, l.amount
               FROM journal_entry_lines l
               JOIN chart_of_accounts coa ON coa.id = l.account_id
              WHERE l.entry_id = ?'
        );
        $actualStmt->execute([$entryId]);
        $actual = $actualStmt->fetchAll(PDO::FETCH_ASSOC);

        $expectedFp = lineFingerprint(PayrollCalculator::lines($breakdown, $type));
        $actualFp   = lineFingerprint(array_map(
            static fn (array $r): array => [
                'account_code' => $r['account_code'],
                'side'         => $r['side'],
                'amount'       => $r['amount'],
            ],
            $actual
        ));

        $reconciled = false;
        if ($expectedFp !== $actualFp) {
            $led   = ledgerAggregates($actual, $expenseLines[0]['expense_code']);
            $delta = $led['employee_deductions'] - $breakdown['employee_deductions'];

            $onlyDeductionsDiffer = $led['gross'] === $breakdown['gross']
                && $led['employer_total'] === $breakdown['employer_total']
                && $led['advance_tax'] === $breakdown['advance_tax'];

            // Dorovnat lze JEN měsíc, kde doplatek do min. VZ vůbec vzniká (hrubá pod
            // minimální mzdou) a po úpravě zůstane kladný. Bez téhle podmínky by se
            // korunový rozdíl z jiné příčiny (překlep v pojistném) zapsal jako záporný
            // nebo vymyšlený doplatek — kontrola kontace by ho nechytila, protože
            // PayrollCalculator::lines() doplatek samostatným řádkem neúčtuje.
            $topupInPlay = $breakdown['health_min_topup'] > 0
                && $breakdown['health_min_topup'] + $delta > 0;

            if (!$reconcile || !$onlyDeductionsDiffer || !$topupInPlay || $delta === 0 || abs($delta) > MAX_RECONCILE_DELTA) {
                if (!$onlyDeductionsDiffer) {
                    $hint = sprintf(
                        'liší se i jiné složky než odvody (hrubá %d/%d, zaměstnavatel %d/%d, záloha %d/%d)',
                        $led['gross'], $breakdown['gross'],
                        $led['employer_total'], $breakdown['employer_total'],
                        $led['advance_tax'], $breakdown['advance_tax']
                    );
                } elseif ($delta === 0) {
                    $hint = 'úhrny sedí, ale kontace ne — jiná struktura řádků, ověř ručně';
                } else {
                    $why = match (true) {
                        !$reconcile             => ' — lze dorovnat přes --reconcile',
                        !$topupInPlay           => ' — netýká se doplatku do min. VZ, dorovnat nelze',
                        default                 => ' — nad limit dorovnání',
                    };
                    $hint = sprintf(
                        'odvody deník %d / přepočet %d (%+d Kč)%s',
                        $led['employee_deductions'],
                        $breakdown['employee_deductions'],
                        $delta,
                        $why
                    );
                }
                $skip($sourceId, 'ledger_mismatch', sprintf('zápis #%d — %s', $entryId, $hint));
                continue;
            }

            // Rozdíl absorbuje doplatek do min. VZ — jediná složka se sporným směrem
            // zaokrouhlení. Odvozené úhrny (13,5 % ZP) se posunou se stejným znaménkem.
            $breakdown['health_min_topup']    += $delta;
            $breakdown['employee_deductions'] += $delta;
            $breakdown['health_total']        += $delta;
            $breakdown['remittance_total']    += $delta;
            $breakdown['net']                 -= $delta;

            // Dorovnaný rozpad musí reprodukovat deník na haléř, jinak se neuloží.
            if (lineFingerprint(PayrollCalculator::lines($breakdown, $type)) !== $actualFp) {
                $skip($sourceId, 'reconcile_failed', sprintf(
                    'zápis #%d — dorovnání o %+d Kč stále nereprodukuje deník',
                    $entryId,
                    $delta
                ));
                continue;
            }
            $reconciled = true;
        }

        // ── Slevy + zápis ─────────────────────────────────────────────────────
        $credits = PayrollCalculator::monthlyCredits(
            $constants,
            (bool) $employee['tax_credit_taxpayer'],
            (int) $employee['child_count'],
        );
        // Rozpad výš je záměrně PŘED slevami — přesně tak je zaúčtovaný deník (jednatel
        // bez podepsaného prohlášení, §38k). Slevy se promítnou až do snapshotu, stejnou
        // logikou jako v PayrollCalculator::compute() (§38h odst. 4, ořez na nulu):
        // nelze zavolat compute() znovu s $credits, protože by se ztratilo dorovnání
        // doplatku do min. VZ podle deníku (--reconcile výš).
        $advanceTaxFinal = max(0, $breakdown['advance_tax'] - $credits['total']);
        $netFinal        = $breakdown['gross'] - $breakdown['employee_deductions'] - $advanceTaxFinal;

        if ($apply) {
            $recordRepo->upsert(
                $supplierId,
                $employeeId,
                $year,
                $month,
                $breakdown,
                $credits,
                $advanceTaxFinal,
                $netFinal,
                $entryId,
            );
        }

        $report['written']++;
        if ($reconciled) {
            $report['reconciled']++;
        }
        $detail[] = sprintf(
            '  %d  OK    %-20s hrubá %8s  odvody %6d  záloha %5d→%-5d  čistá %7d  (#%d %s)',
            $sourceId,
            $reconciled
                ? 'dorovnáno dle deníku'
                : (isset($existing[$month]) ? 'přepsáno' : 'nový'),
            number_format($breakdown['gross'], 0, ',', ' '),
            $breakdown['employee_deductions'],
            $breakdown['advance_tax'],
            $advanceTaxFinal,
            $netFinal,
            $employeeId,
            $employee['full_name'],
        );
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

echo "═══ MĚSÍCE ═════════════════════════════════════════════════════\n";
foreach ($detail as $line) {
    echo $line . "\n";
}
echo "═══ REPORT ═════════════════════════════════════════════════════\n";
printf("  zápisů v deníku: %d\n", count($entries));
printf("  zapsáno:         %d\n", $report['written']);
if ($report['reconciled'] > 0) {
    printf("    z toho dorovnáno dle deníku: %d (doplatek do min. VZ, ±1 Kč)\n", $report['reconciled']);
}
printf("  přeskočeno:      %d\n", $report['skipped']);
if ($reasons !== []) {
    echo "  ── přeskočeno dle důvodu ──\n";
    arsort($reasons);
    foreach ($reasons as $reason => $n) {
        printf("     %-24s %d×\n", $reason, $n);
    }
}
echo "═══════════════════════════════════════════════════════════════\n";

if (!$apply) {
    echo "\n(dry-run — nic nebylo zapsáno; pro ostrý běh přidej --apply)\n";
} elseif ($report['written'] > 0) {
    echo "\nHotovo — mzdový list (Účetnictví → Sestavy → Mzdový list) už má za doplněné měsíce data.\n";
}

exit(0);
