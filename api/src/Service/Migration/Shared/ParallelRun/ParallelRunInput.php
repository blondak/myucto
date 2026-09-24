<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared\ParallelRun;

/**
 * Druhy výstupů starého programu, které kontrola souběhu umí přečíst, a kritéria,
 * ke kterým patří (číslování podle rekonciliačních kritérií převodu).
 */
final class ParallelRunInput
{
    public const TRIAL_BALANCE = 'trial_balance';
    public const DOCUMENT_COUNTS = 'document_counts';
    public const SALDO = 'saldo';
    public const BANK_BALANCES = 'bank_balances';
    public const VAT_RETURN = 'vat_return';
    public const CONTROL_STATEMENT = 'control_statement';
    public const ASSETS = 'assets';
    public const COST_CENTERS = 'cost_centers';
    public const BALANCE_SHEET = 'balance_sheet';
    public const INCOME_STATEMENT = 'income_statement';

    /** Záloha agendy (jen zdroje, které ji umí číst bez zápisu do MyÚčta). */
    public const BACKUP = 'backup';

    /** Druh výstupu => kritéria, která z něj vycházejí. */
    public const CRITERIA = [
        self::TRIAL_BALANCE => ['K1'],
        self::DOCUMENT_COUNTS => ['K5'],
        self::SALDO => ['K6', 'K7'],
        self::BANK_BALANCES => ['K8', 'K10'],
        self::VAT_RETURN => ['K9'],
        self::CONTROL_STATEMENT => ['K9'],
        self::ASSETS => ['K11'],
        self::COST_CENTERS => ['K12'],
        self::BALANCE_SHEET => ['K13'],
        self::INCOME_STATEMENT => ['K13'],
    ];

    /** Knihy dokladů kritéria K5. */
    public const BOOKS = ['issued_invoices', 'purchase_invoices', 'cash', 'bank', 'internal'];

    /** @return list<string> */
    public static function fileKinds(): array
    {
        return array_keys(self::CRITERIA);
    }

    /**
     * Kniha dokladů podle názvu ve sestavě („Faktury vydané", „FV", „Pokladna"…), null = neznámá.
     */
    public static function book(string $label): ?string
    {
        $f = DelimitedExport::fold($label);
        if ($f === '') {
            return null;
        }
        if (in_array($f, self::BOOKS, true)) {
            return $f;
        }
        $f = str_replace(' ', '_', $f);
        if (in_array($f, self::BOOKS, true)) {
            return $f;
        }
        $f = str_replace('_', ' ', $f);
        return match (true) {
            str_contains($f, 'vydan'), $f === 'fv', str_contains($f, 'issued') => 'issued_invoices',
            str_contains($f, 'prijat'), $f === 'fp', str_contains($f, 'received'), str_contains($f, 'purchase') => 'purchase_invoices',
            str_contains($f, 'poklad'), str_contains($f, 'cash') => 'cash',
            str_contains($f, 'bank'), str_contains($f, 'vypis') => 'bank',
            str_contains($f, 'intern'), str_contains($f, 'ostatni'), $f === 'id' => 'internal',
            default => null,
        };
    }
}
