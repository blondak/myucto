<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

final class BankStatementSource
{
    /**
     * Zdroje bankovního výpisu (ne avíza). `import` = výpis převzatý z jiného účetního
     * systému (převod z Money S3) — nese pohyby i stav účtu jako výpis z banky.
     */
    private const STATEMENTS = ['gpc', 'pdf', 'bank_api', 'import'];

    /** Výpisy, jejichž konečný stav je autoritativní kotva zůstatku účtu. */
    private const BALANCE_ANCHORS = ['gpc', 'pdf', 'import'];

    public static function isStatement(string $source): bool
    {
        return in_array($source, self::STATEMENTS, true);
    }

    public static function isBalanceAnchor(string $source): bool
    {
        return in_array($source, self::BALANCE_ANCHORS, true);
    }

    public static function sqlList(): string
    {
        return "('" . implode("','", self::STATEMENTS) . "')";
    }
}
