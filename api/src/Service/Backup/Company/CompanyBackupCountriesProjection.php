<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Úplná company projekce globálního číselníku zemí. */
final class CompanyBackupCountriesProjection
{
    /** @return list<string> */
    public static function dataColumns(): array
    {
        return [
            'id',
            'iso2',
            'iso3',
            'name_cs',
            'name_en',
            'is_eu',
        ];
    }
}
