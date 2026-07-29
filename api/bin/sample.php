<?php

declare(strict_types=1);

/**
 * SAMPLE DATA — generuje testovací data pro vývoj.
 *
 *   php api/bin/sample.php           # interaktivní potvrzení
 *   php api/bin/sample.php --yes     # bez ptaní
 *
 * Vytvoří pro první firmu rozsáhlý syntetický dataset za posledních 12 měsíců:
 *   - 24 klientů, 36 zakázek, 120 vydaných faktur a 12 dobropisů
 *   - 12 dodavatelů a 120 přijatých faktur
 *   - 1 pokladna a 7 příjmových/výdajových pokladních dokladů
 *   - pro s.r.o./PO nebo plátce DPH také podvojné účetnictví, sklad,
 *     120 skladových dokladů, 2 majetky, 6 bankovních výpisů / 120 pohybů
 *     3 e-shopové kategorie, 3 výrobci, zaúčtovaný účetní deník a ukázkové
 *     případy ve všech frontách sekce Automatizace
 *   - kniha jízd: 1 firemní auto, 15 jízd, 6 tankování
 *
 * Vyžaduje již proběhlý `setup.php` (admin user + supplier v DB).
 *
 * Doporučené pořadí spouštění (fresh dev install):
 *   1. cp cfg.sample.php cfg.php  +  vyplň db/smtp/pepper
 *   2. php api/bin/setup.php       # interaktivně: migrace + supplier + admin
 *   3. php api/bin/sample.php      # tento skript — testovací data
 *   ── později ──
 *      php api/bin/reset.php       # wipe všeho, pak znovu setup + sample
 *
 * Logika je sdílená s HTTP setup wizardem (POST /api/auth/setup-sample) —
 * implementace v `Service\Sample\SampleDataGenerator`.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403); exit("CLI only.\n");
}

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Sample\SampleDataGenerator;

$autoYes = in_array('--yes', $argv, true) || in_array('-y', $argv, true);

$app = Bootstrap::buildApp();
$container = $app->getContainer();
$pdo = $container->get(Connection::class)->pdo();

$adminId = (int) $pdo->query(
    "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
      WHERE r.system_key = 'superadmin' AND r.role_type = 'superadmin' AND r.is_active = 1
        AND u.is_active = 1 ORDER BY u.id LIMIT 1"
)->fetchColumn();
$supplierId = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
if ($adminId === 0 || $supplierId === 0) {
    fwrite(STDERR, "[sample] Chybí předpoklady (admin: $adminId, supplier: $supplierId).\n");
    fwrite(STDERR, "[sample] Spusť nejdřív interaktivní setup:\n         php api/bin/setup.php\n");
    exit(1);
}

// Guard: sample data se generují JEN do prázdné DB (stejně jako HTTP setup wizard).
// Bez něj druhý běh duplikoval klienty/doklady a padal na UNIQUE (cars.registration).
$guard = $pdo->prepare(
    'SELECT (SELECT COUNT(*) FROM clients          WHERE supplier_id = ?)
          + (SELECT COUNT(*) FROM invoices         WHERE supplier_id = ?)
          + (SELECT COUNT(*) FROM purchase_invoices WHERE supplier_id = ?)'
);
$guard->execute([$supplierId, $supplierId, $supplierId]);
if ((int) $guard->fetchColumn() > 0) {
    fwrite(STDERR, "[sample] Pro dodavatele #$supplierId už existují klienti nebo doklady —\n");
    fwrite(STDERR, "         ukázková data lze generovat jen do prázdné DB.\n");
    fwrite(STDERR, "         Nejdřív je odeber:\n");
    fwrite(STDERR, "           php api/bin/reset.php --keep-users-supplier   (smaže jen byznys data)\n");
    fwrite(STDERR, "           php api/bin/reset.php                         (úplný reset)\n");
    fwrite(STDERR, "         nebo v aplikaci: Nastavení → Odebrat ukázková data.\n");
    exit(1);
}

echo "================================================\n";
echo "  MyÚčto.cz — ROZŠÍŘENÁ UKÁZKOVÁ DATA\n";
echo "================================================\n";
echo "  Supplier:   #$supplierId\n";
echo "  Admin:      #$adminId\n";
echo "  Vygeneruje: 24 klientů, 12 dodavatelů, 120 vydaných + 120 přijatých faktur,\n";
echo "              pokladnu se 7 doklady, sklad, e-shopové číselníky, majetek, banku a deník.\n";
echo "  Období:     posledních 12 měsíců\n";
echo "================================================\n\n";

if (!$autoYes) {
    echo "Pokračovat? (ANO): ";
    if (trim((string) fgets(STDIN)) !== 'ANO') { echo "Zrušeno.\n"; exit(0); }
}

$generator = $container->get(SampleDataGenerator::class);
try {
    $r = $generator->generate($supplierId, $adminId);
} catch (\Throwable $e) {
    fwrite(STDERR, "[sample] Generování selhalo: " . $e->getMessage() . "\n");
    exit(1);
}

echo "================================================\n";
printf("  HOTOVO. %d klientů, %d dodavatelů, %d zakázek, %d vydaných a %d přijatých faktur.\n", $r['clients'], $r['vendors'], $r['projects'], $r['invoices'], $r['purchase_invoices']);
printf("          %d dobropisů, %d skladových dokladů, %d majetky, %d výpisů / %d pohybů.\n", $r['credit_notes'], $r['stock_documents'], $r['assets'], $r['bank_statements'], $r['bank_transactions']);
printf("          Pokladna: %d / %d dokladů. E-shop: %d kategorie / %d výrobci.\n", $r['cash_registers'], $r['cash_documents'], $r['eshop_categories'], $r['manufacturers']);
printf("          Účetní deník: %d zaúčtovaných zápisů. Podvojné účetnictví: %s.\n", $r['journal_entries'], $r['accounting_enabled'] ? 'ano' : 'ne');
printf("          Automatizace: %d samo / %d ke schválení / %d potřebuje mě / %d potvrzeno.\n", $r['automation_auto'], $r['automation_pending'], $r['automation_needs_input'], $r['automation_approved']);
printf("          Kniha jízd: %d auto, %d jízd, %d tankování.\n", $r['cars'], $r['trips'], $r['fuelings']);
foreach ($r['warnings'] as $warning) fwrite(STDERR, "[sample] Upozornění: {$warning}\n");
echo "================================================\n";
