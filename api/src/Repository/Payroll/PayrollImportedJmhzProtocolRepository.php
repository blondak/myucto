<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Protokoly ČSSZ načtené ze souboru (migrace 1375).
 *
 * Doklad, který přišel do datové schránky, není pokus o odeslání — proto sem
 * a ne do `payroll_submission_transport_attempts`. Zdůvodnění je v migraci.
 *
 * Zápis je záměrně UPSERT: druhé načtení téhož protokolu má řádek zpřesnit,
 * ne zdvojit. Identita řádku (firma, prostředí, variabilní symbol, období,
 * dedupe klíč) je přitom v databázi neměnná — trigger to hlídá bez ohledu na
 * to, co udělá aplikace.
 */
final class PayrollImportedJmhzProtocolRepository
{
    private const TABLE = 'payroll_imported_jmhz_protocols';

    /**
     * Projekce pro seznam. `payload_xml` v ní NENÍ: ven se syrový doklad
     * neposílá. Kdo ho potřebuje (vysvětlení chyb se počítá z originálu, ne
     * z uložené interpretace), řekne si o něj příznakem `$withPayload`.
     */
    private const LIST_COLUMNS = 'id, supplier_id, environment, protocol_kind,
                    variable_symbol, period_month, period_year, submission_guid,
                    correlation_reference, status_code, status_name, error_count,
                    protocol_dated_at, submitted_at, source_filename,
                    payload_sha256, row_version, imported_by, created_at,
                    updated_at';

    /** @var list<string> */
    private const ENVIRONMENTS = ['production', 'test'];

    public function __construct(private readonly Connection $db) {}

    public function isAvailable(): bool
    {
        return $this->db->hasTable(self::TABLE);
    }

    /**
     * Načtené protokoly firmy v prostředí, od nejnovějšího.
     *
     * @return list<array<string,mixed>>
     */
    public function listRecent(
        int $supplierId,
        string $environment,
        int $limit = 100,
        bool $withPayload = false,
    ): array {
        if (!$this->isAvailable()) {
            return [];
        }
        self::assertEnvironment($environment);
        // MariaDB v LIMIT vázané parametry nepřijímá; rozsah se proto omezuje
        // tady a do SQL jde celé číslo.
        $limit = max(1, min($limit, 200));
        $columns = self::LIST_COLUMNS . ($withPayload ? ', payload_xml' : '');
        $statement = $this->db->pdo()->prepare(
            'SELECT ' . $columns . '
               FROM ' . self::TABLE . '
              WHERE supplier_id = ? AND environment = ?
              ORDER BY period_year DESC, period_month DESC, id DESC
              LIMIT ' . $limit,
        );
        $statement->execute([$supplierId, $environment]);
        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (is_array($row)) {
                $rows[] = self::normalize($row);
            }
        }

        return $rows;
    }

    /**
     * Variabilní symboly, kterými se firma u ČSSZ prokazuje.
     *
     * Čte se přímo, ne přes `PayrollEmployerSettingsRepository::get()`: ten
     * vrací pracoviště jen tehdy, když existuje řádek nastavení s platným
     * výchozím pracovištěm. Firma, která má pracoviště se symbolem, ale
     * nastavení nedodělané, by tak přišla o možnost načíst VLASTNÍ protokol —
     * a to je odmítnutí z nesprávného důvodu. Neaktivní pracoviště se
     * nevynechávají: symbol je identita firmy, ne oprávnění.
     *
     * @return list<string>
     */
    public function employerVariableSymbols(int $supplierId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT employer_registration_number AS symbol
               FROM payroll_employer_settings
              WHERE supplier_id = ? AND employer_registration_number IS NOT NULL
              UNION
             SELECT social_security_variable_symbol AS symbol
               FROM payroll_offices
              WHERE supplier_id = ? AND social_security_variable_symbol IS NOT NULL',
        );
        $statement->execute([$supplierId, $supplierId]);
        $symbols = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $symbol) {
            if (is_string($symbol) && trim($symbol) !== '') {
                $symbols[] = trim($symbol);
            }
        }

        return $symbols;
    }

    /** @return array<string,mixed>|null */
    public function findByDedupeKey(
        int $supplierId,
        string $environment,
        string $dedupeKey,
    ): ?array {
        if (!$this->isAvailable()) {
            return null;
        }
        self::assertEnvironment($environment);
        $statement = $this->db->pdo()->prepare(
            'SELECT ' . self::LIST_COLUMNS . '
               FROM ' . self::TABLE . '
              WHERE supplier_id = ? AND environment = ? AND dedupe_key = ?',
        );
        $statement->execute([$supplierId, $environment, $dedupeKey]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::normalize($row) : null;
    }

    /**
     * Uloží načtený protokol; při shodném dedupe klíči přepíše ten dosavadní.
     *
     * @param array{
     *   protocol_kind:string,
     *   variable_symbol:string,
     *   period_month:?int,
     *   period_year:?int,
     *   submission_guid:?string,
     *   correlation_reference:?string,
     *   status_code:int,
     *   status_name:string,
     *   error_count:int,
     *   protocol_dated_at:?string,
     *   submitted_at:?string,
     *   source_filename:?string,
     *   payload_sha256:string,
     *   payload_xml:string,
     *   dedupe_key:string
     * } $data
     * @return array{row:array<string,mixed>,created:bool}
     */
    public function store(
        int $supplierId,
        string $environment,
        array $data,
        ?int $actorUserId,
    ): array {
        if (!$this->isAvailable()) {
            throw new \DomainException(
                'Evidence načtených protokolů není v databázi založená (migrace 1375).',
            );
        }
        self::assertEnvironment($environment);
        $existing = $this->findByDedupeKey($supplierId, $environment, $data['dedupe_key']);
        if ($existing !== null) {
            // Období ani variabilní symbol se nepřepisují — trigger by to stejně
            // odmítl a přepis by znamenal, že se doklad tiše přiřadil jinam.
            $update = $this->db->pdo()->prepare(
                'UPDATE ' . self::TABLE . '
                    SET protocol_kind = ?, submission_guid = ?,
                        correlation_reference = ?, status_code = ?,
                        status_name = ?, error_count = ?, protocol_dated_at = ?,
                        submitted_at = ?, source_filename = ?,
                        payload_sha256 = ?, payload_xml = ?,
                        imported_by = ?, row_version = row_version + 1
                  WHERE supplier_id = ? AND environment = ? AND id = ?',
            );
            $update->execute([
                $data['protocol_kind'],
                $data['submission_guid'],
                $data['correlation_reference'],
                $data['status_code'],
                $data['status_name'],
                $data['error_count'],
                $data['protocol_dated_at'],
                $data['submitted_at'],
                $data['source_filename'],
                $data['payload_sha256'],
                $data['payload_xml'],
                $actorUserId,
                $supplierId,
                $environment,
                (int) $existing['id'],
            ]);
            $row = $this->findByDedupeKey($supplierId, $environment, $data['dedupe_key']);

            return ['row' => $row ?? $existing, 'created' => false];
        }

        $insert = $this->db->pdo()->prepare(
            'INSERT INTO ' . self::TABLE . '
                (supplier_id, environment, protocol_kind, variable_symbol,
                 period_month, period_year, submission_guid,
                 correlation_reference, status_code, status_name, error_count,
                 protocol_dated_at, submitted_at, source_filename,
                 payload_sha256, payload_xml, dedupe_key, imported_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $insert->execute([
            $supplierId,
            $environment,
            $data['protocol_kind'],
            $data['variable_symbol'],
            $data['period_month'],
            $data['period_year'],
            $data['submission_guid'],
            $data['correlation_reference'],
            $data['status_code'],
            $data['status_name'],
            $data['error_count'],
            $data['protocol_dated_at'],
            $data['submitted_at'],
            $data['source_filename'],
            $data['payload_sha256'],
            $data['payload_xml'],
            $data['dedupe_key'],
            $actorUserId,
        ]);
        $row = $this->findByDedupeKey($supplierId, $environment, $data['dedupe_key']);
        if ($row === null) {
            throw new \RuntimeException('Načtený protokol se nepodařilo uložit.');
        }

        return ['row' => $row, 'created' => true];
    }

    private static function assertEnvironment(string $environment): void
    {
        if (!in_array($environment, self::ENVIRONMENTS, true)) {
            throw new \InvalidArgumentException(
                "Prostředí `{$environment}` není test ani production.",
            );
        }
    }

    /**
     * @param array<array-key,mixed> $row
     * @return array<string,mixed>
     */
    private static function normalize(array $row): array
    {
        /** @var array<string,mixed> $normalized */
        $normalized = [];
        foreach ($row as $key => $value) {
            $normalized[(string) $key] = $value;
        }
        foreach (['id', 'supplier_id', 'status_code', 'error_count', 'row_version'] as $column) {
            if (array_key_exists($column, $normalized)) {
                $normalized[$column] = (int) $normalized[$column];
            }
        }
        foreach (['period_month', 'period_year', 'imported_by'] as $column) {
            if (array_key_exists($column, $normalized)) {
                $normalized[$column] = $normalized[$column] === null
                    ? null
                    : (int) $normalized[$column];
            }
        }

        return $normalized;
    }
}
