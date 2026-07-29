<?php

declare(strict_types=1);

/**
 * Automatizace / Uzávěrka 2026 — seed pravidel klasifikace nákladů (expense_classification_rules).
 *
 * Seeduje jen VENDOR-NEZÁVISLÁ pravidla (matchují popis dokladu). Pravidla vázaná na
 * konkrétního dodavatele jsou per-tenant a zakládají se v aplikaci, ne tímhle skriptem.
 *
 * Doplňuje sloupce `recurring_prepaid` (mig. 1102) a `target_account_code` (mig. 1095). Bez tohohle
 * seedu uzávěrka 2026 NEnavrhne odklad ročního předplatného na 381 a PHM/NULL služby zůstanou na
 * defaultu — motor běží naprázdno.
 *
 *   NIC NEAPLIKUJE NASLEPO. Bez argumentu je DRY-RUN — jen vypíše, co by vzniklo.
 *   Teprve `--apply` pravidla vloží (idempotentně: existující pravidlo téhož jména přeskočí).
 *

 * Použití:
 *   php api/bin/seed-rules-2026.php                  # dry-run (supplier 1)
 *   php api/bin/seed-rules-2026.php --apply           # vloží pravidla
 *   php api/bin/seed-rules-2026.php --apply --supplier=1
 */

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ExpenseClassificationRuleRepository;

$apply = in_array('--apply', $argv, true);
$supplierId = 1;
foreach ($argv as $a) {
    if (str_starts_with($a, '--supplier=')) {
        $supplierId = (int) substr($a, strlen('--supplier='));
    }
}

/**
 * @var list<array{name:string,vendor:?string,desc:?string,kind:string,account:?string,recurring:bool,note:string}>
 */
$rules = [
    // Pravidla jsou VENDOR-NEZÁVISLÁ: matchují popis dokladu, ne konkrétního dodavatele.
    // Dodavatelská pravidla („vše od firmy X → účet Y") jsou z podstaty per-tenant —
    // patří do aplikace (Nastavení → Klasifikace nákladů), ne do seedu v repozitáři.

    // ── PHM → material + 501 (PHM nikdy na 518/drobný majetek) ──
    ['name' => 'PHM — nafta → 501', 'vendor' => null, 'desc' => 'nafta',
        'kind' => 'material', 'account' => '501', 'recurring' => false,
        'note' => 'description „nafta" → material 501'],
    ['name' => 'PHM — benzin → 501', 'vendor' => null, 'desc' => 'benzin',
        'kind' => 'material', 'account' => '501', 'recurring' => false,
        'note' => 'description „benzin" → material 501'],
    ['name' => 'PHM — PHM → 501', 'vendor' => null, 'desc' => 'PHM',
        'kind' => 'material', 'account' => '501', 'recurring' => false,
        'note' => 'description „PHM" → material 501'],
    ['name' => 'PHM — palivo → 501', 'vendor' => null, 'desc' => 'palivo',
        'kind' => 'material', 'account' => '501', 'recurring' => false,
        'note' => 'description „palivo" → material 501'],

    // ── servis vozu → 511 (opravy a udržování), zúženo popisem ──
    ['name' => 'Servis vozu → 511', 'vendor' => null, 'desc' => 'servis',
        'kind' => 'service', 'account' => '511', 'recurring' => false,
        'note' => 'description „servis" → 511 (opravy a udržování)'],

    // ── pojistné → 548 (vyhl. 500/2002 F.5. Jiné provozní náklady, ne 518 Služby) ──
    ['name' => 'Pojistné → 548', 'vendor' => null, 'desc' => 'pojist',
        'kind' => 'service', 'account' => '548', 'recurring' => true,
        'note' => 'pojistné → 548; roční pojistné přes přelom roku se rozlišuje na 381'],

    // ── nedaňové osobní (§25 ZDP) → 528; vstupuje do ř.40 DPPO automaticky ──
    ['name' => 'Optika / dioptrické brýle — nedaňové osobní', 'vendor' => null, 'desc' => 'bryle',
        'kind' => 'service', 'account' => '528', 'recurring' => false,
        'note' => 'dioptrické brýle = osobní potřeba, nedaňové → 528'],
];

$container = Bootstrap::buildApp()->getContainer();
$pdo = $container->get(Connection::class)->pdo();
$repo = $container->get(ExpenseClassificationRuleRepository::class);

// Existující jména pravidel tenanta (idempotence) + existující kódy účtů (validace target).
$existing = [];
foreach ($repo->listForTenant($supplierId) as $r) {
    $existing[mb_strtolower((string) $r['name'])] = (int) $r['id'];
}
$accounts = [];
$aq = $pdo->prepare('SELECT account_code FROM chart_of_accounts WHERE supplier_id = ?');
$aq->execute([$supplierId]);
foreach ($aq->fetchAll(PDO::FETCH_COLUMN) as $code) {
    $accounts[(string) $code] = true;
}

printf("=== seed-rules-2026 · supplier=%d · %s ===\n\n", $supplierId, $apply ? 'APPLY' : 'DRY-RUN');

$inserted = 0;
$skipped = 0;
$warned = 0;
foreach ($rules as $r) {
    $flags = ($r['recurring'] ? ' recurring_prepaid' : '') . ($r['account'] === '528' ? ' NEDAŇOVÉ' : '');

    if (isset($existing[mb_strtolower($r['name'])])) {
        printf("SKIP (už existuje #%d): %s\n", $existing[mb_strtolower($r['name'])], $r['name']);
        $skipped++;
        continue;
    }
    if ($r['account'] !== null && !isset($accounts[$r['account']])) {
        printf("WARN účet %s není v osnově tenanta — přeskočeno: %s\n", $r['account'], $r['name']);
        $warned++;
        continue;
    }

    printf("+ %-42s → kind=%-9s účet=%s%s\n     %s\n",
        $r['name'], $r['kind'], $r['account'] ?? '(dle druhu)', $flags, $r['note']);

    if ($apply) {
        $id = $repo->insert($supplierId, [
            'name' => $r['name'],
            'vendor_name_contains' => $r['vendor'],
            'description_contains' => $r['desc'],
            'expense_kind' => $r['kind'],
            'target_account_code' => $r['account'],
            'recurring_prepaid' => $r['recurring'] ? 1 : 0,
            // Konkrétnější pravidlo (s popisem) má přednost před dodavatelem-only.
            'priority' => $r['desc'] !== null ? 50 : 100,
        ], null);
        printf("     → vloženo #%d\n", $id);
        $inserted++;
    }
}

printf("\n=== hotovo: %s · %d vloženo · %d přeskočeno (existuje) · %d warn (chybí účet) ===\n",
    $apply ? 'APPLY' : 'DRY-RUN (nic nevloženo)', $inserted, $skipped, $warned);
if (!$apply) {
    echo "Spusť s --apply pro vložení.\n";
}
