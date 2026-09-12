<?php

declare(strict_types=1);

namespace MyInvoice\Service\Shoptet;

/** Rozpozná formát exportu objednávek (XML / CSV) a předá ho správnému parseru. */
final class ShoptetOrderFileParser
{
    public const MAX_BYTES = 20 * 1024 * 1024;

    public function __construct(
        private readonly ShoptetOrderXmlParser $xml,
        private readonly ShoptetOrderCsvParser $csv,
    ) {}

    /**
     * @return array{format:string, orders:list<array<string,mixed>>, errors:list<array{ref:string,message:string}>}
     */
    public function parse(string $content, ?string $fileName = null): array
    {
        if ($content === '') {
            throw new ShoptetImportException('shoptet_file_empty', 'Soubor je prázdný.');
        }
        if (strlen($content) > self::MAX_BYTES) {
            throw new ShoptetImportException('shoptet_file_too_large', 'Soubor je větší než 20 MB.');
        }
        $trimmed = ltrim(preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content);
        $ext = strtolower(pathinfo((string) $fileName, PATHINFO_EXTENSION));
        $isXml = str_starts_with($trimmed, '<') || $ext === 'xml';
        if ($isXml) {
            return ['format' => 'xml'] + $this->xml->parse($trimmed);
        }

        return ['format' => 'csv'] + $this->csv->parse($content);
    }
}
