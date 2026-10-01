<?php

declare(strict_types=1);

namespace MyInvoice\Service\PurchaseInvoice\Review;

/**
 * Jeden důvod, proč přijatý doklad potřebuje kontrolu v okně Kontrola vytěžených
 * dokladů ({@see PurchaseInvoiceReviewNeeds}). Nový důvod (např. dimenze se
 * schvalováním) = nová implementace zařazená do PurchaseInvoiceReviewNeeds.
 *
 * Vyhodnocuje se dávkou (seznam dokladů), aby si implementace načetla společná data
 * firmy jednou a ne pro každý řádek.
 */
interface PurchaseReviewCheck
{
    /** Strojový kód důvodu; frontend ho překládá (`purchase_invoice.review.reason.<kód>`). */
    public function reason(): string;

    /**
     * @param list<array<string,mixed>> $invoices řádky dokladů (aspoň id, status, document_kind,
     *                                            vendor_id, project_id, issue_date, tax_date)
     * @return array<int,array<string,mixed>> id dokladu => podrobnosti; doklad, který z tohoto
     *                                        důvodu kontrolu nepotřebuje, ve výsledku chybí
     */
    public function evaluateMany(int $supplierId, array $invoices): array;
}
