<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

/** Disk extensions for original purchase-invoice source formats. */
final class PurchaseInvoiceSourceFormat
{
    private const SOURCE_EXT = [
        'isdoc'          => 'isdoc',
        'isdocx'         => 'isdocx',
        'pdf'            => 'pdf',
        'pohoda_xml'     => 'xml',
        'idoklad_json'   => 'json',
        'fakturoid_json' => 'json',
    ];

    public static function extension(string $format): ?string
    {
        return self::SOURCE_EXT[$format] ?? null;
    }

    /** @return list<string> */
    public static function extensions(): array
    {
        return array_values(array_unique(self::SOURCE_EXT));
    }
}
