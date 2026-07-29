<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Ledger § 46 až § 46g ZDPH — oprava základu daně u nedobytné pohledávky (věřitel).
 *
 * Protějšek {@see Section74bCorrectionRepository} na vydané straně. Drží jednotlivé pohyby
 * (oprava / obnova po úhradě) per dotčené vydané plnění a období. „Čistá oprava" plnění
 * = Σ correction − Σ restoration; z ní {@see \MyInvoice\Service\Tax\BadDebt\Section46Service}
 * počítá deltu potřebnou v běžném období.
 */
final class Section46CorrectionRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Čistá dosud zaevidovaná oprava (Σ correction − Σ restoration) per invoice_id.
     * Kladná hodnota = daň na výstupu je aktuálně snížena o tuto částku.
     *
     * @param list<int> $invoiceIds
     * @return array<int,float> invoice_id => net corrected VAT
     */
    public function netCorrectedByInvoice(int $supplierId, array $invoiceIds): array
    {
        $invoiceIds = array_values(array_unique(array_map('intval', $invoiceIds)));
        if ($invoiceIds === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($invoiceIds), '?'));
        $sql =
            "SELECT invoice_id,
                    SUM(CASE WHEN movement = 'correction' THEN vat_amount ELSE -vat_amount END) AS net_corrected
               FROM vat_s46_corrections
              WHERE supplier_id = ? AND invoice_id IN ({$ph})
           GROUP BY invoice_id";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(array_merge([$supplierId], $invoiceIds));
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['invoice_id']] = round((float) $r['net_corrected'], 2);
        }
        return $out;
    }

    /**
     * Doklady s nenulovou čistou opravou — kandidáti na obnovu daně po úhradě (§ 46e).
     *
     * @return array<int,float> invoice_id => net corrected VAT
     */
    public function correctedInvoices(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT invoice_id,
                    SUM(CASE WHEN movement = 'correction' THEN vat_amount ELSE -vat_amount END) AS net_corrected
               FROM vat_s46_corrections
              WHERE supplier_id = ?
           GROUP BY invoice_id
             HAVING net_corrected <> 0"
        );
        $stmt->execute([$supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['invoice_id']] = round((float) $r['net_corrected'], 2);
        }
        return $out;
    }

    /** Právní důvod první evidované opravy dokladu — obnova ho dědí (§ 46e navazuje na § 46). */
    public function legalGroundFor(int $supplierId, int $invoiceId): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT legal_ground FROM vat_s46_corrections
              WHERE supplier_id = ? AND invoice_id = ? AND movement = 'correction'
           ORDER BY id LIMIT 1"
        );
        $stmt->execute([$supplierId, $invoiceId]);
        $v = $stmt->fetchColumn();

        return $v === false ? null : (string) $v;
    }

    /**
     * Součet oprav podle § 46 odst. 1 písm. f) (malá nedobytná pohledávka) za dlužníka
     * a kalendářní rok — podklad pro roční strop na dlužníka.
     */
    public function smallReceivableTotalForDebtor(int $supplierId, int $clientId, int $year): float
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(i.total_with_vat * c.unpaid_ratio), 0)
               FROM vat_s46_corrections c
               JOIN invoices i ON i.id = c.invoice_id
              WHERE c.supplier_id = ? AND i.client_id = ?
                AND c.movement = 'correction'
                AND c.legal_ground = 'small_receivable'
                AND c.period_year = ?"
        );
        $stmt->execute([$supplierId, $clientId, $year]);

        return round((float) $stmt->fetchColumn(), 2);
    }

    /** Zapíše jeden pohyb opravy § 46. */
    public function recordMovement(
        int $supplierId,
        int $invoiceId,
        int $year,
        int $month,
        string $movement,
        float $vatAmount,
        float $outputVat,
        float $unpaidRatio,
        string $legalGround,
        ?string $correctiveDocNumber,
        ?string $deliveredOn,
        ?string $note,
        ?int $createdBy,
    ): int {
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO vat_s46_corrections
                (supplier_id, invoice_id, period_year, period_month, movement,
                 vat_amount, output_vat, unpaid_ratio, legal_ground,
                 corrective_doc_number, delivered_on, note, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $supplierId, $invoiceId, $year, $month, $movement,
            round($vatAmount, 2), round($outputVat, 2), round($unpaidRatio, 6), $legalGround,
            $correctiveDocNumber, $deliveredOn, $note, $createdBy,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * Pohyby zaevidované v daném období (report / auditní náhled).
     *
     * @return list<array<string,mixed>>
     */
    public function movementsForPeriod(int $supplierId, int $year, int $month): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT * FROM vat_s46_corrections
              WHERE supplier_id = ? AND period_year = ? AND period_month = ?
           ORDER BY invoice_id, id"
        );
        $stmt->execute([$supplierId, $year, $month]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
