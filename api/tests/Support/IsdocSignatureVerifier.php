<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

/**
 * Nezávislé ověření podpisu ISDOC pro testy.
 *
 * Záměrně jde jinou cestou než podepisovač: otisk reference nepočítá z dokumentu
 * před vložením podpisu, ale VYHODNOCENÍM filtru XPath z podpisu nad podepsaným
 * dokumentem (tak, jak to dělá ověřovatel u příjemce), a podpis ověřuje veřejným
 * klíčem z `KeyInfo`. Navíc kontroluje tvar požadovaný standardem ISDOC 6.0.2
 * kap. 5.1 (poslední prvek kořene, algoritmy, transformace).
 */
final class IsdocSignatureVerifier
{
    use OpensslConfigTrait;

    private const NS_DSIG = 'http://www.w3.org/2000/09/xmldsig#';

    /**
     * Vrátí PEM certifikátu z KeyInfo; při jakékoli nesrovnalosti vyhodí výjimku.
     */
    public static function verify(string $xml): string
    {
        $dom = new \DOMDocument();
        if (!$dom->loadXML($xml, LIBXML_NONET)) {
            throw new \RuntimeException('Podepsaný ISDOC není well-formed XML.');
        }
        $root = $dom->documentElement;
        $last = $root?->lastChild;
        while ($last !== null && !$last instanceof \DOMElement) {
            $last = $last->previousSibling;
        }
        if (!$last instanceof \DOMElement || $last->namespaceURI !== self::NS_DSIG || $last->localName !== 'Signature') {
            throw new \RuntimeException('ds:Signature není posledním prvkem kořene.');
        }
        if ($dom->getElementsByTagNameNS(self::NS_DSIG, 'Signature')->length !== 1) {
            throw new \RuntimeException('Dokument nemá právě jeden podpis.');
        }

        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('ds', self::NS_DSIG);
        $one = static function (string $query) use ($xp, $last): string {
            $nodes = $xp->query($query, $last);
            if ($nodes === false || $nodes->length !== 1) {
                throw new \RuntimeException("V podpisu chybí {$query}.");
            }
            $node = $nodes->item(0);

            return $node instanceof \DOMAttr ? $node->value : trim((string) $node?->textContent);
        };

        $expect = [
            'ds:SignedInfo/ds:CanonicalizationMethod/@Algorithm' => 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315',
            'ds:SignedInfo/ds:SignatureMethod/@Algorithm' => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256',
            'ds:SignedInfo/ds:Reference/@URI' => '',
            'ds:SignedInfo/ds:Reference/ds:Transforms/ds:Transform[1]/@Algorithm' => 'http://www.w3.org/2000/09/xmldsig#enveloped-signature',
            'ds:SignedInfo/ds:Reference/ds:DigestMethod/@Algorithm' => 'http://www.w3.org/2001/04/xmlenc#sha256',
        ];
        foreach ($expect as $query => $value) {
            if ($one($query) !== $value) {
                throw new \RuntimeException("Neočekávaná hodnota {$query}.");
            }
        }
        if ($last->getAttribute('Id') === '') {
            throw new \RuntimeException('Podpis nemá atribut Id.');
        }

        // Reference: celý dokument bez podpisu (Enveloped Signature), případně
        // zúžený filtrem XPath z podpisu, v Canonical XML 1.0.
        $filter = 'not(ancestor-or-self::ds:Signature)';
        $prefixes = ['ds' => self::NS_DSIG];
        $xpathTransform = $xp->query('ds:SignedInfo/ds:Reference/ds:Transforms/ds:Transform[2]', $last)?->item(0);
        if ($xpathTransform instanceof \DOMElement) {
            if ($xpathTransform->getAttribute('Algorithm') !== 'http://www.w3.org/TR/1999/REC-xpath-19991116') {
                throw new \RuntimeException('Druhá transformace není XPath.');
            }
            $xpathElement = $xp->query('ds:XPath', $xpathTransform)?->item(0);
            if (!$xpathElement instanceof \DOMElement) {
                throw new \RuntimeException('Transformace XPath nemá výraz.');
            }
            $filter .= ' and (' . trim($xpathElement->textContent) . ')';
            foreach ($xp->query('namespace::*', $xpathElement) ?: [] as $ns) {
                if ($ns instanceof \DOMNameSpaceNode && $ns->localName !== 'xml' && $ns->prefix !== '') {
                    $prefixes[$ns->localName] = $ns->namespaceURI;
                }
            }
        }
        $referenced = $dom->C14N(false, false, [
            'query' => '(//. | //@* | //namespace::*)[' . $filter . ']',
            'namespaces' => $prefixes,
        ]);
        if (!is_string($referenced) || $referenced === '') {
            throw new \RuntimeException('Referenci nelze kanonizovat.');
        }
        $digest = base64_encode(hash('sha256', $referenced, true));
        if (!hash_equals($one('ds:SignedInfo/ds:Reference/ds:DigestValue'), $digest)) {
            throw new \RuntimeException('Otisk reference nesouhlasí — dokument byl po podpisu změněn.');
        }

        $certificate = "-----BEGIN CERTIFICATE-----\n"
            . chunk_split((string) preg_replace('/\s+/', '', $one('ds:KeyInfo/ds:X509Data/ds:X509Certificate[1]')), 64, "\n")
            . "-----END CERTIFICATE-----\n";
        $publicKey = openssl_pkey_get_public($certificate);
        if ($publicKey === false) {
            throw new \RuntimeException('Certifikát z KeyInfo nelze načíst.');
        }
        $signedInfo = $xp->query('ds:SignedInfo', $last)?->item(0);
        $canonical = $signedInfo?->C14N(false, false);
        $value = base64_decode((string) preg_replace('/\s+/', '', $one('ds:SignatureValue')), true);
        if (!is_string($canonical) || !is_string($value)
            || openssl_verify($canonical, $value, $publicKey, OPENSSL_ALGO_SHA256) !== 1
        ) {
            throw new \RuntimeException('Hodnota podpisu neplatí.');
        }

        return $certificate;
    }

    /**
     * Syntetický certifikát s RSA klíčem zabalený do PKCS#12.
     *
     * @return array{pfx:string,password:string,cert:string,pkey:string}
     */
    public static function syntheticPfx(string $commonName = 'ISDOC Test Signer'): array
    {
        $options = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'digest_alg' => 'sha256',
        ] + self::opensslConfigArgs();
        $key = openssl_pkey_new($options);
        $csr = $key === false ? false : openssl_csr_new(['commonName' => $commonName, 'countryName' => 'CZ'], $key, $options);
        $cert = $csr === false ? false : openssl_csr_sign($csr, null, $key, 365, $options);
        $password = 'test-' . bin2hex(random_bytes(4));
        $pfx = '';
        $certPem = '';
        $keyPem = '';
        if ($key === false || $cert === false
            || !openssl_pkcs12_export($cert, $pfx, $key, $password)
            || !openssl_x509_export($cert, $certPem)
            || !openssl_pkey_export($key, $keyPem, null, $options)
        ) {
            throw new \RuntimeException('Syntetický certifikát se nepodařilo vyrobit: ' . self::opensslErrors());
        }

        return ['pfx' => $pfx, 'password' => $password, 'cert' => $certPem, 'pkey' => $keyPem];
    }
}
