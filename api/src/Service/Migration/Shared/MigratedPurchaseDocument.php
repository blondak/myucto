<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

/**
 * Hlavička přijatého dokladu (`purchase_invoices`) převzatého z cizího účetního programu.
 * Zapisuje ji {@see MigratedDocumentWriter}.
 *
 * Stejně jako u {@see MigratedIssuedDocument} musí zdroj vždy uvést `pricesIncludeVat`,
 * `reverseCharge`, měnu a kurz. Nepovinné parametry mají výchozí hodnotu shodnou
 * s DEFAULT sloupce v databázi.
 */
final class MigratedPurchaseDocument
{
    public function __construct(
        public readonly int $supplierId,
        public readonly int $vendorId,
        public readonly bool $vendorIsVatPayer,
        public readonly string $varsymbol,
        public readonly string $vendorInvoiceNumber,
        public readonly string $documentKind,
        public readonly string $issueDate,
        public readonly string $taxDate,
        public readonly string $dueDate,
        public readonly string $receivedAt,
        public readonly string $receivedAtSource,
        public readonly int $currencyId,
        public readonly ?float $exchangeRate,
        public readonly bool $pricesIncludeVat,
        public readonly bool $reverseCharge,
        public readonly string $vendorSnapshot,
        public readonly float $totalWithoutVat,
        public readonly float $totalVat,
        public readonly float $totalWithVat,
        public readonly float $rounding,
        public readonly string $status,
        public readonly string $vatDeduction,
        public readonly ?string $noteAboveItems,
        public readonly ?string $noteBelowItems,
        public readonly int $createdBy,
        public readonly float $advancePaidAmount = 0.0,
        public readonly ?string $paymentVariableSymbol = null,
        public readonly ?string $paymentConstantSymbol = null,
        public readonly ?string $paymentAccountNumber = null,
        public readonly ?string $paymentBankCode = null,
        public readonly string $paymentMethod = 'bank_transfer',
        public readonly ?string $paidAt = null,
        public readonly ?string $bookedAt = null,
        public readonly ?int $bookedBy = null,
        public readonly ?string $externalBarcode = null,
        public readonly ?string $vatClassificationCode = null,
        public readonly bool $isFixedAsset = false,
    ) {}
}
