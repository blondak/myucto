<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Úplná company projekce globálního číselníku sazeb DPH. */
final class CompanyBackupVatRatesProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'code',
            'rate_percent',
            'country',
            'label_cs',
            'label_en',
            'is_default',
            'is_reverse_charge',
            'valid_from',
            'valid_to',
            'display_order',
        ];
    }
}
