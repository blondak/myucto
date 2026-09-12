<?php

declare(strict_types=1);

namespace MyInvoice\Service\Integration;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Místní číselníky pro mapování konektoru: sklady, měny a jazyky firmy a sazby DPH.
 *
 * Výběr v editoru nabízí jen platné hodnoty, validace při uložení ale přijme
 * i neaktivní sklad, archivovaný jazyk nebo sazbu s ukončenou platností. Uložené
 * mapování se jinak rozbije ve chvíli, kdy firma hodnotu vyřadí z nabídky.
 */
final class IntegrationLocalLookups
{
    public function __construct(private readonly Connection $db) {}

    /** @return array<string, list<array{value:string,label:string,active:bool}>> */
    public function all(int $supplierId): array
    {
        return [
            'warehouses' => $this->warehouses($supplierId),
            'currencies' => $this->currencies($supplierId),
            'languages' => $this->languages($supplierId),
            'vat_rates' => $this->vatRates(false),
        ];
    }

    /** @return list<string> */
    public function values(int $supplierId, string $source): array
    {
        $rows = match ($source) {
            'warehouses' => $this->warehouses($supplierId),
            'currencies' => $this->currencies($supplierId),
            'languages' => $this->languages($supplierId),
            'vat_rates' => $this->vatRates(true),
            default => [],
        };
        return array_map(static fn (array $row): string => $row['value'], $rows);
    }

    /**
     * Výchozí místní hodnota číselníku pro předvyplnění mapování: výchozí
     * aktivní sklad a jazyk, měna firmy. Vždy jen hodnota z číselníků TÉTO
     * firmy, jinak null.
     */
    public function preferred(int $supplierId, string $source): ?string
    {
        $pdo = $this->db->pdo();
        if ($source === 'warehouses') {
            $stmt = $pdo->prepare('SELECT code FROM warehouses WHERE supplier_id = ? AND is_active = 1
                ORDER BY is_default DESC, name, code LIMIT 1');
            $stmt->execute([$supplierId]);
            $code = $stmt->fetchColumn();
            return $code === false ? null : (string) $code;
        }
        if ($source === 'languages') {
            $stmt = $pdo->prepare('SELECT code FROM stock_locales WHERE supplier_id = ? AND archived = 0
                ORDER BY is_default DESC, display_order, code LIMIT 1');
            $stmt->execute([$supplierId]);
            $code = $stmt->fetchColumn();
            return $code === false ? null : (string) $code;
        }
        if ($source === 'currencies') {
            // Kód výchozí měny firmy, pak její výchozí a aktivní měny. Kandidát
            // projde jen tehdy, když ho firma ve svém číselníku opravdu má.
            $stmt = $pdo->prepare('SELECT UPPER(c.code) FROM supplier s JOIN currencies c ON c.id = s.default_currency_id WHERE s.id = ?
                UNION ALL
                SELECT UPPER(code) FROM (SELECT code FROM currencies WHERE supplier_id = ? AND is_active = 1
                    ORDER BY is_default DESC, code) own');
            $stmt->execute([$supplierId, $supplierId]);
            $available = array_map(static fn (array $row): string => $row['value'],
                array_filter($this->currencies($supplierId), static fn (array $row): bool => $row['active']));
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $code) {
                if (in_array((string) $code, $available, true)) {
                    return (string) $code;
                }
            }
            return null;
        }
        return null;
    }

    /** @return list<array{value:string,label:string,active:bool}> */
    private function warehouses(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT code, name, is_active FROM warehouses
            WHERE supplier_id = ? ORDER BY is_active DESC, is_default DESC, name, code');
        $stmt->execute([$supplierId]);
        return array_map(static fn (array $row): array => [
            'value' => (string) $row['code'],
            'label' => $row['code'] . ' · ' . $row['name'],
            'active' => (bool) $row['is_active'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Měny firmy (bankovní číselník) a měny, ve kterých má zboží prodejní cenu.
     * Ceník e-shopu na bankovní účty vázaný není, proto obojí dohromady.
     *
     * @return list<array{value:string,label:string,active:bool}>
     */
    private function currencies(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT code, MAX(name) AS name, MAX(active) AS active FROM (
                SELECT code, name_cs AS name, is_active AS active FROM currencies WHERE supplier_id = ?
                UNION ALL
                SELECT currency_code, NULL, 1 FROM stock_item_prices WHERE supplier_id = ?
            ) c GROUP BY code ORDER BY MAX(active) DESC, code');
        $stmt->execute([$supplierId, $supplierId]);
        return array_map(static fn (array $row): array => [
            'value' => strtoupper((string) $row['code']),
            'label' => $row['name'] !== null ? strtoupper((string) $row['code']) . ' · ' . $row['name'] : strtoupper((string) $row['code']),
            'active' => (bool) $row['active'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array{value:string,label:string,active:bool}> */
    private function languages(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT code, name, archived FROM stock_locales
            WHERE supplier_id = ? ORDER BY archived, display_order, code');
        $stmt->execute([$supplierId]);
        return array_map(static fn (array $row): array => [
            'value' => (string) $row['code'],
            'label' => $row['code'] . ' · ' . $row['name'],
            'active' => !(bool) $row['archived'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array{value:string,label:string,active:bool}> */
    private function vatRates(bool $includeExpired): array
    {
        $sql = 'SELECT code, rate_percent, country, label_cs,
                (valid_from <= CURDATE() AND (valid_to IS NULL OR valid_to >= CURDATE())) AS active
            FROM vat_rates';
        if (!$includeExpired) {
            $sql .= ' WHERE valid_from <= CURDATE() AND (valid_to IS NULL OR valid_to >= CURDATE())';
        }
        $rows = $this->db->pdo()->query($sql . ' ORDER BY country, display_order, rate_percent DESC, code')
            ->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn (array $row): array => [
            'value' => (string) $row['code'],
            'label' => $row['country'] . ' · ' . $row['label_cs'] . ' (' . rtrim(rtrim((string) $row['rate_percent'], '0'), '.') . ' %)',
            'active' => (bool) $row['active'],
        ], $rows);
    }
}
