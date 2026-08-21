<?php

declare(strict_types=1);

namespace MyInvoice\Service\Epo;

use MyInvoice\Infrastructure\Config\RuntimePaths;

/**
 * Rozbalí dodejku EPO (CMS/PKCS#7 SignedData v DER) na části, které jdou přečíst.
 *
 * Potvrzenka je binární obálka — sama o sobě je nečitelná a všechno podstatné má
 * uvnitř. U ASISTOVANÉHO podání přiloží účetní jednotlivé soubory ručně přes
 * „Nahrát výstupy z EPO"; u PŘÍMÉHO (ZAREP) je aplikace drží v ruce, takže nemá
 * důvod nechat je zabalené a nutit uživatele k hex editoru.
 *
 * Vytahují se tři věci:
 *
 *   - **podepsaný obsah** — vlastní XML potvrzení (`<Pisemnost>`) s podacím číslem,
 *     rozhodným časem a kontrolním součtem odeslaného souboru;
 *   - **echo podání** — hexem zakódovaný `<Data>` blok, tedy REDUKOVANÁ podoba toho,
 *     co si finanční správa u podání eviduje (souhrnné věty, ne detailní řádky);
 *   - **certifikát pečeti** — jím se dá podpis ověřit i za pár let, až vydávající
 *     autorita certifikát vymění nebo skončí.
 *
 * Zdrojové XML podání se ZÁMĚRNĚ nevytahuje: je bajt po bajtu totožné s archivovaným
 * snapshotem (`source_xml`), takže by vznikla jen druhá kopie téhož souboru.
 *
 * Heslo pro dotaz na stav (`Podani/@Heslo`) zůstává v podepsaném obsahu tak, jak ho
 * EPO vydalo. Skrývat ho tady nemá smysl — táž hodnota je v archivované P7S, ze které
 * se obsah bere, a bez něj by se účetní nedostala k opisu podání na Daňovém portálu.
 * Do logů heslo nevstupuje; artefakty jsou za oprávněním `reports`.
 */
final class EpoConfirmationExtractor
{
    /** Nad tuhle mez už to není dodejka, ale pokus o zahlcení. */
    private const MAX_INPUT_BYTES = 10 * 1024 * 1024;

    /**
     * @return array{
     *   confirmation_xml:?string,
     *   echo:?array{bytes:string, suffix:string},
     *   seal_certificate_pem:?string, submission_certificate_pem:?string
     * }
     */
    public function extract(string $confirmationBytes): array
    {
        $empty = [
            'confirmation_xml' => null,
            'echo' => null,
            'seal_certificate_pem' => null,
            'submission_certificate_pem' => null,
        ];
        if (
            $confirmationBytes === ''
            || strlen($confirmationBytes) > self::MAX_INPUT_BYTES
            || !function_exists('openssl_cms_verify')
        ) {
            return $empty;
        }

        $input = $this->tempPath('conf-');
        $content = $this->tempPath('content-');
        $certs = $this->tempPath('certs-');
        try {
            if (file_put_contents($input, $confirmationBytes) === false) {
                return $empty;
            }
            // Důvěryhodnost potvrzenky řeší volající PŘED archivací
            // ({@see EpoDirectSubmissionService::confirm}); tady jde jen o rozbalení,
            // takže se řetězec neověřuje znovu.
            $unwrapped = @openssl_cms_verify(
                $input,
                OPENSSL_CMS_BINARY | OPENSSL_CMS_NOVERIFY,
                $certs,
                [],
                null,
                $content,
                null,
                null,
                OPENSSL_ENCODING_DER,
            );
            if ($unwrapped !== true || !is_file($content)) {
                return $empty;
            }

            $xml = (string) file_get_contents($content);

            return [
                'confirmation_xml' => $xml === '' ? null : $xml,
                'echo' => $this->embeddedSubmission($xml),
                'seal_certificate_pem' => $this->sealCertificate($certs),
                'submission_certificate_pem' => $this->submissionCertificates($xml),
            ];
        } catch (\Throwable) {
            return $empty;
        } finally {
            @unlink($input);
            @unlink($content);
            @unlink($certs);
        }
    }

    /**
     * `<Data>` nese podání tak, jak si ho EPO eviduje. Není to bajtová kopie odeslaného
     * XML — vrací se REDUKOVANÁ podoba (zahodí detailní řádky, přeformátuje čísla), proto
     * se ukládá jako samostatný důkaz „co u toho podání leží", ne jako náhrada snapshotu.
     *
     * FORMÁT SE NEPŘEDPOKLÁDÁ. Ověřeno je hexem kódované XML u kontrolního hlášení;
     * u DPH přiznání, souhrnného hlášení nebo DPPO může EPO vrátit base64 místo hexu
     * a u rozsáhlých písemností i ZIP místo holého XML. Kódování se proto zkouší
     * obojí a podle magic bytes se volí přípona — natvrdo `.xml` by u ZIPu lhalo
     * a natvrdo hex by u ostatních formulářů echo tiše zahodil.
     *
     * @return array{bytes:string, suffix:string}|null
     */
    private function embeddedSubmission(string $xml): ?array
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        libxml_clear_errors();
        $loaded = $dom->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors(false);
        if (!$loaded) {
            return null;
        }
        $node = (new \DOMXPath($dom))->query('//*[local-name()="Data"]')->item(0);
        if ($node === null) {
            return null;
        }
        $encoded = preg_replace('/\s+/', '', $node->textContent) ?? '';
        if ($encoded === '') {
            return null;
        }

        $decoded = null;
        if (strlen($encoded) % 2 === 0 && ctype_xdigit($encoded)) {
            $hex = @hex2bin($encoded);
            $decoded = is_string($hex) && $hex !== '' ? $hex : null;
        }
        if ($decoded === null) {
            $b64 = base64_decode($encoded, true);
            // Round-trip: hex projde i jako base64, ale zpátky se nezakóduje stejně.
            if (is_string($b64) && $b64 !== '' && base64_encode($b64) === $encoded) {
                $decoded = $b64;
            }
        }
        if ($decoded === null) {
            return null;
        }

        return ['bytes' => $decoded, 'suffix' => self::payloadSuffix($decoded)];
    }

    /** Přípona podle skutečného obsahu, ne podle přání. */
    private static function payloadSuffix(string $bytes): string
    {
        if (str_starts_with($bytes, "PK\x03\x04")) {
            return 'zip';
        }
        $head = ltrim(substr($bytes, 0, 64));
        if (str_starts_with($head, '<?xml') || str_starts_with($head, '<')) {
            return 'xml';
        }
        return 'bin';
    }

    /**
     * Certifikát, kterým bylo PODÁNÍ podepsáno — EPO ho vrací zpět v `<Certifikaty>`
     * jako doklad o tom, kdo za daňový subjekt podal. Nejde o pečeť správce daně
     * ({@see sealCertificate}), ale o ZAREP podepisující osoby.
     *
     * Obsah je base64 nad PKCS#7 v BER s NEURČITOU délkou (`30 80 …`). `openssl_pkcs7_read()`
     * na tom selže, proto se certifikáty hledají skenem: každý X.509 začíná `30 82 LL LL`
     * a buď se jako certifikát přečíst dá, nebo ne. Kandidáty ověřuje sám OpenSSL, takže
     * falešný poplach ze skenu neprojde — jen se zahodí.
     */
    private function submissionCertificates(string $xml): ?string
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        libxml_clear_errors();
        $loaded = $dom->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors(false);
        if (!$loaded) {
            return null;
        }
        $node = (new \DOMXPath($dom))->query('//*[local-name()="Certifikaty"]')->item(0);
        if ($node === null) {
            return null;
        }
        $der = base64_decode(preg_replace('/\s+/', '', $node->textContent) ?? '', true);
        if (!is_string($der) || $der === '') {
            return null;
        }

        $pems = [];
        $seen = [];
        $length = strlen($der);
        for ($i = 0; $i + 4 < $length; $i++) {
            if ($der[$i] !== "\x30" || $der[$i + 1] !== "\x82") {
                continue;
            }
            $size = ((ord($der[$i + 2]) << 8) | ord($der[$i + 3])) + 4;
            if ($size < 300 || $i + $size > $length) {
                continue;
            }
            $pem = "-----BEGIN CERTIFICATE-----\n"
                . chunk_split(base64_encode(substr($der, $i, $size)), 64, "\n")
                . "-----END CERTIFICATE-----\n";
            if (!is_array(@openssl_x509_parse($pem, false))) {
                continue;
            }
            $fingerprint = @openssl_x509_fingerprint($pem, 'sha256');
            if (!is_string($fingerprint) || isset($seen[$fingerprint])) {
                continue;
            }
            $seen[$fingerprint] = true;
            $pems[] = $pem;
            $i += $size - 1;
        }

        return $pems === [] ? null : implode('', $pems);
    }

    /** Certifikát pečeti GFŘ; víc podepisujících dodejka nemá, bereme první. */
    private function sealCertificate(string $certsPath): ?string
    {
        if (!is_file($certsPath)) {
            return null;
        }
        $pem = (string) file_get_contents($certsPath);
        if (!preg_match('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $pem, $m)) {
            return null;
        }
        return rtrim($m[0]) . "\n";
    }

    private function tempPath(string $prefix): string
    {
        $dir = RuntimePaths::storage('tmp/epo');
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new EpoSubmissionException(
                'storage_not_writable',
                'Nelze vytvořit bezpečné dočasné úložiště.',
                500,
            );
        }
        $path = tempnam($dir, $prefix);
        if ($path === false) {
            throw new EpoSubmissionException(
                'storage_not_writable',
                'Nelze vytvořit dočasný soubor.',
                500,
            );
        }
        @chmod($path, 0600);
        return $path;
    }
}
