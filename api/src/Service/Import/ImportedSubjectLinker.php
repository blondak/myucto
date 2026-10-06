<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Service\Migration\Shared\PartnerIdentityMatcher;
use MyInvoice\Support\CompanyIdNormalizer;

/**
 * Napojení subjektu z online fakturačky (Fakturoid, iDoklad) na kartu, která už ve firmě
 * je - podle IČO, u subjektu bez IČO podle DIČ. Bez toho import zakládal druhou kartu
 * téhož dodavatele a kontrola duplicit přijatých faktur (`uq_pi_vendor_invoice` přes
 * `vendor_id`) pak propustila stejný doklad podruhé, když už přišel jinou cestou
 * (ruční zadání, AI vytěžení, převod z jiného programu).
 *
 * Karta se nepřepisuje, jen dostane externí ID a chybějící roli. Má-li karta už jiné
 * externí ID téhož zdroje (zdroj sám vede subjekt dvakrát), vrátí null a volající
 * založí kartu jako dřív - externí ID je na kartě unikátní.
 */
final class ImportedSubjectLinker
{
    private const EXTERNAL_ID_COLUMNS = ['fakturoid_id', 'idoklad_id'];

    public function __construct(
        private readonly Connection $db,
        private readonly ClientRepository $clients,
        private readonly PartnerIdentityMatcher $partners,
    ) {}

    public function linkExisting(
        int $supplierId,
        string $externalIdColumn,
        int $externalId,
        ?string $ic,
        ?string $dic,
        bool $isCustomer,
        bool $isVendor,
    ): ?int {
        if (!in_array($externalIdColumn, self::EXTERNAL_ID_COLUMNS, true)) {
            throw new \InvalidArgumentException("Neznámý sloupec externího ID: {$externalIdColumn}");
        }

        $clientId = $this->findExisting($supplierId, $ic, $dic);
        if ($clientId === null) {
            return null;
        }

        $stmt = $this->db->pdo()->prepare(
            "UPDATE clients SET {$externalIdColumn} = ? WHERE id = ? AND supplier_id = ? AND {$externalIdColumn} IS NULL"
        );
        $stmt->execute([$externalId, $clientId, $supplierId]);
        if ($stmt->rowCount() !== 1) {
            return null;
        }

        if ($isCustomer) $this->clients->markAsCustomer($clientId, $supplierId);
        if ($isVendor) $this->clients->markAsVendor($clientId, $supplierId);
        return $clientId;
    }

    private function findExisting(int $supplierId, ?string $ic, ?string $dic): ?int
    {
        $ico = PartnerIdentityMatcher::ico((string) $ic);
        if ($ico !== '') {
            return $this->partners->clientByIco($supplierId, $ico)['id'] ?? null;
        }

        $dicNormalized = CompanyIdNormalizer::dic($dic);
        if ($dicNormalized === null) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT id FROM clients
              WHERE supplier_id = ? AND archived_at IS NULL
                AND UPPER(REGEXP_REPLACE(dic, '[^A-Za-z0-9]', '')) = ?
           ORDER BY id LIMIT 1"
        );
        $stmt->execute([$supplierId, $dicNormalized]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }
}
