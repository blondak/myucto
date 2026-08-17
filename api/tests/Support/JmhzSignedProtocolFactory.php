<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

/**
 * Vyrobí protokol ČSSZ s podepsanou časovou značkou tak, jak ji ČSSZ vydává.
 *
 * Podepisuje se SYNTETICKÝM certifikátem: skutečným klíčem ČSSZ nikdo podepsat
 * nemůže a reálný protokol do repozitáře nepatří — jsou v něm identifikátory
 * zaměstnavatele. Ověřuje se proto postup, ne vzorek. Kotva se ověřovateli
 * předá jako `trustAnchorPem`.
 *
 * Postup je shodný s podacím protokolem ČSSZ: kanonizace celého elementu
 * `Message` s VYPRÁZDNĚNÝM textem `SignatureValue`, otisk, a ten se vloží do
 * VLOŽENÉHO (attached) PKCS#7 jako obsah.
 */
final class JmhzSignedProtocolFactory
{
    use OpensslConfigTrait;

    public const SHA512 = 'http://www.w3.org/2001/04/xmlenc#sha512';

    /** @var list<string> */
    private array $tempFiles = [];

    /** @var array<string,array{certificate:\OpenSSLCertificate,key:\OpenSSLAsymmetricKey,pem:string}> */
    private array $issuers = [];

    public function __destruct()
    {
        $this->cleanUp();
    }

    public function cleanUp(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->tempFiles = [];
    }

    /** PEM kotvy, kterou se má protokol ověřovat. */
    public function anchorPem(string $commonName = 'DIS.CSSZ.TEST'): string
    {
        return $this->issuer($commonName)['pem'];
    }

    /**
     * @param string $protocolXml protokol s prázdným `<Header />` uvnitř
     *   `Message` — právě tam se podepsaná časová značka vkládá
     */
    public function sign(
        string $protocolXml,
        string $commonName = 'DIS.CSSZ.TEST',
        ?string $timestamp = null,
        bool $detached = false,
        string $algorithm = self::SHA512,
    ): string {
        // Syntetický certifikát platí ode dneška, takže výchozí značka musí
        // být „teď“ — jinak by ověření padalo na dosud neplatný certifikát.
        $timestamp ??= gmdate('Ymd H:i:s');
        [$date, $time] = explode(' ', $timestamp);
        $signature = '<Signature Version="1.0" xmlns="http://www.cssz.cz/emp/timestamp">'
            . '<DigestMethod Algorithm="' . $algorithm . '" />'
            . '<TimeStamp><date>' . $date . '</date><time>' . $time . '</time></TimeStamp>'
            . '<SignatureValue></SignatureValue>'
            . '</Signature>';
        $withSignature = str_replace(
            '<Header /><Body>',
            '<Header>' . $signature . '</Header><Body>',
            $protocolXml,
        );
        if ($withSignature === $protocolXml) {
            throw new \RuntimeException(
                'Vzorek protokolu nemá prázdnou hlavičku `<Header />`, kam se'
                    . ' podepsaná časová značka vkládá.',
            );
        }

        return str_replace(
            '<SignatureValue></SignatureValue>',
            '<SignatureValue>'
                . base64_encode($this->cms(
                    hash('sha512', $this->canonicalMessage($withSignature), true),
                    $commonName,
                    $detached,
                ))
                . '</SignatureValue>',
            $withSignature,
        );
    }

    private function canonicalMessage(string $xml): string
    {
        $dom = new \DOMDocument();
        if (!$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
            throw new \RuntimeException('Vzorek protokolu není platné XML.');
        }
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('c', 'http://www.cssz.cz/XMLSchema/envelope');
        $message = $xpath->query('//c:Message')?->item(0);
        if (!$message instanceof \DOMElement) {
            throw new \RuntimeException('Vzorek protokolu nemá obálku `Message`.');
        }

        return (string) $message->C14N();
    }

    private function cms(string $content, string $commonName, bool $detached): string
    {
        $issuer = $this->issuer($commonName);
        $input = $this->tempFile($content);
        $output = $this->tempFile('');
        $signed = openssl_cms_sign(
            $input,
            $output,
            $issuer['certificate'],
            $issuer['key'],
            [],
            $detached ? OPENSSL_CMS_BINARY | OPENSSL_CMS_DETACHED : OPENSSL_CMS_BINARY,
            OPENSSL_ENCODING_DER,
        );
        $der = $signed ? file_get_contents($output) : false;
        if (!is_string($der) || $der === '') {
            throw new \RuntimeException(
                'Protokol se nepodařilo podepsat: ' . self::opensslErrors(),
            );
        }

        return $der;
    }

    /** @return array{certificate:\OpenSSLCertificate,key:\OpenSSLAsymmetricKey,pem:string} */
    private function issuer(string $commonName): array
    {
        if (isset($this->issuers[$commonName])) {
            return $this->issuers[$commonName];
        }
        $options = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'digest_alg' => 'sha256',
        ] + self::opensslConfigArgs();

        $key = openssl_pkey_new($options);
        $csr = $key === false
            ? false
            : openssl_csr_new(
                ['commonName' => $commonName, 'countryName' => 'CZ'],
                $key,
                $options,
            );
        $certificate = $csr === false
            ? false
            : openssl_csr_sign($csr, null, $key, 3650, $options);
        $pem = '';
        if ($key === false
            || $certificate === false
            || !openssl_x509_export($certificate, $pem)
        ) {
            throw new \RuntimeException(
                'Syntetický certifikát se nepodařilo vyrobit: ' . self::opensslErrors(),
            );
        }

        return $this->issuers[$commonName] = [
            'certificate' => $certificate,
            'key' => $key,
            'pem' => $pem,
        ];
    }

    private function tempFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'jmhz-protocol-');
        if ($path === false) {
            throw new \RuntimeException('Nelze připravit dočasný soubor.');
        }
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return $path;
    }
}
