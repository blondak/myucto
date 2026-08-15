<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Transport;

/**
 * Odpojený (detached) podpis PKCS#7/CMS nad původním tvarem dat podání.
 *
 * Podací protokol ČSSZ (v1.7, kap. „Podepisování") je v tomhle jednoznačný:
 * *„Podpis musí být typu detached signature… Podepisují se původní data podání
 * PŘED komprimací, šifrováním a kódováním pro přenos, v podobě pole bytů."*
 *
 * Odsud plyne pořadí, které se nesmí zaměnit: **podepsat → gzip → zašifrovat →
 * base64**. Podepsat až zašifrovaná data by dalo podpis, který ČSSZ nemá jak
 * ověřit, protože ověřuje proti rozbalenému obsahu.
 *
 * Odpojený podpis znamená, že výsledek NEOBSAHUJE podepsaná data — jde do
 * `Message/Header/Signature` zvlášť, zatímco data jdou zašifrovaná do
 * `Message/Body`. Kdyby byl podpis připojený, poslali bychom obsah podání
 * dvakrát, podruhé nezašifrovaně.
 */
final class JmhzDetachedSigner
{
    /**
     * @param string $payload původní data podání (XML datové věty)
     * @param string $pfxBytes obsah PKCS#12 s kvalifikovaným certifikátem
     * @return string DER podpisu, připravený k zakódování do base64
     */
    public function sign(string $payload, string $pfxBytes, string $password): string
    {
        if ($payload === '') {
            throw new JmhzTransportException(
                'jmhz_signing_empty_payload',
                'Prázdné podání nelze podepsat.',
            );
        }
        if (!function_exists('openssl_cms_sign')) {
            throw new JmhzTransportException(
                'jmhz_signing_unavailable',
                'Server nepodporuje podpis CMS/PKCS#7.',
            );
        }
        $bundle = [];
        if (!@openssl_pkcs12_read($pfxBytes, $bundle, $password)) {
            throw new JmhzTransportException(
                'jmhz_signing_credential_unlock_failed',
                'Soukromý klíč podpisového certifikátu nelze otevřít.',
            );
        }
        $certificate = (string) ($bundle['cert'] ?? '');
        $privateKey = (string) ($bundle['pkey'] ?? '');
        if ($certificate === '' || $privateKey === '') {
            throw new JmhzTransportException(
                'jmhz_signing_private_key_missing',
                'Certifikát neobsahuje použitelný soukromý klíč.',
            );
        }
        // Řetěz certifikátů se přikládá, pokud v PKCS#12 je: ČSSZ ověřuje
        // proti akreditované autoritě a bez mezilehlého certifikátu by musela
        // řetěz dohledávat sama.
        $chain = $bundle['extracerts'] ?? [];

        $input = self::tempPath('jmhz-sign-in-');
        $output = self::tempPath('jmhz-sign-out-');
        $chainFile = null;
        try {
            if (file_put_contents($input, $payload) === false) {
                throw new JmhzTransportException(
                    'jmhz_signing_failed',
                    'Podání se nepodařilo připravit k podpisu.',
                );
            }
            @chmod($input, 0600);
            if (is_array($chain) && $chain !== []) {
                $chainFile = self::tempPath('jmhz-sign-chain-');
                file_put_contents($chainFile, implode("\n", $chain));
                @chmod($chainFile, 0600);
            }
            $ok = @openssl_cms_sign(
                $input,
                $output,
                $certificate,
                $privateKey,
                [],
                OPENSSL_CMS_BINARY | OPENSSL_CMS_DETACHED | OPENSSL_CMS_NOATTR,
                OPENSSL_ENCODING_DER,
                $chainFile,
            );
            $signature = $ok ? @file_get_contents($output) : false;
            if (!is_string($signature) || $signature === '') {
                throw new JmhzTransportException(
                    'jmhz_signing_failed',
                    'Podání se nepodařilo podepsat.',
                );
            }

            return $signature;
        } finally {
            foreach ([$input, $output, $chainFile] as $path) {
                if (is_string($path) && is_file($path)) {
                    @unlink($path);
                }
            }
        }
    }

    private static function tempPath(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        if ($path === false) {
            throw new JmhzTransportException(
                'jmhz_signing_failed',
                'Nelze připravit dočasný soubor pro podpis.',
            );
        }

        return $path;
    }
}
