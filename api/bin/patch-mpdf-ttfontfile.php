<?php

declare(strict_types=1);

/**
 * Po composer install/update aplikuje na vendor/mpdf/mpdf/src/TTFontFile.php
 * fix čtení tabulek fontu přes buffered/wrapped stream (mpdf upstream #1504):
 * fread() přes userland stream wrapper (např. dg/bypass-finals v testech,
 * phar://) vrací max ~8192 B, takže get_table() bez loopu ořízne velké tabulky
 * (glyf ~79 kB u Montserratu) a subsetování fontu tiše selže s warningy.
 *
 * Upstream má fix jen v get_chunk(); get_table() čte fread-em napřímo.
 * Patch přesměruje get_table() na get_chunk(). Idempotentní — už opravený
 * soubor se přeskočí.
 */

$file = __DIR__ . '/../vendor/mpdf/mpdf/src/TTFontFile.php';
if (!is_file($file)) {
    fwrite(STDERR, "TTFontFile.php nenalezen: {$file}\n");
    exit(0); // no-op (mpdf nenainstalován)
}

$src = (string) file_get_contents($file);

// Idempotence: opravený get_table() volá get_chunk() (v neopraveném souboru
// se `$this->get_chunk($pos, $length)` nevyskytuje).
if (str_contains($src, 'return $this->get_chunk($pos, $length);')) {
    echo "[mpdf-ttfontfile-patch] Už aplikováno, přeskakuji.\n";
    exit(0);
}

// Cíl v get_table(): `fseek(...); return (fread($this->fh, $length));`.
// Tahle dvojice statementů je unikátní pro get_table() — v get_chunk() je
// fseek() následován if blokem, ne `return (fread(...))`. Regex je tolerantní
// k whitespace (mpdf mezi 8.2 a 8.3 vložil prázdný řádek před return), aby
// kosmetické přeformátování upstreamu patch nerozbilo.
$pattern = '/fseek\(\$this->fh, \$pos\);\s*return \(fread\(\$this->fh, \$length\)\);/';

$replacement = "// same fix as get_chunk (#1504): fread from a buffered/wrapped stream\n"
    . "\t\t// (phar://, userland stream wrappers) is limited to one chunk (~8192 B),\n"
    . "\t\t// so loop until the whole table is read\n"
    . "\t\treturn \$this->get_chunk(\$pos, \$length);";

$patchedSrc = preg_replace($pattern, $replacement, $src, 1, $count);

if ($patchedSrc === null || $count === 0) {
    fwrite(STDERR, "[mpdf-ttfontfile-patch] Očekávaný kód get_table() nenalezen — mpdf se změnil, patch zkontroluj ručně.\n");
    exit(1);
}

file_put_contents($file, $patchedSrc);
echo "[mpdf-ttfontfile-patch] get_table() přesměrován na get_chunk() (#1504).\n";
