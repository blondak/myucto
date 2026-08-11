<?php

declare(strict_types=1);

/**
 * Dohledání pohybů, které starý GPC import tiše zahodil jako duplicity (BUG 0).
 *
 * Do opravy neplnil GPC parser `bank_ref`, takže identita pohybu se skládala z VS, KS,
 * SS, protiúčtu, kódu banky, názvu a popisu. Dvě legitimní platby téže částky, dne, VS
 * a popisu (opakované mikroplatby, poplatky, karetní pohyby bez protiúčtu) dostaly
 * identický otisk a druhá i další se do evidence nikdy nedostaly — bez chyby a bez
 * hlášení. Pozná se to až rozvahou: hlavička výpisu (počáteční zůstatek + kredit −
 * debet) přestane sedět na součet transakcí.
 *
 * Skript je READ-ONLY. Znovu rozparsuje originální bajty uložené v
 * `bank_statements.file_content` (GPC výpisy je mají) a porovná počet 075 řádků proti
 * pohybům v databázi. U výpisů, kde počty nesedí, vypíše konkrétní chybějící pohyby.
 *
 * NÁPRAVA: chybějící pohyby se doplní opětovným nahráním TÝCHŽ výpisů přes běžný import
 * (Banka → Nahrát výpis). Po opravě už import chybějící pohyby založí a ty existující
 * podruhé nezaloží (drží je otisk z náhradní identity). Statement-level dedupe podle
 * `file_hash` ale nahrání téhož SOUBORU odmítne — proto se pouští buď výpis za delší
 * období, který se s dotčeným překrývá, nebo se dotčený výpis nejdřív smaže. Skript
 * sám nic nemění.
 *
 * Použití:
 *   php api/bin/audit-gpc-lost-transactions.php                 # všechny tenanty
 *   php api/bin/audit-gpc-lost-transactions.php --supplier=3    # jen jeden tenant
 *   php api/bin/audit-gpc-lost-transactions.php --verbose       # vypíše i chybějící řádky
 */

require __DIR__ . '/../vendor/autoload.php';

$verbose = in_array('--verbose', $argv, true);
$supplierId = null;
foreach ($argv as $arg) {
    if (preg_match('/^--supplier=(\d+)$/', $arg, $m)) {
        $supplierId = (int) $m[1];
    }
}

$container = \MyInvoice\Bootstrap::buildApp()->getContainer();
$pdo = $container->get(\MyInvoice\Infrastructure\Database\Connection::class)->pdo();
$parser = new \MyInvoice\Service\Bank\GpcParser();

$sql = "SELECT id, supplier_id, file_name, account_number, statement_number, statement_date, file_content
          FROM bank_statements
         WHERE source = 'gpc' AND file_content IS NOT NULL";
$params = [];
if ($supplierId !== null) {
    $sql .= ' AND supplier_id = ?';
    $params[] = $supplierId;
}
$sql .= ' ORDER BY supplier_id, statement_date, id';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$txStmt = $pdo->prepare(
    'SELECT posted_at, amount, variable_symbol, constant_symbol, specific_symbol,
            counterparty_account, counterparty_name, description
       FROM bank_transactions WHERE statement_id = ?'
);

/** Klíč pohybu bez bankovní reference — přesně to, co starý otisk považoval za identitu. */
$key = static fn (array $t): string => implode('|', [
    (string) ($t['posted_at'] ?? ''),
    number_format((float) ($t['amount'] ?? 0), 2, '.', ''),
    trim((string) ($t['variable_symbol'] ?? '')),
    trim((string) ($t['constant_symbol'] ?? '')),
    trim((string) ($t['specific_symbol'] ?? '')),
    trim((string) ($t['counterparty_account'] ?? '')),
    mb_strtoupper(trim((string) ($t['counterparty_name'] ?? '')), 'UTF-8'),
    mb_strtoupper(trim((string) ($t['description'] ?? '')), 'UTF-8'),
]);

$checked = 0;
$affected = 0;
$lostTotal = 0;
$unparsable = 0;

foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $s) {
    $checked++;
    try {
        $parsed = $parser->parse((string) $s['file_content']);
    } catch (\Throwable $e) {
        $unparsable++;
        echo sprintf("  ? výpis #%d (%s): soubor nelze rozparsovat — %s\n", $s['id'], $s['file_name'], $e->getMessage());
        continue;
    }

    $txStmt->execute([(int) $s['id']]);
    $stored = [];
    foreach ($txStmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
        $stored[$key($row)] = ($stored[$key($row)] ?? 0) + 1;
    }

    $missing = [];
    foreach ($parsed['transactions'] as $t) {
        $k = $key($t);
        if (($stored[$k] ?? 0) > 0) {
            $stored[$k]--;
            continue;
        }
        $missing[] = $t;
    }
    if ($missing === []) {
        continue;
    }

    $affected++;
    $lostTotal += count($missing);
    echo sprintf(
        "  ✗ výpis #%d tenant=%s  %s  č. %s  (%s): v souboru %d pohybů, v evidenci %d → chybí %d\n",
        $s['id'],
        $s['supplier_id'] ?? '-',
        $s['statement_date'],
        $s['statement_number'],
        $s['file_name'],
        count($parsed['transactions']),
        count($parsed['transactions']) - count($missing),
        count($missing),
    );
    if ($verbose) {
        foreach ($missing as $t) {
            echo sprintf(
                "        %s  %12.2f  VS=%-10s  ID pohybu=%-14s  %s\n",
                $t['posted_at'] ?? '?',
                (float) ($t['amount'] ?? 0),
                (string) ($t['variable_symbol'] ?? '-'),
                (string) ($t['bank_ref'] ?? '-'),
                (string) ($t['description'] ?? ''),
            );
        }
    }
}

echo "\n";
echo "Zkontrolováno GPC výpisů: {$checked}\n";
echo "Výpisů s chybějícími pohyby: {$affected}\n";
echo "Celkem chybějících pohybů:  {$lostTotal}\n";
if ($unparsable > 0) {
    echo "Nerozparsovatelných souborů: {$unparsable}\n";
}
if ($affected > 0 && !$verbose) {
    echo "\nSpusť s --verbose pro výpis konkrétních chybějících pohybů.\n";
}
exit($affected > 0 ? 1 : 0);
