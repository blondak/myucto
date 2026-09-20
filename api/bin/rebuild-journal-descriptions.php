<?php

declare(strict_types=1);

/**
 * Zpětné dogenerování popisů účetního deníku.
 *
 * Instalace s převzatým deníkem (POHODA, Money S3) mají zápisy s popisem z jediného
 * pole zdrojového systému, takže desítky řádků za sebou nesou shodný text. Skript
 * jim popis složí znovu podle zdrojového dokladu
 * ({@see \MyInvoice\Service\Accounting\JournalDescriptionBuilder}).
 *
 * NEPŘEPISUJE ruční zápisy, zápisy bez zdrojového dokladu, uzávěrkové/mzdové/
 * majetkové zápisy ani popis, který uživatel sám změnil (§35 inline editace). Mění
 * se JEN narativní popis — částky, účty, období ani čísla dokladů zůstávají.
 *
 * Použití:
 *   php api/bin/rebuild-journal-descriptions.php                       # dry-run, vše
 *   php api/bin/rebuild-journal-descriptions.php --apply
 *   php api/bin/rebuild-journal-descriptions.php --supplier=2 --apply
 *   php api/bin/rebuild-journal-descriptions.php --source-type=bank --limit=200
 *
 * Přepínače:
 *   --dry-run            jen vypíše, co by se změnilo (výchozí chování)
 *   --apply              skutečně zapíše
 *   --supplier=N         jen jedna firma (supplier_id)
 *   --source-type=T      invoice | purchase_invoice | bank | cash
 *   --limit=N            nejvýše N zápisů (výchozí 5000)
 *   --samples=N          kolik ukázek „před → po" vypsat (výchozí 20)
 */

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Service\Accounting\JournalDescriptionBuilder;
use MyInvoice\Service\Accounting\JournalDescriptionRebuilder;

/** @return string|null hodnota přepínače --klic=hodnota */
$opt = static function (string $name) use ($argv): ?string {
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--' . $name . '=')) {
            return substr($arg, strlen($name) + 3);
        }
    }
    return null;
};

$apply      = in_array('--apply', $argv, true) && !in_array('--dry-run', $argv, true);
$supplier   = $opt('supplier');
$sourceType = $opt('source-type');
$limit      = $opt('limit');
$samples    = (int) ($opt('samples') ?? '20');

if ($sourceType !== null && !in_array($sourceType, JournalDescriptionBuilder::BUILDABLE, true)) {
    fwrite(STDERR, "Neznámý --source-type={$sourceType}. Povolené: "
        . implode(', ', JournalDescriptionBuilder::BUILDABLE) . "\n");
    exit(2);
}

$app       = \MyInvoice\Bootstrap::buildApp();
$container = $app->getContainer();
/** @var JournalDescriptionRebuilder $rebuilder */
$rebuilder = $container->get(JournalDescriptionRebuilder::class);

$filter = [
    'supplier_id' => $supplier !== null ? (int) $supplier : null,
    'source_type' => $sourceType,
    'limit'       => 2000, // velikost dávky, ne strop změn — ten hlídá --limit níž
];
$max = $limit !== null ? max(1, (int) $limit) : null;

// Stránkuje se přes kurzor `id`: přepsaný zápis z výběru vypadne, takže OFFSET by
// další dávku přeskočil. Deník má u převedených instalací desítky tisíc zápisů.
$plan    = [];
$afterId = 0;
while ($max === null || count($plan) < $max) {
    $batch = $rebuilder->planBatch(array_merge($filter, ['after_id' => $afterId]));
    if ($batch['last_id'] === null || $batch['last_id'] <= $afterId) {
        break;
    }
    $afterId = $batch['last_id'];
    foreach ($batch['items'] as $item) {
        $plan[] = $item;
        if ($max !== null && count($plan) >= $max) {
            break;
        }
    }
}
$mode = $apply ? '' : '[DRY-RUN] ';

if ($plan === []) {
    echo "{$mode}Žádný zápis nepotřebuje nový popis.\n";
    exit(0);
}

$byType = [];
foreach ($plan as $item) {
    $byType[$item['source_type']] = ($byType[$item['source_type']] ?? 0) + 1;
}
ksort($byType);

echo "{$mode}Ke změně je " . count($plan) . " zápisů:\n";
foreach ($byType as $type => $count) {
    printf("  %-18s %6d\n", $type, $count);
}

if ($samples > 0) {
    echo "\nUkázka (před → po):\n";
    foreach (array_slice($plan, 0, $samples) as $item) {
        printf("  #%-8d %s\n", $item['id'], $item['before'] ?? '(bez popisu)');
        printf("  %9s → %s\n", '', $item['after']);
    }
}

if (!$apply) {
    echo "\nSpusť znovu s --apply pro skutečný zápis.\n";
    exit(0);
}

$changed = $rebuilder->apply($plan);
echo "\nHotovo. Přepsáno popisů: {$changed}.\n";
if (count($plan) > $changed) {
    echo 'Pozor: ' . (count($plan) - $changed) . " zápisů se mezitím změnilo a přeskočilo se.\n";
}
