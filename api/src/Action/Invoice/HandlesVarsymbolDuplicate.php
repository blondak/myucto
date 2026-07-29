<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

/**
 * Sdílené rozpoznání kolize čísla dokladu na unikátním indexu varsymbolu —
 * vydané faktury `uq_inv_supplier_varsymbol`, přijaté `uq_pi_supplier_varsymbol`
 * (obě `(supplier_id, varsymbol)`).
 *
 * Generátor se duplicitám aktivně vyhýbá, ruční čísla se předem kontrolují —
 * tohle je poslední pojistka: místo holé 500 (PDOException) vrátí akce
 * srozumitelnou hlášku. Viz issue #85 (řešení 4) a #103 (paralela pro přijaté).
 */
trait HandlesVarsymbolDuplicate
{
    /**
     * Vrátí uživatelskou hlášku, pokud výjimka je porušení unique indexu na varsymbolu;
     * jinak null (volající má výjimku přehodit dál).
     */
    private static function varsymbolDuplicateMessage(\PDOException $e, ?string $varsymbol): ?string
    {
        $isUnique = $e->getCode() === '23000' || str_contains($e->getMessage(), '1062');
        if (!$isUnique || !str_contains($e->getMessage(), 'varsymbol')) {
            return null;
        }

        $vs = trim((string) ($varsymbol ?? ''));
        return $vs !== ''
            ? "Číslo '{$vs}' už u tohoto dodavatele existuje. Zvol jiné, nebo nech pole prázdné — vygeneruje se automaticky."
            : 'Doklad s tímto číslem už u dodavatele existuje.';
    }

    /**
     * Vrátí uživatelskou hlášku, pokud výjimka je porušení unique indexu
     * `uq_pi_vendor_invoice` (supplier_id, vendor_id, vendor_invoice_number, issue_date) —
     * tj. přijatá faktura se stejným dodavatelem, jeho číslem dokladu a datem vystavení
     * už v systému je. Bez tohoto záchytu vrací přesný duplikát holou 500 (PDOException).
     * Jinak null (volající má výjimku přehodit dál). Viz audit 0.10.
     */
    private static function vendorInvoiceDuplicateMessage(\PDOException $e, ?string $vendorInvoiceNumber): ?string
    {
        $isUnique = $e->getCode() === '23000' || str_contains($e->getMessage(), '1062');
        if (!$isUnique || !str_contains($e->getMessage(), 'uq_pi_vendor_invoice')) {
            return null;
        }

        $num = trim((string) ($vendorInvoiceNumber ?? ''));
        return $num !== ''
            ? "Přijatá faktura číslo '{$num}' od tohoto dodavatele s tímto datem vystavení už existuje."
            : 'Přijatá faktura od tohoto dodavatele s tímto číslem a datem vystavení už existuje.';
    }
}
