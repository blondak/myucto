<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Archiv generovaných EPO XML výkazů (DPHDP3, DPHKH1, DPHSHV, DPFDP7, DPPDP9).
 *
 * Asistované předání pouze otevře formulář EPO; konečné podání provádí uživatel.
 * Tabulka drží neměnný snapshot, na který navazují pokusy a důkazní dokumenty.
 */
final class TaxSubmissionRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Archivovat vygenerovaný XML. Vrátí ID záznamu.
     *
     * @param array<string,mixed> $summary
     * @param list<string> $validationErrors
     */
    public function archive(
        int $supplierId,
        string $formCode,
        int $year,
        ?int $month,
        ?int $quarter,
        string $xml,
        array $summary,
        string $validationStatus,
        array $validationErrors,
        ?int $generatedBy,
        string $variant = 'B',
        string $status = 'generated',
    ): int {
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO tax_submissions
                (supplier_id, form_code, period_year, period_month, period_quarter, form_variant,
                 xml_content, xml_size_bytes, xml_sha256,
                 validation_status, status, validation_errors, summary_json, generated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $supplierId, $formCode, $year, $month, $quarter, $variant,
            $xml, strlen($xml), hash('sha256', $xml),
            $validationStatus, $status,
            !empty($validationErrors) ? json_encode($validationErrors, JSON_UNESCAPED_UNICODE) : null,
            json_encode($summary, JSON_UNESCAPED_UNICODE),
            $generatedBy,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * Označí archivovaný snapshot jako PROKAZATELNĚ PODANÝ (audit §2.4). Teprve tento
     * stav (`submitted`) je základ pro opravné/následné tvrzení, posun daňového zámku,
     * označení "podáno" v UI a rekonciliaci "s podaným". Vygenerování ani stažení XML
     * podáním není.
     *
     * Idempotentní vůči datům: opakované volání jen přepíše metadata podání. Vrací
     * aktualizovaný řádek (vč. form/period pro rozhodnutí o zámku), nebo null když
     * záznam neexistuje / nepatří tenantovi.
     *
     * @return array<string,mixed>|null
     */
    public function markSubmitted(
        int $id,
        int $supplierId,
        string $submittedAt,
        ?string $submissionRef,
        ?int $submittedBy,
    ): ?array {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE tax_submissions
                SET status = 'submitted', submitted_at = ?, submission_ref = ?, submitted_by = ?
              WHERE id = ? AND supplier_id = ?"
        );
        $stmt->execute([$submittedAt, $submissionRef, $submittedBy, $id, $supplierId]);
        return $this->find($id, $supplierId);
    }

    /**
     * Poslední archivované podání pro dané období a druh(y) — základna dodatečného/následného
     * podání (C7'). Filtruje `form_variant IN (...)` (typicky řádné/opravné B/O) a vynechává
     * neplatná (`validation_status = 'failed'`) — z těch se rozdíl počítat nedá. Řadí dle
     * `generated_at DESC` (poslední skutečně podané = základna „poslední známé daně").
     *
     * @param list<string> $variants povolené druhy podání (např. ['B','O'])
     * @return array<string,mixed>|null celý řádek vč. xml_content a generated_at, nebo null
     */
    public function findLatestForPeriod(
        int $supplierId,
        string $formCode,
        int $year,
        ?int $month,
        ?int $quarter,
        array $variants,
    ): ?array {
        $variants = array_values(array_filter($variants, static fn ($v) => $v !== ''));
        if ($variants === []) {
            return null;
        }
        $ph = implode(',', array_fill(0, count($variants), '?'));
        // Období: měsíční (period_month) vs kvartální (period_quarter) — porovnáváme přesně
        // tu složku, která je vyplněná, druhá musí být NULL, ať se měsíc nespáruje s kvartálem.
        $periodSql = $month !== null
            ? 'period_month = ? AND period_quarter IS NULL'
            : 'period_month IS NULL AND period_quarter = ?';
        // Audit §2.4: základnou opravného/následného tvrzení smí být JEN prokazatelně
        // PODANÝ snapshot. `accepted` je vzdáleně potvrzený podstav `submitted`,
        // ne důvod vyřadit snapshot z daňového řetězce.
        $sql =
            "SELECT * FROM tax_submissions
              WHERE supplier_id = ? AND form_code = ? AND period_year = ?
                AND {$periodSql}
                AND form_variant IN ({$ph})
                AND status IN ('submitted','accepted')
                AND validation_status <> 'failed'
           ORDER BY submitted_at DESC, generated_at DESC, id DESC
              LIMIT 1";
        $params = array_merge(
            [$supplierId, $formCode, $year, $month !== null ? $month : $quarter],
            $variants,
        );
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->normalize($row) : null;
    }

    /**
     * Všechna DODATEČNÁ přiznání (druhy D/E) téhož období, chronologicky (generated_at ASC) —
     * pro rekonstrukci kumulativní „poslední známé daně" u 2.+ dodatečného přiznání (C7').
     * Vrací i `xml_content` (nese ROZDÍLY oproti předchozímu stavu). Vynechává neplatná
     * (`validation_status = 'failed'`).
     *
     * @return list<array<string,mixed>>
     */
    public function findAmendmentsForPeriod(
        int $supplierId,
        string $formCode,
        int $year,
        ?int $month,
        ?int $quarter,
    ): array {
        $periodSql = $month !== null
            ? 'period_month = ? AND period_quarter IS NULL'
            : 'period_month IS NULL AND period_quarter = ?';
        // Audit §2.4: kumulativní řetězec staví jen na prokazatelně PODANÝCH dodatečných.
        // Přijetí EPO (`accepted`) jejich důkazní sílu dále zvyšuje.
        $sql =
            "SELECT * FROM tax_submissions
              WHERE supplier_id = ? AND form_code = ? AND period_year = ?
                AND {$periodSql}
                AND form_variant IN ('D','E')
                AND status IN ('submitted','accepted')
                AND validation_status <> 'failed'
           ORDER BY submitted_at ASC, generated_at ASC, id ASC";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId, $formCode, $year, $month !== null ? $month : $quarter]);
        return array_map(fn ($r) => $this->normalize($r), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Seznam záznamů per tenant. Vrátí bez `xml_content` (jen metadata) pro list view.
     *
     * @return list<array<string,mixed>>
     */
    public function list(int $supplierId, int $limit = 100): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, supplier_id, form_code, period_year, period_month, period_quarter, form_variant,
                    xml_size_bytes, xml_sha256, validation_status,
                    status, submitted_at, submission_ref, submitted_by,
                    validation_errors, summary_json, generated_by, generated_at, notes
               FROM tax_submissions
              WHERE supplier_id = ?
           ORDER BY generated_at DESC
              LIMIT ?"
        );
        $stmt->execute([$supplierId, $limit]);
        return array_map(fn ($r) => $this->normalize($r), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function find(int $id, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM tax_submissions WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->normalize($row) : null;
    }

    public function delete(int $id, int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM tax_submissions WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    private function normalize(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['supplier_id'] = (int) $row['supplier_id'];
        $row['period_year'] = (int) $row['period_year'];
        $row['period_month'] = $row['period_month'] !== null ? (int) $row['period_month'] : null;
        $row['period_quarter'] = $row['period_quarter'] !== null ? (int) $row['period_quarter'] : null;
        if (isset($row['form_variant'])) {
            $row['form_variant'] = (string) $row['form_variant'];
        }
        if (array_key_exists('submitted_by', $row)) {
            $row['submitted_by'] = $row['submitted_by'] !== null ? (int) $row['submitted_by'] : null;
        }
        $row['xml_size_bytes'] = (int) $row['xml_size_bytes'];
        if (isset($row['summary_json']) && $row['summary_json'] !== null) {
            $row['summary'] = json_decode((string) $row['summary_json'], true) ?: null;
            unset($row['summary_json']);
        }
        if (isset($row['validation_errors']) && $row['validation_errors'] !== null) {
            $row['validation_errors'] = json_decode((string) $row['validation_errors'], true) ?: [];
        } else {
            $row['validation_errors'] = [];
        }
        return $row;
    }
}
