<?php

declare(strict_types=1);

namespace MyInvoice\Service\Signing;

use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Pdf\SigningConfig;

/**
 * Odemčení podpisového certifikátu (PKCS#12) z profilu.
 *
 * Jediné místo, kde se heslo k certifikátu dešifruje a otevírá `.p12`/`.pfx`
 * pro podpis výstupů dokladu: PAdES podpis PDF ({@see \MyInvoice\Service\Pdf\PdfSigner})
 * i XML podpis ISDOC ({@see \MyInvoice\Service\Signing\Xml\XmlDsigEnvelopedSigner}).
 * Obě cesty tak berou klíč ze stejného zdroje a nemůžou se rozejít v tom,
 * kterým certifikátem doklad podepisují.
 *
 * Heslo se dešifruje až tady a v paměti se nedrží déle než po dobu volání.
 */
final class SigningCredentialUnlocker
{
    public function __construct(private readonly SecretEncryption $secrets) {}

    /**
     * @return array{cert:string,pkey:string,extracerts?:list<string>}
     */
    public function unlock(SigningConfig $cfg): array
    {
        $password = $this->secrets->decrypt($cfg->passwordEnc);
        $p12 = $cfg->certBytes ?? @file_get_contents($cfg->certPath);
        if (!is_string($p12) || $p12 === '') {
            throw new \RuntimeException('Certifikát nelze načíst: ' . $cfg->certPath);
        }
        $certs = [];
        if (!openssl_pkcs12_read($p12, $certs, $password)) {
            throw new \RuntimeException('P12 nelze otevřít (špatné heslo nebo poškozený soubor).');
        }
        if (!is_string($certs['cert'] ?? null) || $certs['cert'] === ''
            || !is_string($certs['pkey'] ?? null) || $certs['pkey'] === ''
        ) {
            throw new \RuntimeException('Certifikát neobsahuje použitelný soukromý klíč.');
        }

        return $certs;
    }
}
