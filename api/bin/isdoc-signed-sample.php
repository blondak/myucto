<?php

declare(strict_types=1);

/**
 * Ukázkové PDF vydané faktury s podepsaným vloženým ISDOC — pro ruční ověření
 * v POHODĚ nebo jiném účetním programu.
 *
 * Vyrenderuje PDF faktury stejnou cestou jako aplikace (podpis ISDOC → vložení
 * do PDF → PAdES podpis PDF) a uloží ho spolu se samostatným `.isdoc` do
 * výstupního adresáře. Nic trvale nemění:
 *
 *   - s `--pfx` založí dočasný podpisový profil a konfiguraci výstupu
 *     „Vydaná faktura" v transakci, kterou na konci vrátí;
 *   - bez `--pfx` použije konfiguraci podpisů, kterou dodavatel už má;
 *   - uložené PDF faktury v úložišti (cache) i `pdf_path` vrátí do původního stavu.
 *
 * Heslo k certifikátu se nečte z příkazové řádky (zůstalo by v historii shellu):
 * bere se z proměnné prostředí `ISDOC_PFX_PASSWORD`, jinak se na něj skript zeptá.
 *
 * Použití:
 *   php api/bin/isdoc-signed-sample.php --invoice=123 --pfx=C:\cesta\cert.p12 [--out=C:\tmp\isdoc-test]
 *   php api/bin/isdoc-signed-sample.php --invoice=123          # s existující konfigurací podpisů
 *
 * `--no-xpath` podepíše bez doporučené transformace XPath (jen povinná Enveloped
 * Signature) — pro porovnání s ověřovateli, které XPath nepovolují (.NET Framework).
 * Soubory se pak jmenují `…-bez-xpath`.
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\SigningProfileRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Import\PdfIsdocExtractor;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Service\Signing\Xml\XmlDsigEnvelopedSigner;

$invoiceId = 0;
$pfxPath = null;
$xpathFilter = true;
$outDir = PHP_OS_FAMILY === 'Windows' ? 'C:\\tmp\\isdoc-test' : sys_get_temp_dir() . '/isdoc-test';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--invoice=')) {
        $invoiceId = (int) substr($arg, 10);
    } elseif (str_starts_with($arg, '--pfx=')) {
        $pfxPath = substr($arg, 6);
    } elseif ($arg === '--no-xpath') {
        $xpathFilter = false;
    } elseif (str_starts_with($arg, '--out=')) {
        $outDir = substr($arg, 6);
    } else {
        fwrite(STDERR, "Neznámý argument: {$arg}\n");
        exit(2);
    }
}
if ($invoiceId <= 0) {
    fwrite(STDERR, "Zadejte --invoice=<id vydané faktury>.\n");
    exit(2);
}

$c = Bootstrap::buildContainer();
if (!$xpathFilter) {
    $c->set(XmlDsigEnvelopedSigner::class, new XmlDsigEnvelopedSigner(xpathFilter: false));
}
$db = $c->get(Connection::class);
$pdo = $db->pdo();
$invoice = $c->get(InvoiceRepository::class)->find($invoiceId);
if ($invoice === null) {
    fwrite(STDERR, "Faktura #{$invoiceId} neexistuje.\n");
    exit(1);
}
if (strtoupper((string) $invoice['currency']) !== 'CZK' || empty($invoice['varsymbol'])) {
    fwrite(STDERR, "ISDOC se vkládá jen do CZK faktur s číslem dokladu.\n");
    exit(1);
}
$supplierId = (int) $invoice['supplier_id'];

$pdo->beginTransaction();
$backup = null;
$cachePath = null;
$exit = 0;
try {
    if ($pfxPath !== null) {
        $pfx = @file_get_contents($pfxPath);
        if (!is_string($pfx) || $pfx === '') {
            throw new RuntimeException("Certifikát {$pfxPath} nelze přečíst.");
        }
        $password = getenv('ISDOC_PFX_PASSWORD');
        if (!is_string($password) || $password === '') {
            fwrite(STDOUT, 'Heslo k certifikátu: ');
            $password = rtrim((string) fgets(STDIN), "\r\n");
        }
        $probe = [];
        if (!openssl_pkcs12_read($pfx, $probe, $password)) {
            throw new RuntimeException('Certifikát nejde otevřít — špatné heslo nebo poškozený soubor.');
        }
        $profiles = $c->get(SigningProfileRepository::class);
        $profileId = $profiles->createProfile($supplierId, null, 'ISDOC ukázka', 'isdoc-sample-' . bin2hex(random_bytes(3)));
        $profiles->upsertCredential($supplierId, $profileId, [
            'certificate_path' => realpath($pfxPath) ?: $pfxPath,
            'passphrase_policy' => 'encrypted_store',
            'encrypted_passphrase' => $c->get(SecretEncryption::class)->encrypt($password),
        ]);
        $profiles->upsertOutputSetting($supplierId, 'invoice', [
            'enabled' => true,
            'selection_source' => 'admin_profile_settings',
            'default_profile_id' => $profileId,
            'failure_policy' => 'fail_closed',
        ]);
    }

    $renderer = $c->get(InvoicePdfRenderer::class);
    $cachePath = (new ReflectionMethod(InvoicePdfRenderer::class, 'cachePath'))->invoke($renderer, $invoice);
    if (is_file($cachePath)) {
        $backup = $cachePath . '.isdoc-sample-backup';
        copy($cachePath, $backup);
    }

    // Bez pdf_path renderer PDF vždy vyrenderuje znovu (a podepíše).
    $path = $renderer->render($invoiceId, false, null, ['pdf_path' => null, 'pdf_generated_at' => null] + $invoice);
    $pdf = (string) file_get_contents($path);
    $isdoc = (new PdfIsdocExtractor())->extract($pdf);

    if (!is_dir($outDir) && !mkdir($outDir, 0755, true) && !is_dir($outDir)) {
        throw new RuntimeException("Adresář {$outDir} nelze vytvořit.");
    }
    $base = 'Faktura-' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $invoice['varsymbol']) . ($xpathFilter ? '' : '-bez-xpath');
    file_put_contents($outDir . DIRECTORY_SEPARATOR . $base . '.pdf', $pdf);
    if (is_string($isdoc)) {
        file_put_contents($outDir . DIRECTORY_SEPARATOR . $base . '.isdoc', $isdoc);
    }

    $pdfSigned = preg_match('#/SubFilter\s*/(adbe\.pkcs7\.detached|ETSI\.CAdES\.detached)#', $pdf) === 1;
    $isdocSigned = is_string($isdoc) && str_contains($isdoc, 'http://www.w3.org/2000/09/xmldsig#');
    echo "PDF:   {$outDir}" . DIRECTORY_SEPARATOR . "{$base}.pdf (" . ($pdfSigned ? 'podepsané' : 'NEPODEPSANÉ') . ")\n";
    echo "ISDOC: " . (is_string($isdoc)
        ? "{$outDir}" . DIRECTORY_SEPARATOR . "{$base}.isdoc (" . ($isdocSigned ? 'podepsané' : 'NEPODEPSANÉ') . ")\n"
        : "v PDF chybí (dodavatel nemá zapnuté vkládání ISDOC?)\n");
    if (!$pdfSigned || !$isdocSigned) {
        $exit = 1;
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Chyba: ' . $e->getMessage() . ($e->getPrevious() ? ' (' . $e->getPrevious()->getMessage() . ')' : '') . "\n");
    $exit = 1;
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($cachePath !== null) {
        if ($backup !== null && is_file($backup)) {
            @unlink($cachePath);
            rename($backup, $cachePath);
        } elseif (is_file($cachePath)) {
            @unlink($cachePath);
        }
    }
}

exit($exit);
