<?php

declare(strict_types=1);

namespace MyInvoice\Service\Epo;

use MyInvoice\Infrastructure\Config\RuntimePaths;

final class EpoPkcs7Signer
{
    public function sign(string $payload, string $pfxBytes, string $password): string
    {
        if (!function_exists('openssl_cms_sign')) {
            throw new EpoSubmissionException(
                'openssl_unavailable',
                'Server nepodporuje podpis CMS/PKCS#7.',
                503,
            );
        }
        $bundle = [];
        if (!@openssl_pkcs12_read($pfxBytes, $bundle, $password)) {
            throw new EpoSubmissionException(
                'credential_unlock_failed',
                'Soukromý klíč nelze otevřít.',
                500,
            );
        }
        $certificate = (string) ($bundle['cert'] ?? '');
        $privateKey = (string) ($bundle['pkey'] ?? '');
        if ($certificate === '' || $privateKey === '') {
            throw new EpoSubmissionException(
                'private_key_missing',
                'Certifikát neobsahuje použitelný soukromý klíč.',
                500,
            );
        }

        $input = $this->tempPath('input-');
        $output = $this->tempPath('signed-');
        try {
            if (file_put_contents($input, $payload) === false) {
                throw new EpoSubmissionException(
                    'signing_failed',
                    'Nelze připravit podání k podpisu.',
                    500,
                );
            }
            @chmod($input, 0600);
            $ok = @openssl_cms_sign(
                $input,
                $output,
                $certificate,
                $privateKey,
                [],
                OPENSSL_CMS_BINARY,
                OPENSSL_ENCODING_DER,
            );
            if (!$ok || !is_file($output)) {
                throw new EpoSubmissionException(
                    'signing_failed',
                    'Elektronický podpis se nepodařilo vytvořit.',
                    500,
                );
            }
            $signed = file_get_contents($output);
            if (!is_string($signed) || $signed === '') {
                throw new EpoSubmissionException(
                    'signing_failed',
                    'Elektronický podpis je prázdný.',
                    500,
                );
            }
            return $signed;
        } finally {
            @unlink($input);
            @unlink($output);
        }
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
