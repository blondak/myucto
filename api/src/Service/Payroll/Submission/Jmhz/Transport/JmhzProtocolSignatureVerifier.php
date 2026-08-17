<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Transport;

use DOMDocument;
use DOMElement;
use DOMXPath;
use MyInvoice\Service\Pdf\Asn1;

/**
 * Ověření podepsané časové značky ČSSZ v protokolu o zpracování.
 *
 * Podací a dotazovací protokol ČSSZ (v1.47, kap. „Podepsaná časová značka
 * odpovědi ČSSZ") předepisuje postup doslova a shodně s ním se chová i
 * referenční implementace v .NET, kterou ČSSZ rozdává jako `CSSZSubmissionDemo`:
 *
 * 1. `Message/Header/Signature/SignatureValue` je base64 **PKCS#7/CMS
 *    SignedData ve VLOŽENÉ (attached) podobě** — jeho `eContent` NENÍ XML, ale
 *    surové bajty otisku;
 * 2. podepsanou částí je **celý element `Message`** (tělo `GovTalkMessage`)
 *    v kanonické podobě podle Canonical XML 1.0, ve které se **vyprázdní jen
 *    textový obsah `SignatureValue`** — element `Signature` včetně
 *    `DigestMethod` a `TimeStamp` v podepsaných datech ZŮSTÁVÁ;
 * 3. otisk se spočítá algoritmem z `DigestMethod/@Algorithm` (dnes SHA-512)
 *    a porovná bajt po bajtu s `eContent`.
 *
 * Ověřeno proti skutečnému protokolu ČSSZ z podkladů: otisk sedí právě tehdy,
 * když se dokument načte **bez bílých míst** (`LIBXML_NOBLANKS`, což odpovídá
 * `XmlDocument.PreserveWhitespace = false` v .NET). S ponechaným odsazením
 * vyjde jiný otisk — je to nejsnáz přehlédnutelný detail celého postupu.
 *
 * Kotvou důvěry je **připnutý certifikát ČSSZ `DIS.CSSZ.2025`**, tentýž, kterým
 * se podání šifruje ({@see JmhzCsszEncryption}). Je to přísnější kotva než
 * kořen certifikační autority: ta by uznala jakýkoli certifikát PostSignum,
 * kdežto otisk uzná jen ten jediný, který ČSSZ zveřejnila. Až ho ČSSZ vymění,
 * ověření začne padat s pojmenovaným důvodem — to je záměr, protokol podepsaný
 * neznámým certifikátem nesmí posunout podání na „přijato“.
 *
 * Všechno je fail-closed. Neověřený protokol není chyba běhu, je to jen
 * příloha bez důkazní síly: {@see JmhzReceiptVerifier} ho pak uloží jako
 * `unverified` a stav podání se nepohne.
 *
 * ⚠️ **Podpis nekryje obálku GovTalk.** `Header/MessageDetails` (a s ním
 * `CorrelationID`) leží MIMO podepsaný `Message`. Proti záměně protokolu za
 * cizí proto stojí dvě věci uvnitř: `Class` se porovnává s `ProcessingResult/@type`
 * z podepsané části a `Qualifier` křížově kontroluje {@see JmhzProtocolParser}.
 * Samotné `CorrelationID` podepsané není a ani být nemůže — kdo ho použije
 * k párování, musí vědět, že se opírá o důvěru v kanál, ne o podpis.
 */
final readonly class JmhzProtocolSignatureVerifier implements JmhzProtocolSignatureVerifierInterface
{
    private const NS_CSSZ = 'http://www.cssz.cz/XMLSchema/envelope';
    private const NS_TIMESTAMP = 'http://www.cssz.cz/emp/timestamp';

    /** Doložené hodnoty `DigestMethod/@Algorithm`; produkce jede SHA-512. */
    private const DIGEST_ALGORITHMS = [
        'http://www.w3.org/2001/04/xmlenc#sha512' => 'sha512',
        'http://www.w3.org/2001/04/xmlenc#sha384' => 'sha384',
        'http://www.w3.org/2001/04/xmlenc#sha256' => 'sha256',
        'http://www.w3.org/2000/09/xmldsig#sha1' => 'sha1',
    ];

    private const ENVIRONMENTS = ['test', 'production'];

    public function __construct(
        private JmhzCsszEncryption $anchor = new JmhzCsszEncryption(),
        /**
         * Kotva mimo připnutý certifikát ČSSZ. `null` znamená připnutý
         * certifikát z repozitáře; jiná hodnota je použitelná jen v testech,
         * kde skutečným klíčem ČSSZ nikdo nepodepíše.
         */
        private ?string $trustAnchorPem = null,
    ) {}

    public function verifiedProtocolXml(string $bytes, string $environment): string
    {
        if (!in_array($environment, self::ENVIRONMENTS, true)) {
            throw new JmhzTransportException(
                'jmhz_protocol_environment_unknown',
                'Prostředí protokolu není ani testovací, ani produkční.',
            );
        }
        $dom = $this->load($bytes);
        $message = $this->requireMessage($dom);
        $signature = $this->requireSignature($dom, $message);
        $algorithm = $this->digestAlgorithm($signature);
        $signedData = $this->signatureValue($signature);

        // Vyprázdní se JEN textový obsah; element `SignatureValue` v podepsaných
        // datech zůstává. Odstranit ho celý je vzor z JINÉHO, VREP vlastního
        // podpisu — tady by vyšel jiný otisk a ověření by padalo vždy.
        $this->signatureValueNode($signature)->textContent = '';

        $digest = hash($algorithm, (string) $message->C14N(), true);
        $signed = $this->verifySignedData($signedData, $this->signedAt($signature));
        if (!hash_equals($signed, $digest)) {
            throw new JmhzTransportException(
                'jmhz_protocol_digest_mismatch',
                'Otisk podepsaný ČSSZ neodpovídá obsahu protokolu; protokol byl'
                    . ' po podpisu změněn.',
            );
        }
        $this->assertClassMatchesSignedContent($dom, $message);

        $xml = $dom->saveXML();
        if (!is_string($xml) || $xml === '') {
            throw new JmhzTransportException(
                'jmhz_protocol_unreadable',
                'Ověřený protokol se nepodařilo serializovat.',
            );
        }
        // Vydat se smí jen to, co po serializaci pořád nese TENTÝŽ podepsaný
        // obsah. Bez téhle kontroly by rozdíl mezi kanonizací a serializací
        // znamenal, že parser dostane jiné bajty, než které prošly ověřením.
        $this->assertRoundTripStable($xml, $algorithm, $digest);

        return $xml;
    }

    /**
     * Kryptografické ověření CMS a vrácení podepsaného otisku.
     *
     * Řetěz se neověřuje přes důvěryhodný sklad OpenSSL, ale proti připnutému
     * certifikátu: sklad by přijal každý certifikát vydaný toutéž autoritou.
     */
    private function verifySignedData(string $der, \DateTimeImmutable $signedAt): string
    {
        if (!function_exists('openssl_cms_verify')) {
            throw new JmhzTransportException(
                'jmhz_protocol_signature_unavailable',
                'Server nepodporuje ověření podpisu CMS/PKCS#7.',
            );
        }
        $input = self::tempPath('jmhz-protocol-sig-');
        $signers = self::tempPath('jmhz-protocol-signer-');
        try {
            if (file_put_contents($input, $der) === false) {
                throw new JmhzTransportException(
                    'jmhz_protocol_signature_unreadable',
                    'Podpis protokolu se nepodařilo připravit k ověření.',
                );
            }
            @chmod($input, 0600);
            // Nejdřív se rozhodne o TVARU podpisu. OpenSSL na odpojený podpis
            // bez dodaného obsahu i na nesmyslné bajty odpoví stejně („neplatí“),
            // takže by se skutečná příčina — že v podpisu žádný otisk není —
            // nedozvěděla. Právě ta je přitom celá otázka, kvůli které se
            // protokoly rozebíraly.
            $eContent = $this->encapsulatedContent($der);
            $verified = @openssl_cms_verify(
                $input,
                OPENSSL_CMS_BINARY | OPENSSL_CMS_NOVERIFY,
                $signers,
                [],
                null,
                null,
                null,
                null,
                OPENSSL_ENCODING_DER,
            );
            if ($verified !== true) {
                throw new JmhzTransportException(
                    'jmhz_protocol_signature_invalid',
                    'Podpis protokolu ČSSZ neplatí — obsah podpisu neodpovídá'
                        . ' podepsaným datům.',
                );
            }
            $this->assertTrustedSigner((string) @file_get_contents($signers), $signedAt);

            return $eContent;
        } finally {
            foreach ([$input, $signers] as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }
    }

    /**
     * Vložený obsah `SignedData`, tedy surové bajty otisku.
     *
     * Rozebírá se ručně, protože právě TVAR podpisu je to, co se z podkladů
     * nedalo vyčíst a muselo se zjistit z reálného protokolu: `eContentType`
     * je `pkcs7-data` a `eContent` je přítomný (attached) — 64 bajtů otisku
     * SHA-512, ne XML. Odpojený podpis by tady měl `encapContentInfo` bez
     * druhého prvku a nesmí se tvářit jako nečitelný vstup.
     */
    private function encapsulatedContent(string $der): string
    {
        try {
            $offset = 0;
            $nodes = Asn1::decode($der, $offset, strlen($der));
            $signedData = $nodes[0]['children'][1]['children'][0]['children'] ?? null;
            $encapsulated = is_array($signedData)
                ? ($signedData[2]['children'] ?? null)
                : null;
        } catch (\Throwable) {
            $encapsulated = null;
        }
        if (!is_array($encapsulated) || $encapsulated === []) {
            throw new JmhzTransportException(
                'jmhz_protocol_signature_unreadable',
                'Hodnota podpisu v protokolu není čitelná struktura PKCS#7/CMS.',
            );
        }
        $content = $encapsulated[1]['children'][0]['raw'] ?? null;
        if (!is_string($content) || $content === '') {
            throw new JmhzTransportException(
                'jmhz_protocol_signature_detached',
                'Podpis protokolu neobsahuje vložený otisk; doložený tvar je'
                    . ' vložený (attached) PKCS#7 s otiskem v obsahu.',
            );
        }

        return $content;
    }

    /**
     * Podepsat protokol smí jen ČSSZ. Kotvou je otisk připnutého certifikátu;
     * když se ve svazku podepisujících najde i vydavatel, ověří se navíc, že
     * kotva je jím skutečně vydaná — podvržený svazek tak neprojde ani tehdy,
     * kdyby otisk odněkud opsal.
     */
    private function assertTrustedSigner(string $signersPem, \DateTimeImmutable $signedAt): void
    {
        $certificates = self::splitPem($signersPem);
        if ($certificates === []) {
            throw new JmhzTransportException(
                'jmhz_protocol_signer_missing',
                'Podpis protokolu neobsahuje certifikát podepisujícího.',
            );
        }
        $expected = $this->trustAnchorFingerprint();
        $anchor = null;
        foreach ($certificates as $pem) {
            $certificate = @openssl_x509_read($pem);
            if ($certificate === false) {
                throw new JmhzTransportException(
                    'jmhz_protocol_signer_unreadable',
                    'Certifikát podepisujícího protokol nelze načíst.',
                );
            }
            $fingerprint = openssl_x509_fingerprint($certificate, 'sha256');
            if (is_string($fingerprint) && hash_equals($expected, strtolower($fingerprint))) {
                $anchor = $certificate;
            }
        }
        if ($anchor === null) {
            throw new JmhzTransportException(
                'jmhz_protocol_signer_untrusted',
                'Protokol podepsal certifikát, který není připnutým certifikátem'
                    . ' ČSSZ. Nedůvěryhodný protokol podání neposune.',
            );
        }
        $this->assertWithinValidity($anchor, $signedAt);
        $this->assertIssuedByBundledCa($anchor, $certificates, $expected);
    }

    /**
     * Otisk kotvy. Bere se ze SKUTEČNÉHO certifikátu v repozitáři, ne z
     * konstanty — připnutí tak platí i tehdy, když by soubor někdo vyměnil,
     * protože {@see JmhzCsszEncryption::certificate()} ho sám ověřuje.
     */
    private function trustAnchorFingerprint(): string
    {
        $pem = $this->trustAnchorPem ?? $this->anchor->certificate();
        $certificate = @openssl_x509_read($pem);
        if ($certificate === false) {
            throw new JmhzTransportException(
                'jmhz_protocol_anchor_unreadable',
                'Kotvu důvěry pro protokoly ČSSZ nelze načíst.',
            );
        }
        $fingerprint = openssl_x509_fingerprint($certificate, 'sha256');
        if (!is_string($fingerprint) || $fingerprint === '') {
            throw new JmhzTransportException(
                'jmhz_protocol_anchor_unreadable',
                'Z kotvy důvěry nelze spočítat otisk.',
            );
        }

        return strtolower($fingerprint);
    }

    private function assertWithinValidity(
        \OpenSSLCertificate $certificate,
        \DateTimeImmutable $signedAt,
    ): void {
        $parsed = openssl_x509_parse($certificate);
        if (!is_array($parsed)
            || !isset($parsed['validFrom_time_t'], $parsed['validTo_time_t'])
        ) {
            throw new JmhzTransportException(
                'jmhz_protocol_signer_unreadable',
                'Z certifikátu ČSSZ nelze přečíst dobu platnosti.',
            );
        }
        $at = $signedAt->getTimestamp();
        if ($at < (int) $parsed['validFrom_time_t']) {
            throw new JmhzTransportException(
                'jmhz_protocol_certificate_not_yet_valid',
                'Certifikát ČSSZ v době podpisu protokolu ještě neplatil.',
            );
        }
        if ($at > (int) $parsed['validTo_time_t']) {
            throw new JmhzTransportException(
                'jmhz_protocol_certificate_expired',
                'Certifikát ČSSZ v době podpisu protokolu už neplatil.',
            );
        }
    }

    /**
     * Okamžik podpisu z `TimeStamp`. Platnost certifikátu se posuzuje k němu,
     * ne k dnešku: kdyby se brala k dnešku, po výměně certifikátu ČSSZ by
     * přestaly platit i protokoly, které v den podpisu byly v pořádku.
     *
     * `TimeStamp` leží uvnitř podepsané části, takže se o něj opřít lze —
     * pozměněné datum by shodilo otisk. Časová zóna je UTC, ověřeno proti
     * skutečnému protokolu (čas příchodu 11:02 SELČ = `TimeStamp` 09:02).
     */
    private function signedAt(DOMElement $signature): \DateTimeImmutable
    {
        $date = '';
        $time = '';
        foreach ($signature->getElementsByTagNameNS(self::NS_TIMESTAMP, 'date') as $node) {
            $date = trim($node->textContent);

            break;
        }
        foreach ($signature->getElementsByTagNameNS(self::NS_TIMESTAMP, 'time') as $node) {
            $time = trim($node->textContent);

            break;
        }
        $stamp = \DateTimeImmutable::createFromFormat(
            'Ymd H:i:s',
            $date . ' ' . $time,
            new \DateTimeZone('UTC'),
        );
        if ($stamp === false) {
            throw new JmhzTransportException(
                'jmhz_protocol_timestamp_unreadable',
                'Podepsaná časová značka neuvádí čitelné datum a čas podpisu.',
            );
        }

        return $stamp;
    }

    /**
     * @param \OpenSSLCertificate $anchor
     * @param list<string> $certificates
     */
    private function assertIssuedByBundledCa(
        \OpenSSLCertificate $anchor,
        array $certificates,
        string $anchorFingerprint,
    ): void {
        $issuers = [];
        foreach ($certificates as $pem) {
            $candidate = @openssl_x509_read($pem);
            if ($candidate === false) {
                continue;
            }
            $fingerprint = openssl_x509_fingerprint($candidate, 'sha256');
            if (is_string($fingerprint) && hash_equals($anchorFingerprint, strtolower($fingerprint))) {
                continue;
            }
            $issuers[] = $candidate;
        }
        // Svazek nemusí vydavatele nést; kotva sama o sobě stačí. Když ho ale
        // nese, musí sedět — jinak je to podvržený svazek.
        if ($issuers === []) {
            return;
        }
        foreach ($issuers as $issuer) {
            $key = openssl_pkey_get_public($issuer);
            if ($key === false) {
                continue;
            }
            if (openssl_x509_verify($anchor, $key) === 1) {
                return;
            }
        }

        throw new JmhzTransportException(
            'jmhz_protocol_chain_broken',
            'Certifikát ČSSZ neodpovídá vydavateli přiloženému v podpisu.',
        );
    }

    /**
     * Znovu ověří, že serializovaný výstup nese tentýž podepsaný obsah.
     */
    private function assertRoundTripStable(string $xml, string $algorithm, string $digest): void
    {
        $dom = $this->load($xml);
        $message = $this->requireMessage($dom);
        if (!hash_equals($digest, hash($algorithm, (string) $message->C14N(), true))) {
            throw new JmhzTransportException(
                'jmhz_protocol_canonicalization_unstable',
                'Ověřený protokol po serializaci nedává tentýž otisk; vydat by'
                    . ' znamenalo předat parseru neověřené bajty.',
            );
        }
    }

    /**
     * `Class` z obálky GovTalk leží mimo podpis, takže se musí opřít o to, co
     * podepsané je. Bez téhle kontroly by šlo protokol vydávat za jiný druh
     * podání, aniž by se podpis porušil.
     */
    private function assertClassMatchesSignedContent(DOMDocument $dom, DOMElement $message): void
    {
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('g', JmhzGovTalkEnvelope::NS_GOVTALK);
        $class = trim(
            $xpath->query('//g:Header/g:MessageDetails/g:Class')?->item(0)?->textContent ?? '',
        );
        $result = $xpath->query(
            './/*[local-name()="ProcessingResult"]',
            $message,
        )?->item(0);
        if (!$result instanceof DOMElement) {
            throw new JmhzTransportException(
                'jmhz_protocol_signature_scope_unexpected',
                'Podepsaná část protokolu neobsahuje ProcessingResult.',
            );
        }
        $type = trim($result->getAttribute('type'));
        if ($class !== '' && $type !== '' && !hash_equals($type, $class)) {
            throw new JmhzTransportException(
                'jmhz_protocol_class_unsigned_mismatch',
                'Druh podání v obálce GovTalk neodpovídá podepsanému protokolu.',
            );
        }
    }

    private function requireMessage(DOMDocument $dom): DOMElement
    {
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('c', self::NS_CSSZ);
        $message = $xpath->query('//c:Message')?->item(0);
        if (!$message instanceof DOMElement) {
            throw new JmhzTransportException(
                'jmhz_protocol_signature_missing',
                'Protokol neobsahuje obálku ČSSZ `Message`, takže nemá co ověřit.'
                    . ' Podepsanou časovou značku nesou jen protokoly z kanálu VREP.',
            );
        }

        return $message;
    }

    private function requireSignature(DOMDocument $dom, DOMElement $message): DOMElement
    {
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('t', self::NS_TIMESTAMP);
        $signature = $xpath->query('./*/t:Signature', $message)?->item(0);
        if (!$signature instanceof DOMElement) {
            throw new JmhzTransportException(
                'jmhz_protocol_signature_missing',
                'Protokol ČSSZ neobsahuje podepsanou časovou značku.',
            );
        }

        return $signature;
    }

    private function digestAlgorithm(DOMElement $signature): string
    {
        $method = null;
        foreach ($signature->getElementsByTagNameNS(self::NS_TIMESTAMP, 'DigestMethod') as $node) {
            $method = $node;

            break;
        }
        if (!$method instanceof DOMElement) {
            throw new JmhzTransportException(
                'jmhz_protocol_digest_algorithm_unknown',
                'Podepsaná časová značka neuvádí algoritmus otisku.',
            );
        }
        $algorithm = self::DIGEST_ALGORITHMS[trim($method->getAttribute('Algorithm'))] ?? null;
        if ($algorithm === null) {
            throw new JmhzTransportException(
                'jmhz_protocol_digest_algorithm_unknown',
                'Algoritmus otisku v podepsané časové značce není doložený.',
            );
        }

        return $algorithm;
    }

    private function signatureValueNode(DOMElement $signature): DOMElement
    {
        foreach ($signature->getElementsByTagNameNS(self::NS_TIMESTAMP, 'SignatureValue') as $node) {
            return $node;
        }

        throw new JmhzTransportException(
            'jmhz_protocol_signature_missing',
            'Podepsaná časová značka neobsahuje hodnotu podpisu.',
        );
    }

    private function signatureValue(DOMElement $signature): string
    {
        $node = $this->signatureValueNode($signature);
        $encoded = preg_replace('/\s+/', '', $node->textContent) ?? '';
        if ($encoded === '') {
            throw new JmhzTransportException(
                'jmhz_protocol_signature_missing',
                'Hodnota podpisu v protokolu je prázdná.',
            );
        }
        $der = base64_decode($encoded, true);
        if (!is_string($der) || $der === '') {
            throw new JmhzTransportException(
                'jmhz_protocol_signature_unreadable',
                'Hodnota podpisu v protokolu není platný base64.',
            );
        }

        return $der;
    }

    /**
     * ⚠️ `LIBXML_NOBLANKS` NENÍ kosmetika. Podepsaný otisk sedí jen nad
     * dokumentem bez bílých míst; s ponechaným odsazením vyjde jiný a každé
     * ověření by skončilo neshodou otisku.
     */
    private function load(string $xml): DOMDocument
    {
        if (trim($xml) === '') {
            throw new JmhzTransportException(
                'jmhz_protocol_unreadable',
                'Protokol ČSSZ je prázdný.',
            );
        }
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            throw new JmhzTransportException(
                'jmhz_protocol_unreadable',
                'Protokol ČSSZ není platné XML.',
            );
        }

        return $dom;
    }

    /** @return list<string> */
    private static function splitPem(string $pem): array
    {
        if (preg_match_all(
            '/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s',
            $pem,
            $matches,
        ) < 1) {
            return [];
        }

        return array_values($matches[0]);
    }

    private static function tempPath(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        if ($path === false) {
            throw new JmhzTransportException(
                'jmhz_protocol_signature_unreadable',
                'Nelze připravit dočasný soubor pro ověření podpisu.',
            );
        }

        return $path;
    }
}
