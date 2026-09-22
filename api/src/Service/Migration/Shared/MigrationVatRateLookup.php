<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Tuzemská sazba DPH (`vat_rates.id`) pro položku dokladu převzatého z cizího účetního
 * programu - společné párování převodů z Money S3, POHODY, PREMIER a Stereo NX.
 *
 * Pravidlo: česká sazba (`country = 'CZ'`) s přesně tímtéž procentem, bez reverse-charge
 * řádků; přednost má řádek platný k datu (hranice `NULL` = neomezeno), mezi nimi výchozí
 * (`is_default`) a nejnižší id. Když k datu neplatí žádný, vezme se podle téhož pořadí
 * i řádek mimo platnost - historický doklad z let, pro která číselník řádek nevede
 * (CZ-21 má na stock instalaci `valid_from = 2024-01-01`), jinak převést nejde a
 * rozhodující procento stejně nese `vat_rate_snapshot` položky. `null` = sazba v číselníku
 * není; co s tím, rozhoduje volající (výjimka celého běhu, nebo přeskočení dokladu).
 *
 * Proč to NENÍ {@see \MyInvoice\Service\Vat\VatRateResolver}, přestože o sobě tvrdí, že je
 * jedinou cestou k `vat_rate_id`: na týchž vstupech může vrátit jiný řádek, takže přechod
 * by změnil převedené doklady.
 *  - Krok 1 resolveru vyžaduje `valid_from <= datum`; řádek s `valid_from IS NULL` bere
 *    převod jako platný, resolver ho odsune do kroku 2.
 *  - Pořadí mezi platnými: převod `is_default DESC, id`, resolver
 *    `is_default DESC, valid_from DESC, display_order, id`.
 *  - Pořadí mimo platnost: převod `is_default DESC, id`, resolver
 *    `valid_to IS NULL DESC, valid_from DESC, id` (výchozí řádek nepreferuje).
 *  - Procento: převod porovná `rate_percent` s procentem zaokrouhleným na 2 místa,
 *    resolver toleranci 0,005 - na hraně zaokrouhlení se mohou lišit.
 *  - Resolver vrací i varování „mimo platnost", převod ho do protokolu nedává.
 * Sjednocení na resolver je změna chování (jiné `vat_rate_id` u části historických
 * dokladů) a patří do samostatného kroku s regresním porovnáním na reálných datech.
 *
 * Výsledek se drží v paměti instance včetně nenalezených sazeb: převod do `vat_rates`
 * nezapisuje, takže opakovaný dotaz by vrátil totéž.
 */
final class MigrationVatRateLookup
{
    /** @var array<string,?int> klíč „21.00|2024-05-31" */
    private array $cache = [];

    private ?\PDOStatement $stmt = null;

    private ?\PDO $stmtPdo = null;

    public function __construct(private readonly Connection $db) {}

    public function find(float $rate, string $date): ?int
    {
        $percent = number_format($rate, 2, '.', '');
        $key = $percent . '|' . $date;
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }
        $stmt = $this->statement();
        $stmt->execute([$percent, $date, $date]);
        $id = $stmt->fetchColumn();
        return $this->cache[$key] = $id === false ? null : (int) $id;
    }

    private function statement(): \PDOStatement
    {
        $pdo = $this->db->pdo();
        if ($this->stmt === null || $this->stmtPdo !== $pdo) {
            $this->stmtPdo = $pdo;
            $this->stmt = $pdo->prepare(
                "SELECT id FROM vat_rates
                  WHERE country = 'CZ' AND rate_percent = ? AND is_reverse_charge = 0
                  ORDER BY ((valid_from IS NULL OR valid_from <= ?) AND (valid_to IS NULL OR valid_to >= ?)) DESC,
                           is_default DESC, id
                  LIMIT 1"
            );
        }
        return $this->stmt;
    }
}
