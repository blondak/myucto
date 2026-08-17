<?php

declare(strict_types=1);

/**
 * Instalace připnutého XSD jednotné datové věty zdravotních pojišťoven.
 *
 * Skript ZÁMĚRNĚ NESTAHUJE. Zdrojová URL ČPZP jsou CDN hashe, které se mohou
 * kdykoli změnit, a schéma je natolik zásadní vstup, že jeho výměna patří
 * pod ruku člověka. Postup je proto obrácený než u JMHZ: soubory stáhne
 * člověk, skript ověří otisk proti manifestu a teprve pak je nainstaluje.
 *
 * Použití:
 *   php tools/installZpXsd.php <adresář se staženými XSD>
 *
 * Soubory ke stažení (obojí je bajt po bajtu shodné u ČPZP i OZP):
 *   hromadneOznameniZamestnavatele_2025_v8.xsd
 *   prehledPlatbyZamestnavatele_2025_v8.xsd
 */

require __DIR__ . '/../api/vendor/autoload.php';

use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsuranceSchemaCatalog;

$source = $argv[1] ?? null;
if (!is_string($source) || !is_dir($source)) {
    fwrite(
        STDERR,
        "Použití: php tools/installZpXsd.php <adresář se staženými XSD>\n",
    );
    exit(1);
}

$catalog = new HealthInsuranceSchemaCatalog();
$target = __DIR__ . '/../api/xsd/zp/'
    . HealthInsuranceSchemaCatalog::XSD_VERSION;

$staged = [];
foreach ($catalog->documentTypes() as $documentType) {
    $manifest = $catalog->manifestFor($documentType);
    $candidate = rtrim($source, "/\\")
        . DIRECTORY_SEPARATOR . $manifest['filename'];
    if (!is_file($candidate)) {
        fwrite(STDERR, sprintf(
            "[zp-xsd] Chybí %s.\n         Stáhněte ho z %s\n",
            $manifest['filename'],
            $manifest['url'],
        ));
        exit(1);
    }
    $actual = hash_file('sha256', $candidate);
    if (!is_string($actual)
        || !hash_equals($manifest['sha256'], $actual)
    ) {
        fwrite(STDERR, sprintf(
            "[zp-xsd] %s má jiný otisk než připnutý manifest.\n"
            . "         očekáváno %s\n         nalezeno  %s\n"
            . "         Revize schématu je změna podporované specifikace —\n"
            . "         nejdřív ověřte, co se změnilo, a přepište manifest.\n",
            $manifest['filename'],
            $manifest['sha256'],
            (string) $actual,
        ));
        exit(1);
    }
    $contents = file_get_contents($candidate);
    if ($contents === false) {
        fwrite(STDERR, "[zp-xsd] {$manifest['filename']} nelze přečíst.\n");
        exit(1);
    }
    $staged[$manifest['filename']] = $contents;
}

// Instaluje se až po ověření VŠECH souborů: poloviční balíček je horší než
// žádný, protože fail-closed kontrola by prošla u jednoho dokumentu a u
// druhého ne.
if (!is_dir($target) && !mkdir($target, 0o775, true) && !is_dir($target)) {
    fwrite(STDERR, "[zp-xsd] Cílový adresář {$target} nelze vytvořit.\n");
    exit(1);
}
foreach ($staged as $filename => $contents) {
    if (file_put_contents(
        $target . DIRECTORY_SEPARATOR . $filename,
        $contents,
    ) === false) {
        fwrite(STDERR, "[zp-xsd] {$filename} nelze zapsat.\n");
        exit(1);
    }
}

if (!(new HealthInsuranceSchemaCatalog())->isBundleAvailable()) {
    fwrite(
        STDERR,
        "[zp-xsd] Balíček se nainstaloval, ale kontrola otisků neprošla.\n",
    );
    exit(1);
}

fwrite(STDOUT, sprintf(
    "[zp-xsd] Nainstalováno %d XSD do %s.\n",
    count($staged),
    $target,
));
