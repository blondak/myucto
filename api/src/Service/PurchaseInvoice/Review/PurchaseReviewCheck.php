<?php

declare(strict_types=1);

namespace MyInvoice\Service\PurchaseInvoice\Review;

/**
 * Jeden důvod, proč přijatý doklad potřebuje kontrolu v okně Kontrola vytěžených
 * dokladů ({@see PurchaseInvoiceReviewNeeds}). Nový důvod (např. dimenze se
 * schvalováním) = nová implementace zařazená do PurchaseInvoiceReviewNeeds.
 */
interface PurchaseReviewCheck
{
    /** Strojový kód důvodu; frontend ho překládá (`purchase_invoice.review.reason.<kód>`). */
    public function reason(): string;

    /**
     * @param array<string,mixed> $invoice řádek dokladu (aspoň id, status, vendor_id, project_id, issue_date, tax_date)
     * @return array<string,mixed>|null podrobnosti pro okno kontroly; null = z tohoto důvodu kontrolu nepotřebuje
     */
    public function evaluate(int $supplierId, array $invoice): ?array;
}
