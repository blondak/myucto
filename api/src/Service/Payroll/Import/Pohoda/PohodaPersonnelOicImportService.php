<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Pohoda;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\CzechBirthNumber;
use MyInvoice\Service\Payroll\Import\Registration\RegistrationImportLookup;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityService;

/**
 * Mzdy → Importy → OIČ z POHODY: doplnění OIČ / IK MPSV z exportu
 * Tabulky agendy Personalistika.
 *
 * POHODA není protokol ČSSZ, proto se OIČ zapisuje stejnou cestou jako ruční
 * opis z karty vztahu ({@see PayrollRegistrationIdentityService::assignManualJmhzIdentity()})
 * a jen s potvrzením, že účetní čísla ověřila proti ePortálu ČSSZ nebo
 * registracím. Uložené OIČ se nikdy nepřepisuje; jinou hodnotu je nutné
 * opravit vědomě na kartě vztahu.
 *
 * Náhled nic nezapisuje. Použití si soubory přečte a plán sestaví znovu nad
 * aktuální evidencí; klientovi se věří jen seznam vybraných řádků. Rodná
 * čísla odcházejí ke klientovi jen maskovaná.
 */
final class PohodaPersonnelOicImportService
{
    /** Stav řádku náhledu (`rows[].status`). */
    public const STATUSES = [
        'ready',
        'already_stored',
        'conflict',
        'oic_owned_by_other',
        'not_found',
        'ambiguous',
        'no_employment',
        'duplicate',
        'invalid_birth_number',
        'invalid_oic',
        'no_oic',
    ];
    /** Výsledek použití řádku (`results[].status`). */
    public const RESULT_STATUSES = ['applied', 'failed', 'skipped'];
    public const SOURCE_PREFIX = 'pohoda-personalistika';

    private const ENVIRONMENTS = ['production', 'test'];
    private const KEY_PATTERN = '/^[0-9a-f]{16}:[0-9]{1,3}:[0-9]{1,5}$/D';
    /** Stavy vztahu, ke kterým se OIČ nepřiřazuje: vztah nevznikl nebo je v archivu. */
    private const SKIPPED_EMPLOYMENT_STATUSES = ['archived', 'no_show'];

    public function __construct(
        private readonly PohodaPersonnelReader $reader,
        private readonly RegistrationImportLookup $lookup,
        private readonly PayrollRegistrationIdentityService $identities,
        private readonly PayrollSensitiveData $sensitive,
        private readonly Connection $db,
    ) {}

    /**
     * @param list<array{name:string,content:string,sha256:string,extension:string}> $files
     * @return array{
     *   environment:string,
     *   files:list<array<string,mixed>>,
     *   rows:list<array<string,mixed>>,
     *   summary:array{total:int,ready:int,already_stored:int,conflict:int,blocked:int,without_oic:int}
     * }
     */
    public function preview(int $supplierId, array $files, string $environment): array
    {
        $this->environment($environment);
        $plan = $this->plan($supplierId, $files, $environment);
        $rows = array_map(self::publicRow(...), $plan['rows']);

        return [
            'environment' => $environment,
            'files' => $plan['files'],
            'rows' => $rows,
            'summary' => self::summary($rows),
        ];
    }

    /**
     * @param list<array{name:string,content:string,sha256:string,extension:string}> $files
     * @return array{
     *   environment:string,
     *   results:list<array{key:string,status:string,message:string,name:string,employee_id:?int,employment_id:?int}>,
     *   summary:array{applied:int,failed:int,skipped:int}
     * }
     */
    public function apply(
        int $supplierId,
        array $files,
        string $environment,
        mixed $keys,
        bool $evidenceConfirmed,
        ?int $userId,
    ): array {
        $this->environment($environment);
        if (!$evidenceConfirmed) {
            throw new \InvalidArgumentException(
                'Potvrďte, že jste OIČ ověřili proti ePortálu ČSSZ nebo registracím. POHODA není '
                . 'protokol ČSSZ; OIČ se uloží jako ověřený ruční opis a podle něj se páruje hlášení.',
            );
        }
        if (!is_array($keys) || !array_is_list($keys) || $keys === []) {
            throw new \InvalidArgumentException('Vyberte aspoň jeden řádek, jehož OIČ se má zapsat.');
        }
        $selected = [];
        foreach ($keys as $key) {
            if (!is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1) {
                throw new \InvalidArgumentException('Seznam vybraných řádků obsahuje neplatný klíč.');
            }
            $selected[$key] = true;
        }

        $plan = $this->plan($supplierId, $files, $environment);
        $byKey = array_column($plan['rows'], null, 'key');
        $results = [];
        foreach (array_keys($selected) as $key) {
            $row = $byKey[$key] ?? null;
            if ($row === null) {
                $results[] = self::result($key, 'skipped', 'Řádek s tímto klíčem v nahraném souboru není. '
                    . 'Nahrajte soubor znovu a obnovte náhled.');
                continue;
            }
            if ($row['status'] !== 'ready') {
                $results[] = self::result($key, 'skipped', (string) $row['message'], $row);
                continue;
            }
            try {
                $this->identities->assignManualJmhzIdentity(
                    $supplierId,
                    (int) $row['employment_id'],
                    $environment,
                    (string) $row['_oic'],
                    null,
                    (string) $row['valid_from'],
                    (string) $row['_source_reference'],
                    true,
                    $userId,
                );
                $results[] = self::result(
                    $key,
                    'applied',
                    'OIČ je uložené s platností od ' . self::czechDate((string) $row['valid_from']) . '.',
                    $row,
                );
            } catch (\DomainException|\InvalidArgumentException|\OutOfBoundsException $e) {
                $results[] = self::result($key, 'failed', $e->getMessage(), $row);
            }
        }

        $summary = ['applied' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ($results as $result) {
            $summary[$result['status']]++;
        }

        return ['environment' => $environment, 'results' => $results, 'summary' => $summary];
    }

    /**
     * @param list<array{name:string,content:string,sha256:string,extension:string}> $files
     * @return array{files:list<array<string,mixed>>,rows:list<array<string,mixed>>}
     */
    private function plan(int $supplierId, array $files, string $environment): array
    {
        $read = $this->reader->read($files);
        $supplierIco = $this->supplierIco($supplierId);
        $fileRows = [];
        foreach ($read['files'] as $file) {
            $warnings = [];
            if ($file['company_ico'] !== null && $supplierIco !== null && $file['company_ico'] !== $supplierIco) {
                $warnings[] = "Export je z firmy s IČ {$file['company_ico']}, vybraná firma má IČ {$supplierIco}. "
                    . 'Zkontrolujte, že nahráváte export správné firmy.';
            }
            $fileRows[] = $file + ['warnings' => $warnings];
        }

        $rows = [];
        foreach ($read['rows'] as $source) {
            $rows[] = $this->parse($supplierId, $source);
        }
        $this->markDuplicates($rows);
        foreach ($rows as &$row) {
            if ($row['status'] === null) {
                $this->resolve($supplierId, $environment, $row);
            }
        }
        unset($row);

        return ['files' => $fileRows, 'rows' => $rows];
    }

    /**
     * Kontrola tvaru obou čísel ze souboru; osoba se hledá až potom.
     *
     * @param array<string,mixed> $source
     * @return array<string,mixed>
     */
    private function parse(int $supplierId, array $source): array
    {
        $birthRaw = (string) $source['birth_number'];
        $oicRaw = (string) $source['oic'];
        $sheetSuffix = $source['sheet_index'] > 0 ? ':' . $source['sheet_index'] : '';
        $row = [
            'key' => substr((string) $source['sha256'], 0, 16) . ':' . $source['sheet_index'] . ':' . $source['row'],
            'file' => $source['file'],
            'sheet' => $source['sheet'],
            'row' => $source['row'],
            'name' => trim($source['last_name'] . ' ' . $source['first_name']),
            'personal_number' => $source['personal_number'] === '' ? null : $source['personal_number'],
            'birth_number_masked' => $this->masked($birthRaw, PayrollSensitiveField::PERSONAL_IDENTIFIER),
            'oic_masked' => $this->masked($oicRaw, PayrollSensitiveField::PERSON_EXTERNAL_IDENTIFIER),
            'status' => null,
            'message' => '',
            'employee_id' => null,
            'employee_name' => null,
            'employment_id' => null,
            'employment_code' => null,
            'valid_from' => null,
            '_birth_hash' => null,
            '_oic' => null,
            '_source_reference' => self::SOURCE_PREFIX . ':' . $source['sha256'] . ':' . $source['row'] . $sheetSuffix,
        ];
        if (trim($oicRaw) === '') {
            return self::status($row, 'no_oic', 'Řádek nemá vyplněné OIČ, přeskočí se.');
        }
        if (trim($birthRaw) === '') {
            return self::status($row, 'invalid_birth_number', 'Řádek nemá vyplněné rodné číslo, osobu podle něj nejde najít.');
        }
        try {
            $birthNumber = CzechBirthNumber::normalize($birthRaw);
        } catch (\InvalidArgumentException $e) {
            return self::status($row, 'invalid_birth_number', $e->getMessage() . ' Opravte ho v POHODĚ a export zopakujte.');
        }
        try {
            $row['_oic'] = PayrollRegistrationIdentityService::oic($oicRaw);
        } catch (\InvalidArgumentException $e) {
            return self::status($row, 'invalid_oic', $e->getMessage());
        }
        $row['_birth_hash'] = $this->sensitive->lookupHash(
            $birthNumber,
            PayrollSensitiveField::PERSONAL_IDENTIFIER,
            $supplierId,
        );

        return $row;
    }

    /**
     * Soubor si nesmí odporovat: stejné OIČ u dvou různých osob, nebo jedna
     * osoba s dvěma různými OIČ, zablokuje všechny dotčené řádky — ze souboru
     * nejde poznat, který je správně. Opakovaný řádek se stejnými čísly se
     * zapíše jen jednou.
     *
     * @param list<array<string,mixed>> $rows
     */
    private function markDuplicates(array &$rows): void
    {
        $oicsByPerson = [];
        $personsByOic = [];
        foreach ($rows as $row) {
            if ($row['status'] !== null) {
                continue;
            }
            $oicsByPerson[$row['_birth_hash']][$row['_oic']] = true;
            $personsByOic[$row['_oic']][$row['_birth_hash']] = true;
        }
        $seen = [];
        foreach ($rows as &$row) {
            if ($row['status'] !== null) {
                continue;
            }
            if (count($personsByOic[$row['_oic']]) > 1) {
                $row = self::status($row, 'duplicate', 'Stejné OIČ je v souboru u víc osob. Ověřte, komu patří, '
                    . 'opravte export a nahrajte ho znovu.');
                continue;
            }
            if (count($oicsByPerson[$row['_birth_hash']]) > 1) {
                $row = self::status($row, 'duplicate', 'Osoba je v souboru víckrát s různým OIČ. Ověřte správné '
                    . 'číslo, opravte export a nahrajte ho znovu.');
                continue;
            }
            $pair = $row['_birth_hash'] . '|' . $row['_oic'];
            if (isset($seen[$pair])) {
                $row = self::status($row, 'duplicate', "Řádek opakuje řádek {$seen[$pair]} se stejným rodným "
                    . 'číslem i OIČ, zapíše se jen jednou.');
                continue;
            }
            $seen[$pair] = $row['row'];
        }
        unset($row);
    }

    /** @param array<string,mixed> $row */
    private function resolve(int $supplierId, string $environment, array &$row): void
    {
        $employeeIds = $this->lookup->employeesByIdentifierHash($supplierId, 'birth_number', (string) $row['_birth_hash']);
        if ($employeeIds === []) {
            $row = self::status($row, 'not_found', 'Osoba s tímto rodným číslem v mzdové evidenci není. '
                . 'Založte ji, nebo doplňte rodné číslo na kartě osoby, a náhled obnovte.');
            return;
        }
        if (count($employeeIds) > 1) {
            $row = self::status($row, 'ambiguous', 'Rodné číslo mají v evidenci dvě nebo víc osob. '
                . 'Duplicitní osobu sjednoťte a náhled obnovte.');
            return;
        }
        $employeeId = $employeeIds[0];
        $row['employee_id'] = $employeeId;
        $row['employee_name'] = $this->lookup->employeeName($supplierId, $employeeId);
        $employment = self::chooseEmployment($this->lookup->employments($supplierId, $employeeId), self::today());
        if ($employment !== null) {
            $row['employment_id'] = $employment['id'];
            $row['employment_code'] = $employment['code'];
        }

        $owners = $this->oicOwners($supplierId, $environment, (string) $row['_oic']);
        if (array_diff($owners, [$employeeId]) !== []) {
            $row = self::status($row, 'oic_owned_by_other', 'Toto OIČ má v evidenci jiná osoba. Jedno OIČ patří '
                . 'jediné osobě; ověřte číslo proti ePortálu ČSSZ a opravte ho u osoby, u které je chybně.');
            return;
        }
        $matches = $this->identities->activePersonExternalIdMatches(
            $supplierId,
            $employeeId,
            $environment,
            (string) $row['_oic'],
        );
        if ($matches === true) {
            $row = self::status($row, 'already_stored', 'Stejné OIČ už je u osoby uložené, není co zapisovat.');
            return;
        }
        if ($matches === false) {
            $row = self::status($row, 'conflict', 'U osoby je uložené jiné OIČ. Import ho nepřepisuje; '
                . 'ověřte správné číslo a případně ho opravte na kartě pracovního vztahu (JMHZ identifikátory).');
            return;
        }
        if (in_array($employeeId, $owners, true)) {
            $row = self::status($row, 'conflict', 'Toto OIČ má osoba v evidenci s ukončenou platností. Import '
                . 'ho neobnovuje; stav zkontrolujte na kartě pracovního vztahu (JMHZ identifikátory).');
            return;
        }
        if ($employment === null) {
            $row = self::status($row, 'no_employment', 'Osoba nemá pracovní vztah se dnem nástupu, ke kterému by '
                . 'OIČ šlo přiřadit. Doplňte vztah na kartě osoby a náhled obnovte.');
            return;
        }
        $validFrom = (string) $employment['start_date'];
        if ($employment['end_date'] !== null && $validFrom > $employment['end_date']) {
            $validFrom = (string) $employment['end_date'];
        }
        $row['valid_from'] = $validFrom;
        $row = self::status($row, 'ready', 'Zapíše se jako ověřený opis s platností od nástupu '
            . self::czechDate($validFrom) . '.');
    }

    /**
     * Vztah, ke kterému se OIČ přiřadí: ten, který platí dnes (hlavní
     * přednostně), jinak poslední se dnem nástupu. Archivovaný a nenastoupený
     * vztah se nepoužije.
     *
     * @param list<array{id:int,code:string,status:string,is_primary:bool,start_date:?string,end_date:?string}> $employments
     * @return array{id:int,code:string,status:string,is_primary:bool,start_date:string,end_date:?string}|null
     */
    public static function chooseEmployment(array $employments, string $today): ?array
    {
        $candidates = array_values(array_filter(
            $employments,
            static fn (array $employment): bool => $employment['start_date'] !== null
                && !in_array($employment['status'], self::SKIPPED_EMPLOYMENT_STATUSES, true),
        ));
        if ($candidates === []) {
            return null;
        }
        $covering = array_values(array_filter(
            $candidates,
            static fn (array $employment): bool => $employment['start_date'] <= $today
                && ($employment['end_date'] === null || $employment['end_date'] >= $today),
        ));
        $pool = $covering !== [] ? $covering : $candidates;
        usort($pool, static fn (array $a, array $b): int => [
            $covering !== [] && $b['is_primary'],
            $b['start_date'],
            $b['id'],
        ] <=> [
            $covering !== [] && $a['is_primary'],
            $a['start_date'],
            $a['id'],
        ]);
        /** @var array{id:int,code:string,status:string,is_primary:bool,start_date:string,end_date:?string} */
        return $pool[0];
    }

    /**
     * Kdo OIČ v evidenci má, včetně ukončených záznamů: hodnota je v rámci
     * firmy a prostředí unikátní napříč celou historií.
     *
     * @return list<int>
     */
    private function oicOwners(int $supplierId, string $environment, string $oic): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT DISTINCT employee_id
               FROM payroll_person_external_ids
              WHERE supplier_id = ? AND environment = ? AND identifier_type = "ik_mpsv" AND value_hash = ?
              ORDER BY employee_id'
        );
        $statement->execute([
            $supplierId,
            $environment,
            $this->sensitive->lookupHash($oic, PayrollSensitiveField::PERSON_EXTERNAL_IDENTIFIER, $supplierId),
        ]);

        return array_map(intval(...), $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function supplierIco(int $supplierId): ?string
    {
        $statement = $this->db->pdo()->prepare('SELECT ic FROM supplier WHERE id = ?');
        $statement->execute([$supplierId]);
        $ico = preg_replace('/\D/', '', (string) $statement->fetchColumn());

        return is_string($ico) && $ico !== '' ? str_pad($ico, 8, '0', STR_PAD_LEFT) : null;
    }

    private function masked(string $value, PayrollSensitiveField $field): ?string
    {
        if (trim($value) === '') {
            return null;
        }
        try {
            return $this->sensitive->mask($value, $field);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function status(array $row, string $status, string $message): array
    {
        $row['status'] = $status;
        $row['message'] = $message;

        return $row;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function publicRow(array $row): array
    {
        $public = array_filter($row, static fn (string $key): bool => !str_starts_with($key, '_'), ARRAY_FILTER_USE_KEY);
        $public['selectable'] = $row['status'] === 'ready';

        return $public;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array{total:int,ready:int,already_stored:int,conflict:int,blocked:int,without_oic:int}
     */
    private static function summary(array $rows): array
    {
        $summary = ['total' => 0, 'ready' => 0, 'already_stored' => 0, 'conflict' => 0, 'blocked' => 0, 'without_oic' => 0];
        foreach ($rows as $row) {
            if ($row['status'] === 'no_oic') {
                $summary['without_oic']++;
                continue;
            }
            $summary['total']++;
            $bucket = in_array($row['status'], ['ready', 'already_stored', 'conflict'], true) ? $row['status'] : 'blocked';
            $summary[$bucket]++;
        }

        return $summary;
    }

    /**
     * @param array<string,mixed>|null $row
     * @return array{key:string,status:string,message:string,name:string,employee_id:?int,employment_id:?int}
     */
    private static function result(string $key, string $status, string $message, ?array $row = null): array
    {
        return [
            'key' => $key,
            'status' => $status,
            'message' => $message,
            'name' => (string) ($row['name'] ?? ''),
            'employee_id' => isset($row['employee_id']) ? (int) $row['employee_id'] : null,
            'employment_id' => isset($row['employment_id']) ? (int) $row['employment_id'] : null,
        ];
    }

    private static function today(): string
    {
        return (new \DateTimeImmutable('today'))->format('Y-m-d');
    }

    private static function czechDate(string $date): string
    {
        [$year, $month, $day] = array_map('intval', explode('-', $date));

        return "{$day}. {$month}. {$year}";
    }

    private function environment(string $environment): void
    {
        if (!in_array($environment, self::ENVIRONMENTS, true)) {
            throw new \InvalidArgumentException('Prostředí musí být ostré (production), nebo testovací (test).');
        }
    }
}
