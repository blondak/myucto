<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollAnnualDocumentRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementResult;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use PDO;

/**
 * Zmrazí výsledek ročního zúčtování do neměnné roční revize.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Proč se nestaví druhý mechanismus
 * ─────────────────────────────────────────────────────────────────────────────
 * `payroll_annual_document_revisions` už existují (migrace 1265) a jejich CHECK
 * hodnotu `annual_settlement_result` povoluje od začátku — jen ji dosud nikdo
 * nezapsal. Zdroje se přitom vážou přes `payroll_annual_document_sources` na
 * konkrétní schválené mzdové revize a trigger v databázi ověřuje, že jde
 * SKUTEČNĚ o nejnovější schválenou revizi daného měsíce. Přesně to roční
 * zúčtování potřebuje: § 38ch odst. 4 ho staví na úhrnu mezd za celý rok, takže
 * musí být dohledatelné, ze kterých měsíců vzniklo.
 *
 * Manifest zdrojů navíc dělá idempotenci zadarmo. Kdyby se zúčtování spustilo
 * podruhé nad týmiž měsíci, vyjde stejný `source_manifest_hash` a unikátní klíč
 * `uq_payroll_annual_revision_source` vrátí PŮVODNÍ revizi místo založení další.
 * Do manifestu proto patří i otisk výsledku výpočtu — kdyby se změnila jen
 * evidence nároků a měsíce zůstaly, musí to být jiný manifest, ne tichý návrat
 * starého čísla.
 */
final class AnnualSettlementSnapshotBuilder
{
    public const SCHEMA_VERSION = AnnualSettlementResult::SCHEMA_VERSION;
    public const PURPOSE = 'annual_settlement_result';
    public const MAPPING_VERSION = 'annual-settlement-mapping.v1';

    /**
     * Doména klíčovaného otisku snapshotu. Je veřejná proto, že revizi čte
     * i mzdový list (§ 38j odst. 2 písm. h) a otisk musí ověřovat POD TOUTÉŽ
     * doménou, pod kterou vznikl — jinak mu žádná skutečná revize neprojde.
     */
    public const SNAPSHOT_FINGERPRINT_DOMAIN = 'annual-settlement-snapshot-v1';

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollAnnualDocumentRepository $annualRevisions,
        private readonly PayrollSensitiveData $sensitiveData,
        private readonly SecretEncryption $encryption,
    ) {}

    /**
     * @param list<array{label:string,amount_minor_units:int}> $creditRows
     * @param list<array{label:string,months:int,amount_minor_units:int}> $childRows
     * @return array{
     *   revision:array<string,mixed>,
     *   document:AnnualSettlementDocumentData,
     *   created:bool
     * }
     */
    public function build(
        int $supplierId,
        int $employeeId,
        int $taxYear,
        AnnualSettlementResult $result,
        string $settledOn,
        array $creditRows,
        array $childRows,
        ?int $actorUserId,
    ): array {
        if (!$this->db->pdo()->inTransaction()) {
            throw new \LogicException(
                'Snapshot ročního zúčtování vyžaduje aktivní transakci.',
            );
        }
        if (!$result->performed) {
            throw new \LogicException(
                'Neprovedené roční zúčtování se do dokladu nezmrazuje.',
            );
        }

        $sources = $this->annualRevisions->lockApprovedYearSources(
            $supplierId,
            $employeeId,
            $taxYear,
        );
        if ($sources === []) {
            throw new \DomainException(
                'Roční zúčtování nelze doložit bez schváleného mzdového výsledku v daném roce.',
            );
        }
        $manifestSources = [];
        foreach ($sources as $source) {
            $manifestSources[] = [
                'period_start' => (string) $source['period_start'],
                'run_id' => (int) $source['run_id'],
                'revision_id' => (int) $source['revision_id'],
                'result_snapshot_hash' => (string) $source['result_snapshot_hash'],
                'person_result_hash' => (string) $source['person_result_hash'],
            ];
        }

        $profile = $this->profileSnapshot($supplierId, $employeeId, $taxYear);
        $employer = $this->employerSnapshot($supplierId);

        $snapshot = [
            'schema_version' => self::SCHEMA_VERSION,
            'tax_year' => $taxYear,
            'settled_on' => $settledOn,
            'employer' => $employer,
            'employee' => $profile,
            'result' => $result->jsonSerialize(),
            'credit_rows' => $creditRows,
            'child_rows' => $childRows,
        ];
        $snapshotJson = CanonicalJson::encode($snapshot);
        $snapshotHash = $this->snapshotFingerprint($snapshotJson, $supplierId);

        $manifest = [
            'schema_version' => 'payroll-annual-source-manifest.v1',
            'document_schema_version' => self::SCHEMA_VERSION,
            'mapping_version' => self::MAPPING_VERSION,
            'purpose' => self::PURPOSE,
            'tax_year' => $taxYear,
            'employee_id' => $employeeId,
            // Otisk výsledku patří do manifestu: dva různé výsledky nad týmiž
            // měsíci musí být dvě různé revize, ne jedna přehraná.
            'result_hash' => hash('sha256', CanonicalJson::encode($result->jsonSerialize())),
            'sources' => $manifestSources,
        ];
        $manifestJson = CanonicalJson::encode($manifest);
        $manifestHash = hash('sha256', $manifestJson);

        $existing = $this->annualRevisions->findBySourceManifest(
            $supplierId,
            $employeeId,
            $taxYear,
            self::PURPOSE,
            $manifestHash,
        );
        if ($existing !== null) {
            if (!hash_equals((string) $existing['snapshot_hash'], $snapshotHash)) {
                throw new \DomainException(
                    'Stejný manifest ročního zúčtování odkazuje na jiný snapshot.',
                );
            }

            return [
                'revision' => $existing,
                'document' => $this->hydrate($snapshot, (string) $existing['snapshot_hash']),
                'created' => false,
            ];
        }

        $previous = $this->annualRevisions->latest(
            $supplierId,
            $employeeId,
            $taxYear,
            self::PURPOSE,
        );
        $ciphertext = $this->encryption->encryptFor(
            $snapshotJson,
            $this->encryptionContext($supplierId, $employeeId, $taxYear, $manifestHash),
        );
        $revision = $this->annualRevisions->insertApproved([
            'supplier_id' => $supplierId,
            'employee_id' => $employeeId,
            'tax_year' => $taxYear,
            'purpose' => self::PURPOSE,
            'revision_no' => $previous === null ? 1 : (int) $previous['revision_no'] + 1,
            'previous_revision_id' => $previous['id'] ?? null,
            'snapshot_ciphertext' => $ciphertext,
            'snapshot_hash' => $snapshotHash,
            'source_manifest_json' => $manifestJson,
            'source_manifest_hash' => $manifestHash,
            'approved_by' => $actorUserId,
        ], $sources);

        return [
            'revision' => $revision,
            'document' => $this->hydrate($snapshot, $snapshotHash),
            'created' => true,
        ];
    }

    /** @param array<string,mixed> $snapshot */
    private function hydrate(
        array $snapshot,
        string $snapshotHash,
    ): AnnualSettlementDocumentData {
        $employer = self::object($snapshot['employer'] ?? null, 'employer');
        $employee = self::object($snapshot['employee'] ?? null, 'employee');
        $result = self::object($snapshot['result'] ?? null, 'result');
        $trace = self::object($result['trace'] ?? null, 'result.trace');
        // Starší revize klíč nenesly — chybějící příspěvek je nula, protože se
        // tehdy potvrzení od jiného plátce nepoužilo vůbec.
        $external = is_array($trace['external_certificates'] ?? null)
            ? $trace['external_certificates']
            : [];

        return new AnnualSettlementDocumentData(
            $snapshotHash,
            (int) $snapshot['tax_year'],
            (string) $employer['name'],
            (string) $employer['identification_number'],
            (string) $employer['address'],
            (string) $employee['name'],
            (string) $employee['identifier_label'],
            (string) $employee['identifier_value'],
            (string) $snapshot['settled_on'],
            (int) $trace['completed_months'],
            (int) $trace['advance_base_minor_units'],
            (int) $result['rounded_tax_base_minor_units'],
            (int) $result['tax_before_credits_minor_units'],
            self::rows($snapshot['credit_rows'] ?? null),
            (int) $result['applied_credits_minor_units'],
            self::rows($snapshot['child_rows'] ?? null),
            (int) $result['child_credit_minor_units'],
            (int) $result['annual_tax_bonus_minor_units'],
            (int) $result['tax_after_all_credits_minor_units'],
            (int) $trace['advance_tax_minor_units'],
            (int) $trace['monthly_tax_bonus_minor_units'],
            (int) $result['tax_difference_minor_units'],
            (int) $result['bonus_difference_minor_units'],
            (int) $result['settlement_difference_minor_units'],
            (int) $result['payable_minor_units'],
            (string) $result['outcome'],
            (int) ($external['count'] ?? 0),
            (int) ($external['advance_base_minor_units'] ?? 0),
            (int) ($external['advance_tax_minor_units'] ?? 0),
            (int) ($external['tax_bonus_minor_units'] ?? 0),
        );
    }

    /** @return list<array<string,mixed>> */
    private static function rows(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \DomainException('Řádky ročního zúčtování nejsou seznam.');
        }
        $rows = [];
        foreach ($value as $row) {
            $rows[] = self::object($row, 'row');
        }

        return $rows;
    }

    /** @return array<string,mixed> */
    private static function object(mixed $value, string $field): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \DomainException("Snapshot ročního zúčtování: {$field} není objekt.");
        }

        return $value;
    }

    /** @return array<string,string> */
    private function profileSnapshot(
        int $supplierId,
        int $employeeId,
        int $taxYear,
    ): array {
        $from = sprintf('%04d-01-01', $taxYear);
        $to = sprintf('%04d-12-31', $taxYear);

        $employee = $this->db->pdo()->prepare(
            'SELECT birth_date FROM payroll_employees
              WHERE supplier_id = ? AND id = ?
              FOR UPDATE'
        );
        $employee->execute([$supplierId, $employeeId]);
        $employeeRow = $employee->fetch(PDO::FETCH_ASSOC);
        if (!is_array($employeeRow)) {
            throw new \DomainException('Zaměstnanec ročního zúčtování neexistuje.');
        }

        $identity = $this->db->pdo()->prepare(
            'SELECT full_name FROM payroll_person_identity_history
              WHERE supplier_id = ? AND employee_id = ?
                AND effective_from <= ?
                AND (effective_to IS NULL OR effective_to >= ?)
              ORDER BY effective_from DESC, id DESC
              LIMIT 1
              FOR UPDATE'
        );
        $identity->execute([$supplierId, $employeeId, $to, $from]);
        $name = $identity->fetchColumn();
        if (!is_string($name) || trim($name) === '') {
            throw new \DomainException(
                'Pro roční zúčtování chybí účinná historie jména zaměstnance.',
            );
        }

        $identifier = $this->db->pdo()->prepare(
            'SELECT id, value_ciphertext
               FROM payroll_person_identifiers
              WHERE supplier_id = ? AND employee_id = ?
                AND identifier_type = "birth_number"
              LIMIT 1
              FOR UPDATE'
        );
        $identifier->execute([$supplierId, $employeeId]);
        $identifierRow = $identifier->fetch(PDO::FETCH_ASSOC);
        if (is_array($identifierRow)) {
            $label = 'Rodné číslo';
            $value = $this->sensitiveData->reveal(
                (string) $identifierRow['value_ciphertext'],
                PayrollSensitiveField::PERSONAL_IDENTIFIER,
                $supplierId,
                (int) $identifierRow['id'],
            );
        } elseif (is_string($employeeRow['birth_date'] ?? null)) {
            $label = 'Datum narození';
            $value = (new \DateTimeImmutable(
                (string) $employeeRow['birth_date'],
            ))->format('d.m.Y');
        } else {
            throw new \DomainException(
                'Pro roční zúčtování chybí rodné číslo i datum narození.',
            );
        }

        return [
            'name' => trim($name),
            'identifier_label' => $label,
            'identifier_value' => $value,
        ];
    }

    /** @return array<string,string> */
    private function employerSnapshot(int $supplierId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT COALESCE(NULLIF(display_name, ""), company_name) AS name,
                    ic, street, zip, city
               FROM supplier
              WHERE id = ?
              FOR UPDATE'
        );
        $statement->execute([$supplierId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \DomainException('Zaměstnavatel ročního zúčtování neexistuje.');
        }
        foreach (['name', 'ic', 'street', 'zip', 'city'] as $field) {
            if (!is_string($row[$field] ?? null) || trim((string) $row[$field]) === '') {
                throw new \DomainException(
                    "Zaměstnavatel nemá pro roční zúčtování vyplněné pole {$field}.",
                );
            }
        }

        return [
            'name' => trim((string) $row['name']),
            'identification_number' => trim((string) $row['ic']),
            'address' => trim((string) $row['street'])
                . ', '
                . trim((string) $row['zip'] . ' ' . (string) $row['city']),
        ];
    }

    private function encryptionContext(
        int $supplierId,
        int $employeeId,
        int $taxYear,
        string $manifestHash,
    ): string {
        return implode(':', [
            'payroll-annual-document',
            (string) $supplierId,
            (string) $employeeId,
            (string) $taxYear,
            self::PURPOSE,
            $manifestHash,
        ]);
    }

    private function snapshotFingerprint(string $snapshotJson, int $supplierId): string
    {
        return $this->sensitiveData->keyedFingerprint(
            $snapshotJson,
            self::SNAPSHOT_FINGERPRINT_DOMAIN,
            $supplierId,
        );
    }
}
