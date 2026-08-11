<?php

declare(strict_types=1);

/**
 * Podklad pro REKLASIFIKACI historie z plochého 221 na analytiky bankovních účtů.
 *
 * Od migrace 1318 má každý bankovní účet firmy vlastní analytiku 221xxx a NOVÉ pohyby
 * na ni padají samy ({@see \MyInvoice\Service\Accounting\Bank\BankAnalyticResolver}).
 * HISTORIE ale zůstává tam, kde vznikla — na syntetice 221. Tenhle skript rozpadne
 * zůstatek plochého 221 podle toho, ke kterému vlastnímu účtu jednotlivé pohyby patří,
 * a vypíše návrh reklasifikačního zápisu 221xxx / 221.
 *
 * PROČ JEN PODKLAD A NE AUTOMATICKÝ ZÁPIS (vědomé rozhodnutí):
 *   1. Uzavřená a schválená období se nepřeúčtovávají. Reklasifikace se účtuje K DATU
 *      v OTEVŘENÉM období — do už schváleného roku se zasahovat nesmí, a jeho rozvaha
 *      (řádek „Peněžní prostředky na účtech") se rozpadem uvnitř 221 stejně nemění.
 *   2. Reklasifikace je účetní rozhodnutí, ne datová oprava — patří pod schválení
 *      účetní, včetně volby data a textu dokladu.
 *   3. `journal_entries.source_type` je ENUM bez hodnoty pro reklasifikaci, takže by
 *      automatický zápis šel jen jako `manual` — a tím by ztratil idempotenci vázanou
 *      na (source_type, source_id). Druhý běh by historii přeúčtoval podruhé.
 * Návrh se proto zadává ručně jako interní doklad; skript k němu dodá čísla.
 *
 * ATRIBUCE: řádek na účtu 221 se přiřadí bankovnímu účtu jen tehdy, když jde o zápis
 * ze zdroje 'bank' a číslo účtu z výpisu se jednoznačně shoduje s vlastním účtem firmy.
 * Ruční / uzávěrkové / počáteční zápisy na 221 se nepřiřazují — vypíší se zvlášť jako
 * zbytek, který na syntetice legitimně zůstává, dokud o něm nerozhodne účetní.
 *
 * CIZÍ MĚNY: účty v jiné měně než CZK se do návrhu NEZAHRNUJÍ. Jejich reklasifikace
 * musí nést i cizoměnovou stopu (§ 4/12 ZoÚ — currency_code / fx_rate / amount_foreign),
 * kterou z korunových zůstatků zpětně poskládat nelze; vypíší se jen jako varování.
 *
 * Použití:
 *   php api/bin/reclassify-bank-analytics.php --supplier=1
 *   php api/bin/reclassify-bank-analytics.php --supplier=1 --to=2026-08-31
 *
 * Argumenty:
 *   --supplier=<id>      (povinné) firma (musí vést podvojné účetnictví)
 *   --to=<YYYY-MM-DD>    datum, ke kterému se zůstatek rozpadá (default: dnes)
 *   --from=<YYYY-MM-DD>  (volitelné) spodní mez — typicky začátek otevřeného období
 *
 * Skript je READ-ONLY: nezapisuje do databáze nic.
 */

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\SupplierBankAccountRepository;
use MyInvoice\Service\Accounting\Bank\BankAnalyticAssigner;

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

$supplierId = (int) (argValue($argv, 'supplier') ?? 0);
$to         = argValue($argv, 'to') ?? date('Y-m-d');
$from       = argValue($argv, 'from');

if ($supplierId <= 0) {
    fwrite(STDERR, "Chybí --supplier=<id>.\nPoužití: php api/bin/reclassify-bank-analytics.php --supplier=<id> [--from=YYYY-MM-DD] [--to=YYYY-MM-DD]\n");
    exit(2);
}
foreach (['to' => $to, 'from' => $from] as $name => $value) {
    if ($value !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
        fwrite(STDERR, "Neplatné --{$name}={$value} (očekává se YYYY-MM-DD).\n");
        exit(2);
    }
}

$container = Bootstrap::buildApp()->getContainer();
$pdo       = $container->get(Connection::class)->pdo();

$modeStmt = $pdo->prepare('SELECT accounting_mode FROM supplier WHERE id = ?');
$modeStmt->execute([$supplierId]);
$mode = $modeStmt->fetchColumn();
if ($mode === false) {
    fwrite(STDERR, "Firma #{$supplierId} neexistuje.\n");
    exit(2);
}
if ((string) $mode !== 'double_entry') {
    fwrite(STDERR, "Firma #{$supplierId} nevede podvojné účetnictví (accounting_mode={$mode}).\n");
    exit(2);
}

/** @var SupplierBankAccountRepository $bankAccounts */
$bankAccounts = $container->get(SupplierBankAccountRepository::class);

// Řádky na PLOCHÉM 221 do data — signed (MD +, D −) a s číslem účtu z výpisu, když
// zápis pochází z banky. Období se nefiltruje podle statusu: rozpad se počítá z celé
// historie, do jakého období se zápis nakonec zaúčtuje, rozhoduje účetní.
// Číslo vlastního účtu nese HLAVIČKA výpisu (bank_statements), ne transakce — stejně
// jako v BankPostingService::loadTx(), odkud resolver čte recipient_account.
$sql = "SELECT je.id                AS entry_id,
               je.entry_date,
               je.source_type,
               bs.account_number     AS recipient_account,
               bs.bank_code          AS recipient_bank,
               CASE WHEN jel.side = 'debit' THEN jel.amount ELSE -jel.amount END AS signed_amount
          FROM journal_entry_lines jel
          JOIN journal_entries je    ON je.id = jel.entry_id
          JOIN chart_of_accounts c   ON c.id = jel.account_id
     LEFT JOIN bank_transactions bt  ON je.source_type = 'bank' AND bt.id = je.source_id
     LEFT JOIN bank_statements bs    ON bs.id = bt.statement_id
         WHERE jel.supplier_id = ?
           AND c.account_code = '221'
           AND je.reversed_by IS NULL
           AND je.posted_at IS NOT NULL
           AND je.entry_date <= ?";
$params = [$supplierId, $to];
if ($from !== null) {
    $sql .= ' AND je.entry_date >= ?';
    $params[] = $from;
}
$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$perAccount = [];   // account id => ['account' => row, 'net' => float, 'lines' => int]
$unattributed = ['net' => 0.0, 'lines' => 0, 'by_source' => []];
$cache = [];

foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $amount = (float) $row['signed_amount'];
    $account = null;
    $number = trim((string) ($row['recipient_account'] ?? ''));
    if ((string) $row['source_type'] === 'bank' && $number !== '') {
        $bankCode = trim((string) ($row['recipient_bank'] ?? ''));
        $key = $number . '|' . $bankCode;
        if (!array_key_exists($key, $cache)) {
            $cache[$key] = $bankAccounts->matchCounterparty($supplierId, $number, $bankCode !== '' ? $bankCode : null);
        }
        $account = $cache[$key];
    }
    if ($account === null) {
        $unattributed['net'] += $amount;
        $unattributed['lines']++;
        $src = (string) $row['source_type'];
        $unattributed['by_source'][$src] = ($unattributed['by_source'][$src] ?? 0) + 1;
        continue;
    }
    $id = (int) $account['id'];
    $perAccount[$id] ??= ['account' => $account, 'net' => 0.0, 'lines' => 0];
    $perAccount[$id]['net'] += $amount;
    $perAccount[$id]['lines']++;
}

$scope = $from !== null ? "{$from} … {$to}" : "do {$to}";
echo "Rozpad plochého účtu 221 — firma #{$supplierId}, {$scope}\n";
echo "(READ-ONLY podklad — skript nic nezapisuje)\n\n";

if ($perAccount === [] && $unattributed['lines'] === 0) {
    echo "Na plochém 221 nejsou žádné zaúčtované řádky — reklasifikace není potřeba.\n";
    exit(0);
}

$proposal = [];
$foreignSkipped = [];
$noSuffix = [];

ksort($perAccount);
echo "── podle vlastního bankovního účtu ────────────────────────────\n";
foreach ($perAccount as $data) {
    $account  = $data['account'];
    $currency = strtoupper((string) ($account['currency'] ?? 'CZK'));
    $suffix   = $account['analytic_suffix'] ?? null;
    $label    = trim((string) ($account['label'] ?? '')) ?: ('účet #' . (int) $account['id']);
    $code     = BankAnalyticAssigner::isValidSuffix($suffix)
        ? BankAnalyticAssigner::codeFor((string) $suffix)
        : '(bez analytiky)';
    printf("  %-24s %-10s %-12s %12s Kč  (%d řádků)\n",
        mb_substr($label, 0, 24), $currency, $code, number_format($data['net'], 2, ',', ' '), $data['lines']);

    if (!BankAnalyticAssigner::isValidSuffix($suffix)) {
        $noSuffix[] = $label;
        continue;
    }
    if ($currency !== 'CZK' && $currency !== '') {
        $foreignSkipped[] = $label . ' (' . $currency . ')';
        continue;
    }
    if (abs($data['net']) >= 0.005) {
        $proposal[] = ['code' => BankAnalyticAssigner::codeFor((string) $suffix), 'net' => round($data['net'], 2), 'label' => $label];
    }
}

if ($unattributed['lines'] > 0) {
    echo "\n── nepřiřazeno (zůstává na 221) ───────────────────────────────\n";
    printf("  %-47s %12s Kč  (%d řádků)\n", 'ruční / uzávěrkové / neznámý účet výpisu',
        number_format($unattributed['net'], 2, ',', ' '), $unattributed['lines']);
    arsort($unattributed['by_source']);
    foreach ($unattributed['by_source'] as $src => $n) {
        printf("     %-24s %d×\n", $src, $n);
    }
}

if ($noSuffix !== []) {
    echo "\n⚠ Bez přidělené analytiky (spusť migraci 1318 nebo otevři nastavení bankovních účtů):\n";
    foreach ($noSuffix as $label) {
        echo "     - {$label}\n";
    }
}
if ($foreignSkipped !== []) {
    echo "\n⚠ Cizoměnové účty se do návrhu nezahrnují — reklasifikace musí nést kurz a částku\n";
    echo "  v cizí měně (§ 4/12 ZoÚ), což z korunového zůstatku zpětně poskládat nelze:\n";
    foreach ($foreignSkipped as $label) {
        echo "     - {$label}\n";
    }
}

if ($proposal === []) {
    echo "\nNávrh reklasifikačního zápisu: není co přeúčtovat.\n";
    exit(0);
}

echo "\n═══ NÁVRH REKLASIFIKAČNÍHO ZÁPISU k {$to} ═══════════════════════\n";
echo "  Text: Reklasifikace peněžních prostředků na analytiky bankovních účtů\n\n";
$total = 0.0;
foreach ($proposal as $line) {
    $side = $line['net'] > 0 ? 'MD' : 'D ';
    printf("  %s %-8s %14s Kč   %s\n", $side, $line['code'],
        number_format(abs($line['net']), 2, ',', ' '), $line['label']);
    $total += $line['net'];
}
$counterSide = $total > 0 ? 'D ' : 'MD';
printf("  %s %-8s %14s Kč   protistrana (syntetika)\n", $counterSide, '221', number_format(abs($total), 2, ',', ' '));

echo "\nZápis zadej ručně jako interní doklad v OTEVŘENÉM období — do uzavřených\n";
echo "a schválených období se nezasahuje (rozvahový řádek se rozpadem uvnitř 221 nemění).\n";

exit(0);
