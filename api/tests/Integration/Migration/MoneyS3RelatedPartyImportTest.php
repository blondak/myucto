<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Service\Migration\MoneyS3\ImportOptions;
use MyInvoice\Tests\Fixtures\MoneyS3\SyntheticAgenda;
use PHPUnit\Framework\Attributes\Group;

/**
 * Spřízněné osoby z dávky dostanou příznak i tehdy, když je převod nezaložil, ale
 * spároval s kartou, která už ve firmě byla. Dřív ho dostal jen nově založený partner,
 * takže spojená osoba s existující kartou ze sestavy spojených osob tiše vypadla.
 */
#[Group('integration')]
final class MoneyS3RelatedPartyImportTest extends MoneyS3ImportTestCase
{
    public function testMatchedExistingPartnerIsMarkedAsRelatedParty(): void
    {
        $supplierId = $this->supplier();
        $vendorId = $this->client($supplierId, 'Dodavatel Alfa s.r.o.', SyntheticAgenda::VENDOR_ICO);
        $customerId = $this->client($supplierId, 'Odběratel Beta a.s.', SyntheticAgenda::CUSTOMER_ICO);
        $this->db->pdo()->prepare(
            "UPDATE clients SET related_party = 1, related_party_type = 'otherwise', related_party_note = 'shodný jednatel' WHERE id = ?"
        )->execute([$customerId]);

        $protocol = $this->importer->run($supplierId, $this->userId, $this->backup(), new ImportOptions(
            ImportOptions::MODE_IMPORT, true, null, [SyntheticAgenda::VENDOR_ICO, SyntheticAgenda::CUSTOMER_ICO],
        ));

        self::assertSame(['related_party' => 1, 'related_party_type' => 'capital', 'related_party_note' => null], $this->flag($vendorId));
        self::assertSame(
            ['related_party' => 1, 'related_party_type' => 'otherwise', 'related_party_note' => 'shodný jednatel'],
            $this->flag($customerId),
            'Karta už označená si ponechá typ vztahu i doložení.',
        );
        self::assertSame(1, $this->rowCount('clients', $supplierId, 'related_party = 1 AND ic = ' . $this->db->pdo()->quote(SyntheticAgenda::VENDOR_ICO)), 'Převod nezaložil druhou kartu.');
        $partners = array_column($protocol->toArray()['steps'], null, 'key')['partners'] ?? [];
        self::assertSame(1, $partners['counts']['related_marked'] ?? null, $this->explain($protocol));
    }

    private function client(int $supplierId, string $name, string $ico): int
    {
        $pdo = $this->db->pdo();
        $currency = $pdo->prepare('SELECT default_currency_id FROM supplier WHERE id = ?');
        $currency->execute([$supplierId]);
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, ic, street, city, zip, country_id, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, ?, "Vzorová 1", "Praha", "11000", ?, ?, 1, 1)'
        )->execute([$supplierId, $name, $ico, $this->czId, (int) $currency->fetchColumn()]);
        return (int) $pdo->lastInsertId();
    }

    /** @return array{related_party:int,related_party_type:?string,related_party_note:?string} */
    private function flag(int $clientId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT related_party, related_party_type, related_party_note FROM clients WHERE id = ?');
        $stmt->execute([$clientId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return [
            'related_party' => (int) $row['related_party'],
            'related_party_type' => $row['related_party_type'],
            'related_party_note' => $row['related_party_note'],
        ];
    }
}
