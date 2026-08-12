<?php

declare(strict_types=1);

/**
 * Backfill automatického zaúčtování BANKOVNÍCH TRANSAKCÍ (mini-epic AUTOMATIZACE, §7).
 *
 * Zaúčtuje historické spárované platby (221/311 | 321/221, guard předpisu H1) a — s
 * přepínačem --rules — navrhne opakované platby dle pravidel (VŽDY jen jako suggest;
 * auto režim se při dávce degraduje). S --auto se degradace vypne a rozhoduje
 * tenantova politika automatiky (auto_posting_policy). Volá týž engine jako import
 * ({@see \MyInvoice\Service\Accounting\Bank\BankPostingService::handleTransaction}),
 * žádná druhá logika (R9). Idempotentní: zápisy jsou vázané na ('bank', bt.id), druhý
 * běh nic neduplikuje.
 *
 * Historická data: jednoznačnou 1:1 vazbu zaplacené faktury na existující
 * nenavázanou platbu doplní bez vytvoření druhé platby. Pokud explicitní párování
 * chybí, legacy platbu dohledá jen při oboustranně jedinečné shodě VS, částky,
 * měny a data. Auto-partial přijaté faktury, které
 * skutečně kryjí celý doklad, uzavře jako plné (stejná měna přes 548/648; CZK
 * karetní platba cizoměnového dokladu přes kurzový rozdíl 563/663).
 *
 * POŘADÍ (M6, závazné): spouštět AŽ PO `api/bin/backfill-accounting.php` — předpisy
 * dokladů musí být v deníku dřív než platby. Guard H1 (document_not_posted) činí špatné
 * pořadí neškodné: platby bez zaúčtovaného předpisu se přeskočí a druhý běh po doúčtování
 * předpisů je doúčtuje (self-healing).
 *
 * Použití:
 *   php api/bin/backfill-bank-posting.php --supplier=1                    # DRY-RUN (nic nezapíše)
 *   php api/bin/backfill-bank-posting.php --supplier=1 --apply            # ostrý běh
 *   php api/bin/backfill-bank-posting.php --supplier=1 --from=2026-01-01  # jen od data
 *   php api/bin/backfill-bank-posting.php --supplier=1 --apply --rules    # i návrhy z pravidel
 *   php api/bin/backfill-bank-posting.php --supplier=1 --apply --auto     # + zaúčtovat, kde politika říká auto
 *
 * Argumenty:
 *   --supplier=<id>   (povinné) firma (musí vést podvojné účetnictví)
 *   --from=<YYYY-MM-DD> (volitelné) jen transakce od data — řídí, aby fronta nenabobtnala historií
 *   --apply           ostrý běh (bez něj DRY-RUN — nic nezapíše)
 *   --rules           vyhodnotit i nespárované transakce dle pravidel (jen suggest)
 *   --auto            respektovat auto_posting_policy: co má úroveň `auto`, dávka ZAÚČTUJE
 *                     (implikuje --rules). Bez tohoto přepínače se chování nemění, takže
 *                     nikoho neobsluhovaná dávka nepřekvapí. Firma bez nastavené politiky
 *                     má default `suggest` → i s --auto dostane jen návrhy.
 */

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Bank\BankPostingBackfill;

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
$from       = argValue($argv, 'from');
$apply      = in_array('--apply', $argv, true);
$honourPolicy = in_array('--auto', $argv, true);
$withRules  = in_array('--rules', $argv, true) || $honourPolicy;

if ($supplierId <= 0) {
    fwrite(STDERR, "Chybí --supplier=<id>.\nPoužití: php api/bin/backfill-bank-posting.php --supplier=<id> [--from=YYYY-MM-DD] [--apply] [--rules] [--auto]\n");
    exit(2);
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
    fwrite(STDERR, "Firma #{$supplierId} nevede podvojné účetnictví (accounting_mode={$mode}) — není co účtovat.\n");
    exit(2);
}

$prefix = $apply ? '' : '[DRY-RUN] ';
$scope  = $from !== null ? "od {$from}" : 'celá historie';
$rules  = $withRules
    ? ($honourPolicy ? 'ano (dle auto_posting_policy — auto se ZAÚČTUJE)' : 'ano (jen suggest)')
    : 'ne (jen spárované platby)';
echo "{$prefix}Backfill bankovních transakcí — firma #{$supplierId}, {$scope}.\n";
echo "Pravidla (nespárované): {$rules}\n";
echo "POZOR: spouštěj až PO backfill-accounting.php (předpisy dokladů musí být v deníku dřív).\n\n";

/** @var BankPostingBackfill $runner */
$runner = $container->get(BankPostingBackfill::class);
$report = $runner->run($supplierId, $from, $apply, $withRules, null, false, $honourPolicy);

echo "═══ REPORT ═════════════════════════════════════════════════════\n";
printf("  kandidátů:  %d\n", $report['candidates']);
printf("  posted:     %d\n", $report['posted']);
printf("  historické platby navázáno: %d\n", $report['reconciled_legacy']);
printf("  partial → plné:  %d\n", $report['normalized_full']);
printf("  suggested:  %d\n", $report['suggested']);
printf("  skipped:    %d\n", $report['skipped']);

if ($report['suggest_reasons'] !== []) {
    echo "  ── návrhy dle důvodu ──\n";
    arsort($report['suggest_reasons']);
    foreach ($report['suggest_reasons'] as $reason => $n) {
        printf("     %-24s %d×\n", $reason, $n);
    }
}
if ($report['skip_reasons'] !== []) {
    echo "  ── přeskočeno dle důvodu ──\n";
    arsort($report['skip_reasons']);
    foreach ($report['skip_reasons'] as $reason => $n) {
        printf("     %-24s %d×\n", $reason, $n);
    }
}
if (($report['errors'] ?? []) !== []) {
    echo "  ── tvrdé chyby (text výjimky) ──\n";
    foreach ($report['errors'] as $err) {
        printf("     tx #%-7d %-22s %s\n", $err['tx_id'], $err['reason'], $err['message']);
    }
}
echo "═══════════════════════════════════════════════════════════════\n";

if (!$apply) {
    echo "\n(dry-run — nic nebylo zapsáno; pro ostrý běh přidej --apply)\n";
} elseif ($report['posted'] > 0) {
    echo "\nNyní znovu spusť backfill-accounting.php pro doplnění zúčtování záloh 324/311 a 321/314.\n";
}

exit(0);
