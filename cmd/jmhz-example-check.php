<?php

declare(strict_types=1);

/**
 * Ověření vykonávacích kontrol JMHZ proti oficiálním příkladům XML od ČSSZ.
 *
 * Syntetický vzorek v testech ověřuje, že kontroly dělají, co jsme napsali.
 * Tenhle skript ověřuje něco jiného a cennějšího: že nedělají nic navíc na
 * datech, která ČSSZ sama rozesílá jako vzor. Falešný nález na oficiálním
 * příkladu je vážnější než chybějící kontrola — posílá uživatele opravovat
 * správně vyplněné podání.
 *
 * **Bajty příkladů se do repozitáře nekopírují.** Připnutý katalog příkladů je
 * vede jako `usage: source_evidence_only` a `privacy_policy:
 * xml_bytes_external_not_redistributed`, takže se čtou přímo z archivu
 * v gitignorovaném `private/`. Bez něj skript nic nedělá a nic nehlásí.
 *
 * Použití:
 *   php cmd/jmhz-example-check.php
 *   php cmd/jmhz-example-check.php --verbose
 *
 * Návratový kód je 1, když se objeví nález MIMO očekávané. Očekávané jsou
 * kontroly 31 a 131 (období příkladů předchází účinnosti JMHZ) — příklady
 * používají období od roku 2016 a hlásit je jako mimo účinnost je správně.
 */

require __DIR__ . '/../api/vendor/autoload.php';

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlContext;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlOutcome;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1ControlValidator;

const ARCHIVE = __DIR__ . '/../private/Mzdy/podklady/'
    . 'Příklady XML - REGZEC_REGZEL-DOPL_JMHZ_DZMH_2026-04-13.zip';

/** Kontroly, jejichž nález je na těchto příkladech očekávaný a správný. */
const EXPECTED_FINDINGS = [31, 131];

$verbose = in_array('--verbose', $argv, true);

if (!is_file(ARCHIVE)) {
    fwrite(STDERR, "Archiv oficiálních příkladů není k dispozici; kontrola se přeskakuje.\n");
    fwrite(STDERR, 'Očekávaná cesta: ' . ARCHIVE . "\n");
    exit(0);
}

$zip = new ZipArchive();
if ($zip->open(ARCHIVE) !== true) {
    fwrite(STDERR, "Archiv oficiálních příkladů nelze otevřít.\n");
    exit(1);
}

$validator = JmhzScenario1ControlValidator::create();
$evaluated = 0;
$unreadable = 0;
$unexpected = [];

for ($index = 0; $index < $zip->numFiles; $index++) {
    $entry = (string) $zip->getNameIndex($index);
    if (!str_contains($entry, 'JMHZ') || !str_ends_with(strtolower($entry), '.xml')) {
        continue;
    }
    $label = basename($entry);
    $xml = (string) $zip->getFromIndex($index);

    try {
        $report = $validator->validate(
            $xml,
            new JmhzControlContext(gmdate('Y-m-d'), null, true),
        );
    } catch (Throwable $exception) {
        // Formuláře odloženého příjmu mají ELDP blok na jiné cestě slovníku;
        // první profil je nestaví a fail-closed je tam správná odpověď.
        ++$unreadable;
        if ($verbose) {
            printf("%-62s nepromítnuto: %s\n", mb_substr($label, 0, 60), $exception->getMessage());
        }
        continue;
    }

    ++$evaluated;
    foreach ($report->findings as $finding) {
        if ($finding->outcome !== JmhzControlOutcome::Failed) {
            continue;
        }
        if (in_array($finding->controlId, EXPECTED_FINDINGS, true)) {
            continue;
        }
        $unexpected[] = sprintf(
            '%s — kontrola %d: %s',
            $label,
            $finding->controlId,
            $finding->message,
        );
    }
}

printf(
    "Oficiální příklady JMHZ: %d vyhodnoceno, %d nepromítnuto, %d neočekávaných nálezů.\n",
    $evaluated,
    $unreadable,
    count($unexpected),
);
foreach ($unexpected as $line) {
    echo '  ', $line, "\n";
}

exit($unexpected === [] ? 0 : 1);
