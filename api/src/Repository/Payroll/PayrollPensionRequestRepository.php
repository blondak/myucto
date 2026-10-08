<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use DateTimeImmutable;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpDeadlinePolicy;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpStatementService;
use MyInvoice\Service\Payroll\Submission\Eldp\PensionRequestDeadlinePolicy;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionCalendar;
use PDO;

/**
 * Výzvy a žádosti v důchodovém pojištění u osoby: evidenční list na výzvu
 * nebo po úmrtí, oprava měsíčního hlášení na výzvu (§ 38a odst. 1),
 * potvrzení o době pojištění (§ 42), o náhradách za ztrátu na výdělku (§ 37
 * odst. 2) a potvrzení podle znění do 31. 12. 2025 (čl. V zák. č. 360/2025 Sb.).
 *
 * Termín počítá {@see PensionRequestDeadlinePolicy} z doručení. Evidenční
 * list na výzvu se vyřizuje stejnou cestou jako ostatní listy: žádost se
 * jen naváže na připravený list ({@see self::linkEldpStatement()}) a jeho
 * povinnost pak hlídá registr podání. Ostatní žádosti vyřizuje účetní ručně
 * (vystavené potvrzení, odeslané hlášení), stejnopis zaměstnanci se zapisuje
 * zvlášť.
 */
final class PayrollPensionRequestRepository
{
    private const AUTHORITIES = ['cssz', 'ossz'];

    public function __construct(
        private readonly Connection $db,
        private readonly PensionRequestDeadlinePolicy $policy = new PensionRequestDeadlinePolicy(),
    ) {}

    /** @return list<array<string,mixed>>|null */
    public function list(int $supplierId, int $employeeId): ?array
    {
        if (!$this->employeeExists($supplierId, $employeeId)) {
            return null;
        }
        $statement = $this->db->pdo()->prepare(
            'SELECT request.*, obligation.status AS obligation_status
               FROM payroll_pension_requests request
          LEFT JOIN payroll_obligations obligation
                 ON obligation.supplier_id = request.supplier_id
                AND obligation.environment = request.eldp_environment
                AND obligation.source_event_type = ?
                AND obligation.source_event_reference = CONCAT(\'eldp_statement:\', request.eldp_statement_id)
              WHERE request.supplier_id = ? AND request.employee_id = ?
              ORDER BY request.received_on DESC, request.id DESC'
        );
        $statement->execute([EldpStatementService::SOURCE_EVENT_TYPE, $supplierId, $employeeId]);

        return array_map(fn (array $row): array => $this->present($row), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param array<string,mixed> $input
     * @return list<array<string,mixed>>
     */
    public function create(int $supplierId, int $employeeId, array $input, int $actorId): array
    {
        if (!$this->employeeExists($supplierId, $employeeId)) {
            throw new \OutOfBoundsException('Zaměstnanec nenalezen.');
        }
        $kind = self::choice($input['request_kind'] ?? null, PensionRequestDeadlinePolicy::KINDS, 'Druh');
        $requester = self::choice(
            $input['requester'] ?? null,
            PensionRequestDeadlinePolicy::REQUESTERS[$kind],
            'Žadatel',
        );
        $receivedOn = self::date($input['received_on'] ?? null, 'Den doručení');
        if ($receivedOn > PayrollSubmissionCalendar::today()) {
            throw new \InvalidArgumentException('Den doručení nemůže být v budoucnosti.');
        }
        $legacyKind = $kind === 'legacy_confirmation'
            ? self::choice($input['legacy_kind'] ?? null, PensionRequestDeadlinePolicy::LEGACY_KINDS, 'Druh potvrzení')
            : null;
        $periodYear = self::optionalYear($input['period_year'] ?? null);
        $periodFrom = self::optionalDate($input['period_from'] ?? null, 'Období od');
        $periodTo = self::optionalDate($input['period_to'] ?? null, 'Období do');
        if ($periodFrom !== null && $periodTo !== null && $periodTo < $periodFrom) {
            throw new \InvalidArgumentException('Konec období nemůže předcházet jeho začátku.');
        }
        $deathOn = self::optionalDate($input['death_on'] ?? null, 'Datum úmrtí');
        $statedDueOn = self::optionalDate($input['stated_due_on'] ?? null, 'Lhůta z výzvy');
        $employmentId = $this->employment($supplierId, $employeeId, $input['employment_id'] ?? null);
        if (in_array($kind, ['eldp', 'insurance_period_confirmation'], true)) {
            if ($employmentId === null) {
                throw new \InvalidArgumentException('Zvolte pracovní vztah, kterého se list nebo potvrzení týká.');
            }
            if ($periodYear === null) {
                throw new \InvalidArgumentException('Zadejte kalendářní rok, kterého se list nebo potvrzení týká.');
            }
            if ($periodYear > (int) substr($receivedOn, 0, 4)) {
                throw new \InvalidArgumentException('Rok listu nebo potvrzení nemůže být pozdější než rok doručení.');
            }
        }
        if ($kind === 'jmh_correction' && $periodFrom === null) {
            throw new \InvalidArgumentException('Zadejte první měsíc, kterého se výzva k opravě hlášení týká.');
        }
        if ($kind === 'eldp' && $requester === 'survivor') {
            $allowed = EldpDeadlinePolicy::standaloneStatementAllowed((int) $periodYear, $deathOn, false);
            if (!$allowed['allowed']) {
                throw new \InvalidArgumentException($allowed['reason']);
            }
        }
        $deadline = $this->policy->forRequest(
            $kind,
            $requester,
            $receivedOn,
            $periodYear,
            $statedDueOn,
            $requester === 'survivor' ? $deathOn : null,
            $legacyKind,
        );

        $this->db->pdo()->prepare(
            'INSERT INTO payroll_pension_requests
                (supplier_id, employee_id, employment_id, request_kind, legacy_kind,
                 requester, requester_reference, received_on, period_year, period_from,
                 period_to, death_on, stated_due_on, due_on, deadline_rule, note, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $supplierId,
            $employeeId,
            $employmentId,
            $kind,
            $legacyKind,
            $requester,
            self::text($input['requester_reference'] ?? null, 'Číslo jednací / značka', 190),
            $receivedOn,
            $periodYear,
            $periodFrom,
            $periodTo,
            $deathOn,
            $statedDueOn,
            $deadline['due_on'],
            $deadline['rule'],
            self::text($input['note'] ?? null, 'Poznámka', 500),
            $actorId,
        ]);

        return $this->list($supplierId, $employeeId) ?? [];
    }

    /** @return list<array<string,mixed>> */
    public function complete(
        int $supplierId,
        int $employeeId,
        int $requestId,
        mixed $completedOn,
        mixed $reference,
        int $actorId,
    ): array {
        $request = $this->find($supplierId, $employeeId, $requestId);
        $completedOn = self::date($completedOn, 'Den vyřízení');
        if ($completedOn < (string) $request['received_on']) {
            throw new \InvalidArgumentException('Výzvu nebo žádost nelze vyřídit dřív, než byla doručena.');
        }
        if ($completedOn > PayrollSubmissionCalendar::today()) {
            throw new \InvalidArgumentException('Den vyřízení nemůže být v budoucnosti.');
        }
        $this->db->pdo()->prepare(
            'UPDATE payroll_pension_requests
                SET completed_on = ?, completion_kind = ?, completion_reference = ?,
                    completed_by = ?, row_version = row_version + 1
              WHERE supplier_id = ? AND employee_id = ? AND id = ? AND completed_on IS NULL'
        )->execute([
            $completedOn,
            $request['eldp_statement_id'] !== null ? 'eldp_statement' : 'manual',
            self::text($reference, 'Doklad o vyřízení', 190),
            $actorId,
            $supplierId,
            $employeeId,
            $requestId,
        ]);

        return $this->list($supplierId, $employeeId) ?? [];
    }

    /**
     * Den předání stejnopisu (kopie) zaměstnanci, u hornictví stejnopisu ČSSZ.
     *
     * @return list<array<string,mixed>>
     */
    public function recordCopyDelivered(int $supplierId, int $employeeId, int $requestId, mixed $deliveredOn): array
    {
        $request = $this->find($supplierId, $employeeId, $requestId);
        $deliveredOn = self::date($deliveredOn, 'Den předání kopie');
        if ($deliveredOn < (string) $request['received_on']) {
            throw new \InvalidArgumentException('Kopii nelze předat dřív, než byla výzva nebo žádost doručena.');
        }
        $this->db->pdo()->prepare(
            'UPDATE payroll_pension_requests
                SET copy_delivered_on = ?, row_version = row_version + 1
              WHERE supplier_id = ? AND employee_id = ? AND id = ?'
        )->execute([$deliveredOn, $supplierId, $employeeId, $requestId]);

        return $this->list($supplierId, $employeeId) ?? [];
    }

    /** @return list<array<string,mixed>> */
    public function remove(int $supplierId, int $employeeId, int $requestId): array
    {
        $request = $this->find($supplierId, $employeeId, $requestId);
        if ($request['eldp_statement_id'] !== null) {
            throw new \InvalidArgumentException(
                'Výzva je navázaná na připravený evidenční list a smazat ji nelze; vyřízení zapište.',
            );
        }
        $this->db->pdo()->prepare(
            'DELETE FROM payroll_pension_requests
              WHERE supplier_id = ? AND employee_id = ? AND id = ?'
        )->execute([$supplierId, $employeeId, $requestId]);

        return $this->list($supplierId, $employeeId) ?? [];
    }

    /**
     * Údaje výzvy pro sestavení evidenčního listu — přesně ty, ze kterých se
     * počítala lhůta žádosti, aby list a žádost měly tentýž termín.
     *
     * @return array{requested_by_authority:bool,authority_request_received_on:?string,authority_request_due_on:?string,death_on:?string}
     */
    public function eldpConfirmation(int $supplierId, int $requestId, int $employmentId, int $year): array
    {
        $request = $this->findById($supplierId, $requestId);
        if ($request['request_kind'] !== 'eldp') {
            throw new \InvalidArgumentException('Výzva se netýká evidenčního listu.');
        }
        if ((int) $request['employment_id'] !== $employmentId || (int) $request['period_year'] !== $year) {
            throw new \InvalidArgumentException('Výzva se týká jiného pracovního vztahu nebo roku než připravovaný list.');
        }
        $authority = in_array($request['requester'], self::AUTHORITIES, true);

        return [
            'requested_by_authority' => $authority,
            'authority_request_received_on' => $authority ? (string) $request['received_on'] : null,
            'authority_request_due_on' => $authority && $request['stated_due_on'] !== null
                ? (string) $request['stated_due_on']
                : null,
            'death_on' => $request['death_on'] === null ? null : (string) $request['death_on'],
        ];
    }

    /**
     * Naváže výzvu na připravený evidenční list; od té chvíle lhůtu hlídá
     * registr podání. Opravný list téže výzvy dřívější vazbu nahradí.
     */
    public function linkEldpStatement(
        int $supplierId,
        int $requestId,
        int $statementId,
        string $environment,
    ): void {
        $request = $this->findById($supplierId, $requestId);
        $statement = $this->db->pdo()->prepare(
            'SELECT 1 FROM payroll_eldp_statements
              WHERE supplier_id = ? AND environment = ? AND id = ?
                AND employment_id = ? AND statement_year = ?'
        );
        $statement->execute([
            $supplierId,
            $environment,
            $statementId,
            (int) $request['employment_id'],
            (int) $request['period_year'],
        ]);
        if ($statement->fetchColumn() === false) {
            throw new \InvalidArgumentException('Evidenční list neodpovídá vztahu a roku výzvy.');
        }
        $this->db->pdo()->prepare(
            'UPDATE payroll_pension_requests
                SET eldp_statement_id = ?, eldp_environment = ?, row_version = row_version + 1
              WHERE supplier_id = ? AND id = ?'
        )->execute([$statementId, $environment, $supplierId, $requestId]);
    }

    /**
     * Nevyřízené výzvy a žádosti s termínem v okně — pramen hlídače termínů.
     * Výzva navázaná na připravený evidenční list sem nepatří: její termín
     * nese povinnost toho listu v registru podání.
     *
     * @return list<array{request_id:int,employee_id:int,employment_id:?int,full_name:string,request_kind:string,legacy_kind:?string,requester:string,received_on:string,period_year:?int,period_from:?string,due_on:string,deadline_rule:string,legal_basis:string}>
     */
    public function openDeadlines(int $supplierId, string $from, string $to): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT request.id AS request_id, request.employee_id, request.employment_id,
                    employee.full_name, request.request_kind, request.legacy_kind,
                    request.requester, request.received_on, request.period_year,
                    request.period_from, request.due_on, request.deadline_rule,
                    request.stated_due_on, request.death_on
               FROM payroll_pension_requests request
               JOIN payroll_employees employee
                 ON employee.supplier_id = request.supplier_id
                AND employee.id = request.employee_id
              WHERE request.supplier_id = ?
                AND request.completed_on IS NULL
                AND request.eldp_statement_id IS NULL
                AND request.due_on BETWEEN ? AND ?
              ORDER BY request.due_on, request.id'
        );
        $statement->execute([$supplierId, $from, $to]);

        return array_map(fn (array $row): array => [
            'legal_basis' => $this->legalBasis($row),
            'request_id' => (int) $row['request_id'],
            'employee_id' => (int) $row['employee_id'],
            'employment_id' => $row['employment_id'] === null ? null : (int) $row['employment_id'],
            'full_name' => (string) $row['full_name'],
            'request_kind' => (string) $row['request_kind'],
            'legacy_kind' => $row['legacy_kind'] === null ? null : (string) $row['legacy_kind'],
            'requester' => (string) $row['requester'],
            'received_on' => (string) $row['received_on'],
            'period_year' => $row['period_year'] === null ? null : (int) $row['period_year'],
            'period_from' => $row['period_from'] === null ? null : (string) $row['period_from'],
            'due_on' => (string) $row['due_on'],
            'deadline_rule' => (string) $row['deadline_rule'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string,mixed> */
    public function findForEmployee(int $supplierId, int $employeeId, int $requestId): array
    {
        return $this->find($supplierId, $employeeId, $requestId);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function present(array $row): array
    {
        $completedOn = $row['completed_on'] === null ? null : (string) $row['completed_on'];
        $obligationStatus = $row['obligation_status'] ?? null;
        $status = match (true) {
            $completedOn !== null => 'completed',
            in_array($obligationStatus, ['submitted', 'fulfilled'], true) => 'statement_' . $obligationStatus,
            $row['eldp_statement_id'] !== null => 'statement_prepared',
            default => 'open',
        };
        return [
            'id' => (int) $row['id'],
            'employee_id' => (int) $row['employee_id'],
            'employment_id' => $row['employment_id'] === null ? null : (int) $row['employment_id'],
            'request_kind' => (string) $row['request_kind'],
            'legacy_kind' => $row['legacy_kind'] === null ? null : (string) $row['legacy_kind'],
            'requester' => (string) $row['requester'],
            'requester_reference' => $row['requester_reference'] === null ? null : (string) $row['requester_reference'],
            'received_on' => (string) $row['received_on'],
            'period_year' => $row['period_year'] === null ? null : (int) $row['period_year'],
            'period_from' => $row['period_from'] === null ? null : (string) $row['period_from'],
            'period_to' => $row['period_to'] === null ? null : (string) $row['period_to'],
            'death_on' => $row['death_on'] === null ? null : (string) $row['death_on'],
            'stated_due_on' => $row['stated_due_on'] === null ? null : (string) $row['stated_due_on'],
            'due_on' => (string) $row['due_on'],
            'deadline_rule' => (string) $row['deadline_rule'],
            'deadline_source' => $this->legalBasis($row),
            'eldp_statement_id' => $row['eldp_statement_id'] === null ? null : (int) $row['eldp_statement_id'],
            'eldp_environment' => $row['eldp_environment'] === null ? null : (string) $row['eldp_environment'],
            'status' => $status,
            'completed_on' => $completedOn,
            'completion_kind' => $row['completion_kind'] === null ? null : (string) $row['completion_kind'],
            'completion_reference' => $row['completion_reference'] === null ? null : (string) $row['completion_reference'],
            'copy_delivered_on' => $row['copy_delivered_on'] === null ? null : (string) $row['copy_delivered_on'],
            'note' => $row['note'] === null ? null : (string) $row['note'],
            'row_version' => (int) $row['row_version'],
        ];
    }

    /**
     * Zákonný důvod lhůty uložené žádosti — z téže politiky, která lhůtu
     * spočítala; uložený termín se tím nemění.
     *
     * @param array<string,mixed> $row
     */
    private function legalBasis(array $row): string
    {
        try {
            return $this->policy->forRequest(
                (string) $row['request_kind'],
                (string) $row['requester'],
                (string) $row['received_on'],
                $row['period_year'] === null ? null : (int) $row['period_year'],
                $row['stated_due_on'] === null ? null : (string) $row['stated_due_on'],
                $row['requester'] === 'survivor' && $row['death_on'] !== null ? (string) $row['death_on'] : null,
                $row['legacy_kind'] === null ? null : (string) $row['legacy_kind'],
            )['legal_basis'];
        } catch (\InvalidArgumentException | \DomainException) {
            return (string) $row['deadline_rule'];
        }
    }

    /** @return array<string,mixed> */
    private function find(int $supplierId, int $employeeId, int $requestId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_pension_requests
              WHERE supplier_id = ? AND employee_id = ? AND id = ?'
        );
        $statement->execute([$supplierId, $employeeId, $requestId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \OutOfBoundsException('Výzva nebo žádost nenalezena.');
        }

        return $row;
    }

    /** @return array<string,mixed> */
    private function findById(int $supplierId, int $requestId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_pension_requests WHERE supplier_id = ? AND id = ?'
        );
        $statement->execute([$supplierId, $requestId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \OutOfBoundsException('Výzva nebo žádost nenalezena.');
        }

        return $row;
    }

    private function employeeExists(int $supplierId, int $employeeId): bool
    {
        $statement = $this->db->pdo()->prepare('SELECT 1 FROM payroll_employees WHERE supplier_id = ? AND id = ?');
        $statement->execute([$supplierId, $employeeId]);

        return $statement->fetchColumn() !== false;
    }

    private function employment(int $supplierId, int $employeeId, mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $employmentId = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($employmentId)) {
            throw new \InvalidArgumentException('Pracovní vztah musí být kladné celé číslo.');
        }
        $statement = $this->db->pdo()->prepare(
            'SELECT 1 FROM payroll_employments WHERE supplier_id = ? AND employee_id = ? AND id = ?'
        );
        $statement->execute([$supplierId, $employeeId, $employmentId]);
        if ($statement->fetchColumn() === false) {
            throw new \InvalidArgumentException('Pracovní vztah nepatří zaměstnanci.');
        }

        return $employmentId;
    }

    /** @param list<string> $allowed */
    private static function choice(mixed $value, array $allowed, string $label): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new \InvalidArgumentException("„{$label}“ má nepovolenou hodnotu.");
        }

        return $value;
    }

    private static function optionalYear(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $year = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1990, 'max_range' => 2100]]);
        if (!is_int($year)) {
            throw new \InvalidArgumentException('Rok musí být celé číslo 1990 až 2100.');
        }

        return $year;
    }

    private static function optionalDate(mixed $value, string $label): ?string
    {
        return $value === null || $value === '' ? null : self::date($value, $label);
    }

    private static function date(mixed $value, string $label): string
    {
        $parsed = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException("„{$label}“ musí být datum ve tvaru DD. MM. RRRR.");
        }

        return $value;
    }

    private static function text(mixed $value, string $label, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || mb_strlen(trim($value), 'UTF-8') > $max) {
            throw new \InvalidArgumentException("„{$label}“ může mít nejvýš {$max} znaků.");
        }

        return trim($value) === '' ? null : trim($value);
    }
}
