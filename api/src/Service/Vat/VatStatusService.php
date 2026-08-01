<?php

declare(strict_types=1);

namespace MyInvoice\Service\Vat;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Plátcovství DPH vlastní firmy K DATU (SSOT čtení supplier_vat_status_history).
 *
 * Historie (migrace 1120) je řada řádků {effective_from, is_vat_payer}; stav k datu D
 * = poslední řádek s effective_from <= D. Fallback na živý supplier.is_vat_payer kryje
 * legacy firmy bez řádku historie (baseline doplňuje migrace 1180, ale externě
 * založené řádky mimo aplikaci garantované nejsou).
 *
 * Živý sloupec supplier.is_vat_payer je derivovaná cache stavu "dnes" — pro jakékoli
 * rozhodování k datu dokladu/období se NESMÍ číst přímo; jediná správná cesta je
 * isVatPayerAt() (PHP) nebo payerAtExpr() (SQL joiny/agregace).
 */
final class VatStatusService
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** Byla firma plátcem DPH k danému datu (YYYY-MM-DD)? */
    public function isVatPayerAt(int $supplierId, string $date): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(
                (SELECT is_vat_payer FROM supplier_vat_status_history
                  WHERE supplier_id = ? AND effective_from <= ?
                  ORDER BY effective_from DESC, id DESC LIMIT 1),
                (SELECT is_vat_payer FROM supplier WHERE id = ?)
            )'
        );
        $stmt->execute([$supplierId, $date, $supplierId]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Korelovaný SQL výraz "plátce k datu" pro použití uvnitř větších dotazů.
     *
     * Všechny tři argumenty jsou SQL fragmenty vkládané doslova — volající ručí,
     * že nejde o neošetřený uživatelský vstup (typicky inline int supplier_id,
     * název datového sloupce a fallback literál/sloupec).
     */
    public static function payerAtExpr(string $supplierIdSql, string $dateSql, string $fallbackSql): string
    {
        return "COALESCE((SELECT h.is_vat_payer FROM supplier_vat_status_history h
                 WHERE h.supplier_id = {$supplierIdSql} AND h.effective_from <= {$dateSql}
                 ORDER BY h.effective_from DESC, h.id DESC LIMIT 1), {$fallbackSql})";
    }

    /**
     * Baseline řádek historie pro nově založenou firmu (effective_from 1900-01-01).
     *
     * Statická, aby ji mohly volat i bin skripty bez DI kontejneru; idempotentní
     * přes INSERT IGNORE (UNIQUE supplier_id + effective_from).
     */
    public static function seedInitialStatus(\PDO $pdo, int $supplierId, bool $isVatPayer): void
    {
        $pdo->prepare(
            'INSERT IGNORE INTO supplier_vat_status_history
                (supplier_id, effective_from, is_vat_payer, annual_deduction_percent)
             VALUES (?, \'1900-01-01\', ?, 100)'
        )->execute([$supplierId, $isVatPayer ? 1 : 0]);
    }
}
