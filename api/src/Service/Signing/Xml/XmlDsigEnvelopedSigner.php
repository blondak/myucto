<?php

declare(strict_types=1);

namespace MyInvoice\Service\Signing\Xml;

/**
 * Obalený (enveloped) podpis XML podle W3C XML Signature (XMLDSig-Core).
 *
 * Tvar odpovídá standardu ISDOC 6.0.2, kap. 5.1 „Požadavky na digitální podpis
 * v dokumentu ISDOC" (příklad 2), tedy prvnímu podpisu dokladu:
 *
 * - element `Signature` z jmenného prostoru `http://www.w3.org/2000/09/xmldsig#`
 *   je POSLEDNÍM prvkem kořene (`xs:any` na konci sekvence `Invoice` v XSD)
 *   a nese atribut `Id`;
 * - `Reference URI=""` (celý dokument) s transformacemi Enveloped Signature
 *   a XPath `not(ancestor-or-self::dsig:Signature)` — ta podle kap. 5.2 dovolí
 *   příjemci připojit další podpis, aniž by rozbil tenhle;
 *
 *   ⚠️ Prefix `dsig` se deklaruje na elementu `XPath`, NE na `Signature` jako
 *   v příkladu standardu. Ověřeno proti .NET `SignedXml`: deklaraci z `Signature`
 *   při sestavení kanonické podoby `SignedInfo` nepropaguje, takže podpis podle
 *   příkladu standardu by v .NET nikdy neplatil. Deklarace na `XPath` je platná
 *   pro libxml i .NET a filtr se vyhodnotí stejně;
 *
 *   ⚠️ .NET Framework (od MS16-035) transformaci XPath ve výchozím nastavení
 *   nepovoluje — ověřovatel na .NET bez úpravy politiky odmítne každý podpis
 *   s XPath, i formálně bezvadný. Proto jde filtr vypnout (`$xpathFilter`);
 *   samotná transformace Enveloped Signature je ve standardu povinná a stačí;
 * - kanonizace Canonical XML 1.0 (inkluzivní, bez komentářů), otisk SHA-256,
 *   podpis RSA-SHA256 (identifikátory dle RFC 4051, jak kap. 5.1 bod 5 žádá);
 * - `KeyInfo/X509Data/X509Certificate` s certifikátem podepisujícího a případným
 *   řetězcem z PKCS#12, aby příjemce (POHODA a další) ověřil kvalifikovaný certifikát.
 *
 * Otisk reference se počítá z dokumentu PŘED vložením podpisu. Výsledek transformací
 * u ověřovatele (dokument bez podstromu `Signature`) je s ním totožný, protože se
 * podpis vkládá bez okolních bílých znaků — žádný textový uzel nepřibude ani neubude.
 */
final class XmlDsigEnvelopedSigner
{
    public const NS_DSIG = 'http://www.w3.org/2000/09/xmldsig#';
    public const C14N = 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315';
    public const RSA_SHA256 = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';
    public const SHA256 = 'http://www.w3.org/2001/04/xmlenc#sha256';
    public const ENVELOPED = 'http://www.w3.org/2000/09/xmldsig#enveloped-signature';
    public const XPATH = 'http://www.w3.org/TR/1999/REC-xpath-19991116';
    public const XPATH_FIRST_SIGNATURE = 'not(ancestor-or-self::dsig:Signature)';

    public function __construct(private readonly bool $xpathFilter = true) {}

    /**
     * @param array{cert:string,pkey:string|\OpenSSLAsymmetricKey,extracerts?:list<string>} $credential
     *   odemčený certifikát ({@see \MyInvoice\Service\Signing\SigningCredentialUnlocker})
     */
    public function sign(string $xml, array $credential, string $signatureId = 'Signature-1'): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9._-]*$/D', $signatureId) !== 1) {
            throw new \InvalidArgumentException('Identifikátor podpisu musí být platné XML jméno.');
        }
        $dom = $this->load($xml);
        $root = $dom->documentElement;
        if (!$root instanceof \DOMElement) {
            throw new \RuntimeException('Podepisované XML nemá kořenový element.');
        }
        if ($dom->getElementsByTagNameNS(self::NS_DSIG, 'Signature')->length > 0) {
            // Další podpis by potřeboval jiný filtr XPath (ISDOC kap. 5.2);
            // náš výstup se podepisuje jen jednou, při vzniku.
            throw new \RuntimeException('Dokument už elektronický podpis obsahuje.');
        }

        $key = openssl_pkey_get_private($credential['pkey']);
        if ($key === false) {
            throw new \RuntimeException('Soukromý klíč certifikátu nelze načíst.');
        }
        $details = openssl_pkey_get_details($key);
        if (!is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
            throw new \RuntimeException('Podpis XML podporuje jen certifikáty s klíčem RSA.');
        }
        $certificates = [$this->certificateBase64($credential['cert'])];
        foreach ($credential['extracerts'] ?? [] as $extra) {
            $encoded = $this->certificateBase64((string) $extra);
            if (!in_array($encoded, $certificates, true)) {
                $certificates[] = $encoded;
            }
        }

        $digest = base64_encode(hash('sha256', $this->canonical($dom), true));

        $fragment = $dom->createDocumentFragment();
        if (!$fragment->appendXML($this->signatureSkeleton($signatureId, $digest, $certificates))) {
            throw new \RuntimeException('Element podpisu nelze sestavit.');
        }
        $root->appendChild($fragment);
        $signature = $root->lastChild;
        if (!$signature instanceof \DOMElement || $signature->namespaceURI !== self::NS_DSIG) {
            throw new \RuntimeException('Element podpisu se nepodařilo vložit.');
        }
        $signedInfo = $this->child($signature, 'SignedInfo');

        // SignedInfo se kanonizuje NA MÍSTĚ v dokumentu: inkluzivní C14N do něj
        // propíše jmenné prostory deklarované u předků, stejně jako u ověřovatele.
        $canonicalSignedInfo = $signedInfo->C14N(false, false);
        if (!is_string($canonicalSignedInfo) || $canonicalSignedInfo === '') {
            throw new \RuntimeException('SignedInfo nelze kanonizovat.');
        }
        $value = '';
        if (!openssl_sign($canonicalSignedInfo, $value, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Podpis XML se nepodařilo vytvořit.');
        }
        $publicKey = openssl_pkey_get_public($credential['cert']);
        if ($publicKey === false
            || openssl_verify($canonicalSignedInfo, $value, $publicKey, OPENSSL_ALGO_SHA256) !== 1
        ) {
            throw new \RuntimeException('Soukromý klíč neodpovídá certifikátu podepisujícího.');
        }
        $this->child($signature, 'SignatureValue')->textContent = base64_encode($value);

        // Podpis se vloží do PŮVODNÍCH bajtů před koncovou značku kořene, aby
        // reserializace DOM nezměnila zápis zbytku dokladu (např. prázdné elementy).
        $signatureXml = $dom->saveXML($signature);
        $close = strrpos($xml, '</');
        if (is_string($signatureXml) && $close !== false
            && preg_match('/^<\/[^<>]+>\s*$/D', substr($xml, $close)) === 1
        ) {
            return substr($xml, 0, $close) . $signatureXml . substr($xml, $close);
        }

        $out = $dom->saveXML();
        if (!is_string($out) || $out === '') {
            throw new \RuntimeException('Podepsané XML nelze serializovat.');
        }

        return $out;
    }

    private function load(string $xml): \DOMDocument
    {
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = true;
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $loaded = trim($xml) !== '' && $dom->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            throw new \RuntimeException('Podepisovaný dokument není platné XML.');
        }

        return $dom;
    }

    /**
     * Výsledek transformací Reference: celý dokument bez komentářů v Canonical XML 1.0.
     */
    private function canonical(\DOMDocument $dom): string
    {
        $canonical = $dom->C14N(false, false);
        if (!is_string($canonical) || $canonical === '') {
            throw new \RuntimeException('Dokument nelze kanonizovat.');
        }

        return $canonical;
    }

    /**
     * @param list<string> $certificates base64 DER
     */
    private function signatureSkeleton(string $signatureId, string $digest, array $certificates): string
    {
        $x509 = '';
        foreach ($certificates as $certificate) {
            $x509 .= '<X509Certificate>' . $certificate . '</X509Certificate>';
        }

        return '<Signature xmlns="' . self::NS_DSIG . '" Id="' . $signatureId . '">'
            . '<SignedInfo>'
            . '<CanonicalizationMethod Algorithm="' . self::C14N . '"/>'
            . '<SignatureMethod Algorithm="' . self::RSA_SHA256 . '"/>'
            . '<Reference URI="">'
            . '<Transforms>'
            . '<Transform Algorithm="' . self::ENVELOPED . '"/>'
            . ($this->xpathFilter
                ? '<Transform Algorithm="' . self::XPATH . '"><XPath xmlns:dsig="' . self::NS_DSIG . '">' . self::XPATH_FIRST_SIGNATURE . '</XPath></Transform>'
                : '')
            . '</Transforms>'
            . '<DigestMethod Algorithm="' . self::SHA256 . '"/>'
            . '<DigestValue>' . $digest . '</DigestValue>'
            . '</Reference>'
            . '</SignedInfo>'
            . '<SignatureValue></SignatureValue>'
            . '<KeyInfo><X509Data>' . $x509 . '</X509Data></KeyInfo>'
            . '</Signature>';
    }

    private function certificateBase64(string $pem): string
    {
        $der = '';
        if (preg_match('/-----BEGIN CERTIFICATE-----(.+?)-----END CERTIFICATE-----/s', $pem, $m) === 1) {
            $der = (string) base64_decode((string) preg_replace('/\s+/', '', $m[1]), true);
        }
        if ($der === '' || @openssl_x509_read($pem) === false) {
            throw new \RuntimeException('Certifikát podepisujícího nelze načíst.');
        }

        return base64_encode($der);
    }

    private function child(\DOMElement $parent, string $localName): \DOMElement
    {
        foreach ($parent->childNodes as $node) {
            if ($node instanceof \DOMElement && $node->namespaceURI === self::NS_DSIG && $node->localName === $localName) {
                return $node;
            }
        }

        throw new \RuntimeException("V podpisu chybí element {$localName}.");
    }
}
