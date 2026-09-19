<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

/**
 * Výchozí text poznámky pod položkami na nově vystaveném dokladu (#79, migrace 1855).
 *
 * SSOT pro dvě otázky, které se jinak okopírují do každé cesty zvlášť:
 * 1. které sloupce `supplier` se k vyhodnocení musí načíst ({@see supplierColumns()}),
 * 2. jaký text z nich pro daný jazyk dokladu vyleze ({@see forLanguage()}).
 *
 * Text se drží podle JAZYKA dokladu (`invoices.language`), NE podle měny — česká
 * firma běžně fakturuje v eurech česky. Zrcadlo v TypeScriptu je
 * `web/src/pages/invoices/invoiceDefaultNote.ts`; obě strany musí odpovídat,
 * protože editor předvyplňuje v prohlížeči a API při POST bez klíče.
 *
 * Vypnutý přepínač znamená doslova dnešní chování: prázdný řetězec, i kdyby byly
 * texty v nastavení vyplněné.
 */
final class DefaultInvoiceNote
{
    /** Sloupec `supplier` s přepínačem předvyplňování. */
    public const ENABLED_COLUMN = 'default_note_below_items_enabled';

    /** Jazyk dokladu (`invoices.language`) → sloupec `supplier` s jeho textem. */
    public const COLUMNS = [
        'cs' => 'default_note_below_items_cs',
        'en' => 'default_note_below_items_en',
    ];

    /**
     * Sloupce, které musí SELECT dodavatele načíst, aby šlo text vyhodnotit.
     *
     * @return list<string>
     */
    public static function supplierColumns(): array
    {
        return array_merge([self::ENABLED_COLUMN], array_values(self::COLUMNS));
    }

    /**
     * Výchozí poznámka pro daný jazyk dokladu, nebo prázdný řetězec.
     *
     * Prázdno vrací i pro jazyk mimo {@see COLUMNS} — fallback na češtinu by
     * u případné budoucí jazykové mutace tiše podstrčil text v cizím jazyce.
     *
     * @param array<string, mixed>|null $supplier řádek `supplier` (stačí sloupce
     *                                            z {@see supplierColumns()})
     */
    public static function forLanguage(?array $supplier, ?string $language): string
    {
        if ($supplier === null || !(bool) ($supplier[self::ENABLED_COLUMN] ?? false)) {
            return '';
        }
        $column = self::COLUMNS[(string) $language] ?? null;
        if ($column === null) {
            return '';
        }

        return trim((string) ($supplier[$column] ?? ''));
    }
}
