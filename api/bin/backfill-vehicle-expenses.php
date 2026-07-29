<?php

declare(strict_types=1);

/**
 * Back-fill nákladových analytik auta (PHM / servis vozidel) — migrace 1127.
 *
 * PROČ: účtovací cesta (PostingService::purchaseExpenseWeights) čte VÝHRADNĚ uložené
 * sloupce `purchase_invoice_items.expense_kind` / `expense_account_code`. Když jsou
 * prázdné — a u většiny historických řádků jsou — spadne celý náklad tiše na default
 * 518 (Ostatní služby). Pravidla klasifikace se přitom v účtování nikdy nevolala, takže
 * i 100% jisté „PHM AXIGON → 501" zůstalo bez efektu. Reálný dopad: prémiová nafta na 518.
 *
 * CO DĚLÁ: pro zvolený rok dohledá přijaté faktury, nechá {@see ExpenseClassificationService}
 * navrhnout klasifikaci, JISTÉ návrhy (confidence ≥ 0.9) uloží na položky a doklad
 * PŘEÚČTUJE. Zápis se přepisuje in-place (PostingService pro tutéž dvojici source_type +
 * source_id smaže staré řádky a vloží nové) — žádné storno, žádný duplicitní doklad.
 *
 * Položky rozpoznané jako palivo ({@see FuelKeywords}) se přepisují i tehdy, když už účet
 * mají — když je řádek v knize tankování, je to PHM. Ostatní řádky s ručně nastaveným
 * účtem zůstávají nedotčené (ruční volba účetní > automat).
 *
 *   NIC NEMĚNÍ NASLEPO. Bez argumentu je DRY-RUN — vypíše doklad po dokladu, co by se
 *   změnilo (starý → nový účet, částky) a jaký zápis by se přepsal. Teprve `--apply` mění.
 *
 * Zavřená a zamčená období se PŘESKAKUJÍ (přeúčtování by v nich stejně selhalo na
 * period_not_open / date_locked) — vypíše se to jako varování, ať je vidět, co zbylo.
 *
 * Použití:
 *   php api/bin/backfill-vehicle-expenses.php                          # dry-run, rok 2026, supplier 1
 *   php api/bin/backfill-vehicle-expenses.php --year=2026 --supplier=1
 *   php api/bin/backfill-vehicle-expenses.php --apply                  # ostrý běh
 */

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\DocumentAutoPoster;
use MyInvoice\Service\Accounting\Expense\ExpenseAutoClassifier;
use MyInvoice\Service\Accounting\Expense\ExpenseClassificationService;

$opts = getopt('', ['apply', 'all', 'year::', 'supplier::']);
$apply = array_key_exists('apply', $opts);
// Default = JEN položky mířící na analytiky auta (PHM / servis vozidel). Ostatní jisté
// návrhy (Vodafone → 518, SaaS → 518…) se sice taky nabízejí, ale 518 je zároveň default
// účet, takže by přeúčtování nic nezměnilo — jen by to zbytečně přepsalo desítky zápisů.
// --all je pustí taky, když se někdo rozhodne doplnit klasifikaci plošně.
$onlyVehicle = !array_key_exists('all', $opts);
$year = (int) ($opts['year'] ?? 2026);
$supplierId = (int) ($opts['supplier'] ?? 1);

$container = Bootstrap::buildApp()->getContainer();
$db = $container->get(Connection::class);
$suggestions = $container->get(ExpenseClassificationService::class);
$autoClassifier = $container->get(ExpenseAutoClassifier::class);
$autoPoster = $container->get(DocumentAutoPoster::class);
$pdo = $db->pdo();

printf(
    "%s — rok %d, supplier %d%s\n\n",
    $apply ? 'OSTRÝ BĚH' : 'DRY-RUN (nic se nemění)',
    $year,
    $supplierId,
    $apply ? '' : ' — spusť s --apply pro provedení',
);

// Analytiky firmy: bez nich nemá back-fill co nastavovat.
$acc = $pdo->prepare(
    'SELECT fuel_account_code, vehicle_repair_account_code
       FROM accounting_supplier_settings WHERE supplier_id = ?'
);
$acc->execute([$supplierId]);
$accounts = $acc->fetch(PDO::FETCH_ASSOC) ?: [];
printf(
    "Analytiky firmy:  PHM = %s   servis vozidel = %s\n\n",
    $accounts['fuel_account_code'] ?? '(neurčeno)',
    $accounts['vehicle_repair_account_code'] ?? '(neurčeno)',
);
if (($accounts['fuel_account_code'] ?? null) === null && ($accounts['vehicle_repair_account_code'] ?? null) === null) {
    fwrite(STDERR, "VAROVÁNÍ: firma nemá nastavenou ani jednu analytiku — spusť nejdřív migraci 1127.\n");
}

// Přijaté faktury roku, které nejsou koncept/storno. Rozhoduje datum plnění (fallback vystavení).
$stmt = $pdo->prepare(
    "SELECT pi.id, pi.vendor_invoice_number, pi.status,
            COALESCE(pi.tax_date, pi.issue_date) AS doc_date,
            c.company_name AS vendor_name,
            (SELECT je.id FROM journal_entries je
              WHERE je.supplier_id = pi.supplier_id AND je.source_type = 'purchase_invoice'
                AND je.source_id = pi.id AND je.reversed_by IS NULL
              ORDER BY je.id DESC LIMIT 1) AS entry_id,
            (SELECT p.status FROM accounting_periods p
              WHERE p.supplier_id = pi.supplier_id
                AND COALESCE(pi.tax_date, pi.issue_date) BETWEEN p.starts_on AND p.ends_on
              LIMIT 1) AS period_status
       FROM purchase_invoices pi
       LEFT JOIN clients c ON c.id = pi.vendor_id AND c.supplier_id = pi.supplier_id
      WHERE pi.supplier_id = ?
        AND YEAR(COALESCE(pi.tax_date, pi.issue_date)) = ?
        AND pi.status NOT IN ('draft', 'cancelled')
      ORDER BY COALESCE(pi.tax_date, pi.issue_date), pi.id"
);
$stmt->execute([$supplierId, $year]);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$lockedUntil = (function () use ($pdo, $supplierId): ?string {
    $s = $pdo->prepare('SELECT locked_until FROM accounting_supplier_settings WHERE supplier_id = ?');
    $s->execute([$supplierId]);
    $v = $s->fetchColumn();
    return $v === false || $v === null ? null : (string) $v;
})();

$totalChanges = 0;
$touchedInvoices = 0;
$reposted = 0;
$skipped = [];

foreach ($invoices as $inv) {
    $invoiceId = (int) $inv['id'];
    $docDate = (string) $inv['doc_date'];

    // Co by se změnilo — spočítat i pro přeskočené doklady, ať je vidět, o co přicházíme.
    $planned = planChanges($suggestions, $autoClassifier, $supplierId, $invoiceId, $pdo);
    if ($onlyVehicle) {
        $vehicleAccounts = array_filter([
            $accounts['fuel_account_code'] ?? null,
            $accounts['vehicle_repair_account_code'] ?? null,
        ]);
        $planned = array_values(array_filter(
            $planned,
            static fn (array $ch): bool => in_array($ch['to_account'], $vehicleAccounts, true),
        ));
    }
    if ($planned === []) {
        continue;
    }

    $periodStatus = $inv['period_status'] !== null ? (string) $inv['period_status'] : null;
    $dateLocked = $lockedUntil !== null && $docDate <= $lockedUntil;
    $blocked = null;
    if ($periodStatus !== null && $periodStatus !== 'open') {
        $blocked = 'období „' . $periodStatus . '"';
    } elseif ($dateLocked) {
        $blocked = 'uzamčeno do ' . $lockedUntil;
    }

    $touchedInvoices++;
    $totalChanges += count($planned);

    printf(
        "%s  %s  (%s, %s)%s\n",
        $docDate,
        str_pad((string) ($inv['vendor_invoice_number'] ?? ('#' . $invoiceId)), 18),
        $inv['vendor_name'] ?? '?',
        $inv['entry_id'] !== null ? 'zápis #' . $inv['entry_id'] : 'nezaúčtováno',
        $blocked !== null ? '  ← PŘESKOČENO: ' . $blocked : '',
    );
    foreach ($planned as $ch) {
        printf(
            "      %-42s  %s → %s   %s\n",
            mb_strimwidth((string) $ch['description'], 0, 42, '…'),
            str_pad((string) ($ch['from_account'] ?? $ch['from_kind'] ?? '—'), 8),
            str_pad((string) ($ch['to_account'] ?? $ch['to_kind']), 8),
            $ch['reason'],
        );
    }

    if ($blocked !== null) {
        $skipped[] = ['invoice' => $inv['vendor_invoice_number'] ?? ('#' . $invoiceId), 'why' => $blocked];
        echo "\n";
        continue;
    }

    if ($apply) {
        $fuelItems = $autoClassifier->fuelItemIds($supplierId, $invoiceId);
        $limit = $onlyVehicle
            ? array_values(array_filter([
                $accounts['fuel_account_code'] ?? null,
                $accounts['vehicle_repair_account_code'] ?? null,
            ]))
            : null;
        $autoClassifier->applyToInvoice($supplierId, $invoiceId, $fuelItems, null, $limit);

        if ($inv['entry_id'] !== null) {
            try {
                $entryId = $autoPoster->post($supplierId, 'purchase_invoice', $invoiceId, [
                    'entry_date' => $docDate,
                ]);
                $reposted++;
                printf("      → zápis #%d přepsán\n", $entryId);
            } catch (\Throwable $e) {
                fwrite(STDERR, sprintf("      ! přeúčtování selhalo: %s\n", $e->getMessage()));
            }
        }
    }
    echo "\n";
}

printf(
    "\nSouhrn: %d dokladů, %d položek k překlasifikování%s\n",
    $touchedInvoices,
    $totalChanges,
    $apply ? sprintf(', %d zápisů přepsáno', $reposted) : '',
);
if ($skipped !== []) {
    printf("Přeskočeno kvůli uzavřenému/zamčenému období: %d\n", count($skipped));
    foreach ($skipped as $s) {
        printf("  - %s (%s)\n", $s['invoice'], $s['why']);
    }
}
if (!$apply && $totalChanges > 0) {
    echo "\nNic se nezměnilo. Pro provedení spusť týž příkaz s --apply.\n";
}

/**
 * Co by se na dokladu změnilo — bez zápisu. Zrcadlí rozhodovací logiku
 * {@see ExpenseAutoClassifier::applyToInvoice()}, aby dry-run neslíbil něco jiného,
 * než ostrý běh udělá.
 *
 * @return list<array<string,mixed>>
 */
function planChanges(
    ExpenseClassificationService $suggestions,
    ExpenseAutoClassifier $autoClassifier,
    int $supplierId,
    int $invoiceId,
    PDO $pdo,
): array {
    $suggested = $suggestions->suggestForInvoice($supplierId, $invoiceId);
    if ($suggested === []) {
        return [];
    }
    $force = array_flip($autoClassifier->fuelItemIds($supplierId, $invoiceId));

    $stmt = $pdo->prepare(
        'SELECT id, description, expense_kind, expense_account_code
           FROM purchase_invoice_items WHERE purchase_invoice_id = ?'
    );
    $stmt->execute([$invoiceId]);
    $current = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $current[(int) $r['id']] = $r;
    }

    $out = [];
    foreach ($suggested as $itemId => $s) {
        if (!isset($current[$itemId]) || empty($s['auto'])) {
            continue;
        }
        $row = $current[$itemId];
        $fromAccount = $row['expense_account_code'] !== null ? (string) $row['expense_account_code'] : null;
        $fromKind = $row['expense_kind'] !== null ? (string) $row['expense_kind'] : null;
        if (!isset($force[$itemId]) && $fromAccount !== null) {
            continue;
        }
        $toAccount = $s['expense_account_code'] !== null ? (string) $s['expense_account_code'] : null;
        if ($fromKind === (string) $s['expense_kind'] && $fromAccount === $toAccount) {
            continue;
        }
        $out[] = [
            'description'  => $row['description'],
            'from_kind'    => $fromKind,
            'from_account' => $fromAccount,
            'to_kind'      => (string) $s['expense_kind'],
            'to_account'   => $toAccount,
            'reason'       => (string) ($s['reason'] ?? ''),
        ];
    }
    return $out;
}
