<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Soubory z inbox adresáře, jejichž koncept uživatel smazal (`purchase_invoice_inbox_dismissed`,
 * migrace 1960, issue #118).
 *
 * Soubory v inboxu po importu zůstávají a scanner je deduplikuje jen proti existujícím
 * fakturám. Bez téhle evidence by se smazaný koncept při dalším běhu cronu založil znovu.
 */
final class PurchaseInvoiceInboxDismissalRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Zapamatuje hashe souborů smazaného dokladu. Prázdné hodnoty přeskočí,
     * opakované smazání téhož souboru jen přepíše poslední záznam.
     *
     * @param list<string|null> $hashes
     */
    public function remember(int $supplierId, array $hashes, ?int $purchaseInvoiceId, ?string $vendorInvoiceNumber, ?int $userId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoice_inbox_dismissed
                    (supplier_id, sha256, purchase_invoice_id, vendor_invoice_number, dismissed_by)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE purchase_invoice_id = VALUES(purchase_invoice_id),
                    vendor_invoice_number = VALUES(vendor_invoice_number),
                    dismissed_by = VALUES(dismissed_by), dismissed_at = CURRENT_TIMESTAMP'
        );
        foreach (array_unique(array_filter($hashes, static fn ($h) => is_string($h) && strlen($h) === 64)) as $hash) {
            $stmt->execute([$supplierId, strtolower($hash), $purchaseInvoiceId, $vendorInvoiceNumber, $userId]);
        }
    }

    public function isDismissed(int $supplierId, string $sha256): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM purchase_invoice_inbox_dismissed WHERE supplier_id = ? AND sha256 = ? LIMIT 1'
        );
        $stmt->execute([$supplierId, strtolower($sha256)]);
        return $stmt->fetchColumn() !== false;
    }
}
