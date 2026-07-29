<?php

declare(strict_types=1);

namespace MyInvoice\Service\Epo;

use MyInvoice\Infrastructure\Config\RuntimePaths;

final class EpoSubmissionPayloadBuilder
{
    /** @param array<string,mixed> $submission */
    public function build(array $submission): string
    {
        $xml = (string) ($submission['xml_content'] ?? '');
        if ((string) ($submission['form_code'] ?? '') !== 'dphkh1') {
            return $xml;
        }
        if (!class_exists(\ZipArchive::class)) {
            throw new EpoSubmissionException(
                'zip_unavailable',
                'Server nepodporuje povinnou ZIP kompresi kontrolního hlášení.',
                503,
            );
        }

        $path = $this->tempPath();
        $zip = new \ZipArchive();
        $opened = false;
        try {
            $openResult = $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
            $opened = $openResult === true;
            if (!$opened || !$zip->addFromString('DPHKH1.xml', $xml)) {
                throw new EpoSubmissionException(
                    'zip_failed',
                    'Kontrolní hlášení se nepodařilo připravit pro EPO.',
                    500,
                );
            }
            if (!$zip->close()) {
                throw new EpoSubmissionException(
                    'zip_failed',
                    'Kontrolní hlášení se nepodařilo připravit pro EPO.',
                    500,
                );
            }
            $opened = false;
            $payload = file_get_contents($path);
            if (!is_string($payload) || !str_starts_with($payload, "PK\x03\x04")) {
                throw new EpoSubmissionException(
                    'zip_failed',
                    'Kontrolní hlášení se nepodařilo připravit pro EPO.',
                    500,
                );
            }
            return $payload;
        } finally {
            if ($opened) {
                @$zip->close();
            }
            @unlink($path);
        }
    }

    private function tempPath(): string
    {
        $dir = RuntimePaths::storage('tmp/epo');
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new EpoSubmissionException(
                'storage_not_writable',
                'Nelze vytvořit bezpečné dočasné úložiště.',
                500,
            );
        }
        $path = tempnam($dir, 'payload-');
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
