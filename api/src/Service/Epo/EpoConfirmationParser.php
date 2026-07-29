<?php

declare(strict_types=1);

namespace MyInvoice\Service\Epo;

/**
 * Ověří podpis P7S/CMS a přečte veřejná metadata potvrzení EPO.
 *
 * Heslo pro epo_stav se záměrně nevrací ani neukládá. Ověření řetězce závisí na
 * CA úložišti hostitele; platnost kryptografického podpisu se kontroluje vždy.
 */
final class EpoConfirmationParser
{
    /**
     * @return array{
     *   signature_valid:bool,chain_valid:bool,reference:?string,submitted_at:?string,
     *   content_match:?bool,form_match:?bool,embedded_form_code:?string,
     *   confirmation_xml_sha256:?string,is_confirmation:bool,epo_signer_valid:bool
     * }
     */
    public function parse(string $path, string $expectedXml, string $expectedFormCode): array
    {
        $empty = [
            'signature_valid' => false,
            'chain_valid' => false,
            'reference' => null,
            'submitted_at' => null,
            'content_match' => null,
            'form_match' => null,
            'embedded_form_code' => null,
            'confirmation_xml_sha256' => null,
            'is_confirmation' => false,
            'epo_signer_valid' => false,
        ];
        if (!is_file($path) || !function_exists('openssl_cms_verify')) {
            return $empty;
        }

        $contentPath = $this->tempPath('epo-content-');
        $certPath = $this->tempPath('epo-certs-');
        try {
            $chainValid = $this->verifyCms($path, $contentPath, $certPath, false);
            $signatureValid = $chainValid;
            if (!$signatureValid) {
                $signatureValid = $this->verifyCms($path, $contentPath, $certPath, true);
            }
            if (!$signatureValid || !is_file($contentPath)) {
                return $empty;
            }

            $content = (string) file_get_contents($contentPath);
            $confirmation = $this->loadXml($content);
            if ($confirmation === null) {
                return array_merge($empty, [
                    'signature_valid' => true,
                    'chain_valid' => $chainValid,
                ]);
            }

            $xpath = new \DOMXPath($confirmation);
            $podani = $xpath->query('//*[local-name()="Podani"]')->item(0);
            $reference = $podani instanceof \DOMElement
                ? $this->attribute($podani, ['Cislo', 'cislo'])
                : null;
            $submittedAt = $podani instanceof \DOMElement
                ? $this->normalizeDate($this->attribute($podani, ['Datum', 'datum']))
                : null;

            $dataNode = $xpath->query('//*[local-name()="Data"]')->item(0);
            $embedded = $dataNode !== null ? $this->decodeData((string) $dataNode->textContent) : null;
            $embeddedXml = $embedded !== null ? $this->extractXml($embedded) : null;
            $embeddedFormCode = $embeddedXml !== null ? $this->formCode($embeddedXml) : null;

            $contentMatch = null;
            if ($embeddedXml !== null) {
                $contentMatch = hash_equals(hash('sha256', $expectedXml), hash('sha256', $embeddedXml));
                // Potvrzení kontrolního hlášení záměrně obsahuje redukovanou kopii;
                // přesný hash proto nelze porovnat, ale typ formuláře porovnat lze.
                if (!$contentMatch && $expectedFormCode === 'dphkh1' && $embeddedFormCode === 'dphkh1') {
                    $contentMatch = null;
                }
            }
            $formMatch = $embeddedFormCode !== null
                ? hash_equals(strtolower($expectedFormCode), $embeddedFormCode)
                : null;

            return [
                'signature_valid' => true,
                'chain_valid' => $chainValid,
                'reference' => $reference,
                'submitted_at' => $submittedAt,
                'content_match' => $contentMatch,
                'form_match' => $formMatch,
                'embedded_form_code' => $embeddedFormCode,
                'confirmation_xml_sha256' => hash('sha256', $content),
                'is_confirmation' => $reference !== null && $submittedAt !== null,
                // Důvěryhodný řetězec sám neprokazuje, že podepsala Finanční
                // správa. Bez spravovaného allowlistu EPO pečetí zůstává P7S
                // podkladem pro ruční doložení.
                'epo_signer_valid' => false,
            ];
        } finally {
            @unlink($contentPath);
            @unlink($certPath);
        }
    }

    private function verifyCms(string $input, string $content, string $certs, bool $skipChain): bool
    {
        @unlink($content);
        @unlink($certs);
        while (openssl_error_string() !== false) {
        }
        $flags = OPENSSL_CMS_BINARY | ($skipChain ? OPENSSL_CMS_NOVERIFY : 0);
        try {
            return openssl_cms_verify(
                $input,
                $flags,
                $certs,
                [],
                null,
                $content,
                null,
                null,
                OPENSSL_ENCODING_DER,
            );
        } catch (\Throwable) {
            return false;
        }
    }

    private function decodeData(string $value): ?string
    {
        $compact = preg_replace('/\s+/', '', $value) ?? '';
        if ($compact === '' || strlen($compact) % 2 !== 0 || !ctype_xdigit($compact)) {
            return null;
        }
        $decoded = hex2bin($compact);
        return $decoded !== false ? $decoded : null;
    }

    private function extractXml(string $bytes): ?string
    {
        if ($this->loadXml($bytes) !== null) {
            return $bytes;
        }

        $input = $this->tempPath('epo-inner-');
        $content = $this->tempPath('epo-inner-content-');
        $certs = $this->tempPath('epo-inner-certs-');
        try {
            file_put_contents($input, $bytes);
            if (!$this->verifyCms($input, $content, $certs, true) || !is_file($content)) {
                return null;
            }
            $xml = (string) file_get_contents($content);
            return $this->loadXml($xml) !== null ? $xml : null;
        } finally {
            @unlink($input);
            @unlink($content);
            @unlink($certs);
        }
    }

    private function formCode(string $xml): ?string
    {
        $dom = $this->loadXml($xml);
        if ($dom === null) {
            return null;
        }
        $known = ['dphdp3', 'dphkh1', 'dphshv', 'dpfdp5', 'dpfdp7', 'dppdp9', 'ossei1'];
        $xpath = new \DOMXPath($dom);
        foreach ($xpath->query('//*') ?: [] as $node) {
            $name = strtolower((string) $node->localName);
            if (in_array($name, $known, true)) {
                return $name;
            }
        }
        return null;
    }

    private function loadXml(string $xml): ?\DOMDocument
    {
        libxml_use_internal_errors(true);
        libxml_clear_errors();
        $dom = new \DOMDocument();
        $ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors(false);
        return $ok ? $dom : null;
    }

    /** @param list<string> $names */
    private function attribute(\DOMElement $element, array $names): ?string
    {
        foreach ($names as $name) {
            $value = trim($element->getAttribute($name));
            if ($value !== '') {
                return mb_substr($value, 0, 100);
            }
        }
        return null;
    }

    private function normalizeDate(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $timestamp = strtotime($value);
        return $timestamp !== false ? date('Y-m-d H:i:s', $timestamp) : null;
    }

    private function tempPath(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        if ($path === false) {
            throw new \RuntimeException('Nelze vytvořit dočasný soubor.');
        }
        return $path;
    }
}
