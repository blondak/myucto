<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankPostingSuggestionRepository;

/**
 * Jediné místo, které říká „co ještě není zaúčtované" — a KAM na to uživatel klikne.
 *
 * „Doklady k doúčtování" jsou TŘI různé entity (vydané faktury, přijaté faktury,
 * bankovní pohyby) sečtené do jednoho čísla. Dokud si každý konzument počítal součet
 * po svém a proklik si zvolil taky po svém, číslo a jeho cíl se rozcházely: přehled
 * firem hlásil 7 a vedl na `/invoices?booked=0`, kde bylo 0 vydaných faktur, protože
 * všech 7 byly bankovní pohyby. Proto se počty i odkazy drží pohromadě — kdo zobrazí
 * číslo, dostane i seznam cílů, do kterých se rozpadá.
 *
 * Rozsah MUSÍ sedět 1:1 s filtrem cílové obrazovky (jinak karta slibuje akci, která
 * v seznamu není vidět):
 *   - vydané:  jen účtovatelné typy ({@see PostingService::POSTABLE_ISSUED_INVOICE_TYPES}),
 *              finalizované, `booked_at IS NULL` — shodně s InvoiceRepository `booked=0`;
 *   - přijaté: bez zálohových (§ zálohy se neúčtují, účtuje se inkaso a vyúčtování)
 *              — shodně s PurchaseInvoiceRepository `booked=0`;
 *   - banka:   pohyby BEZ živého zápisu v deníku (bankovní transakce nemají vlastní
 *              `booked_at`), tj. týž dotaz, který plní tab „K zaúčtování"
 *              ({@see BankPostingSuggestionRepository::unpostedCount()}). Stav návrhu
 *              je jen proxy a s realitou se rozchází.
 */
final class UnbookedDocumentsCounter
{
    public const LINK_INVOICES = '/invoices?booked=0';
    public const LINK_PURCHASE_INVOICES = '/purchase-invoices?booked=0';
    public const LINK_BANK = '/bank?tab=posting';

    public function __construct(private readonly Connection $db) {}

    /**
     * Rozpad po typech; typy s nulou se vynechávají, pořadí je stabilní
     * (vydané → přijaté → banka), takže první prvek je použitelný jako
     * primární cíl prokliku.
     *
     * @return list<array{key:string, count:int, link:string}>
     */
    public function breakdown(int $supplierId): array
    {
        $out = [];
        foreach ([
            ['invoices', $this->issuedInvoices($supplierId), self::LINK_INVOICES],
            ['purchase_invoices', $this->purchaseInvoices($supplierId), self::LINK_PURCHASE_INVOICES],
            ['bank', (new BankPostingSuggestionRepository($this->db))->unpostedCount($supplierId), self::LINK_BANK],
        ] as [$key, $count, $link]) {
            if ($count > 0) {
                $out[] = ['key' => $key, 'count' => $count, 'link' => $link];
            }
        }

        return $out;
    }

    /**
     * @param list<array{key:string, count:int, link:string}> $breakdown
     */
    public static function totalOf(array $breakdown): int
    {
        $total = 0;
        foreach ($breakdown as $b) {
            $total += $b['count'];
        }

        return $total;
    }

    private function issuedInvoices(int $supplierId): int
    {
        $types = PostingService::POSTABLE_ISSUED_INVOICE_TYPES;
        $placeholders = implode(',', array_fill(0, count($types), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM invoices
              WHERE supplier_id = ?
                AND booked_at IS NULL
                AND status NOT IN ('draft', 'cancelled')
                AND invoice_type IN ({$placeholders})"
        );
        $stmt->execute(array_merge([$supplierId], $types));

        return (int) $stmt->fetchColumn();
    }

    private function purchaseInvoices(int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM purchase_invoices
              WHERE supplier_id = ?
                AND booked_at IS NULL
                AND status NOT IN ('draft', 'cancelled')
                AND COALESCE(document_kind, 'invoice') <> 'advance'"
        );
        $stmt->execute([$supplierId]);

        return (int) $stmt->fetchColumn();
    }
}
