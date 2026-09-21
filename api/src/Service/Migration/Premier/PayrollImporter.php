<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Premier;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollEmploymentRepository;
use MyInvoice\Repository\Payroll\PayrollPersonProfileRepository;
use MyInvoice\Repository\Payroll\PayrollPersonStatutoryEvidenceRepository;
use MyInvoice\Repository\Payroll\PayrollRegistrationIdentityRepository;
use MyInvoice\Repository\PremierImportRepository;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Payroll\Import\Registration\RegistrationImportWriter;
use MyInvoice\Service\Payroll\Migration\PayrollMigrationReferenceTotals;
use MyInvoice\Service\Payroll\Migration\PayrollMigrationReferenceTotalsWriter;
use MyInvoice\Service\Payroll\Migration\PayrollMigrationTakeoverFacts;
use MyInvoice\Service\Payroll\PayrollEmploymentValidator;
use MyInvoice\Service\Payroll\PayrollHistoricalPeriodService;
use MyInvoice\Service\Payroll\PayrollOpeningBalanceService;
use MyInvoice\Service\Payroll\PayrollPersonCreateService;
use MyInvoice\Service\Payroll\PayrollPersonCreateValidator;
use MyInvoice\Service\Payroll\PayrollPersonProfileValidator;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityService;
use PDO;

/**
 * Zaměstnanci a zpracované mzdy z PREMIER ({@see PremierPayroll}).
 *
 * Mzdy jsou v PREMIER zpracované a zaúčtované: jejich zápisy přišly 1:1 s deníkem
 * a převod proto **nezakládá žádný účetní zápis**. Mzdové měsíce se ukládají jako
 * převzaté mzdy předchozího systému ({@see PayrollMigrationReferenceTotalsWriter}, zdroj
 * `other`) - stejná evidence jako u převodu z PAMICA: z ní vzniká převzatý mzdový běh,
 * evidenční list důchodového pojištění za rok přechodu a kontrolní sestava. Počáteční
 * stavy ročních kumulací (roční zúčtování, potvrzení o příjmech) se zapíší za měsíce
 * roku, ve kterém začíná vedení mezd v MyÚčtu, před jeho prvním měsícem.
 *
 * Osoba a vztah jdou toutéž cestou jako ruční založení ({@see PayrollPersonCreateService})
 * a údaje karty (identita, adresa, zákonná evidence, výplatní účet, sjednaná mzda,
 * skončení vztahu) doplňují jen to, co v MyÚčtu chybí. Opakovaný převod nic nezdvojí:
 * osoby, vztahy i měsíce nese mapa převodu.
 *
 * Mzdy se nakonec porovnají s deníkem ({@see PremierPayroll::reconcile()}): hrubé
 * příjmy, pojistné zaměstnance a zaměstnavatele a daň po měsících. Rozdíl je upozornění,
 * převod nezastaví - deník je převedený přesně a mzdy jsou evidence, ne zápisy.
 */
final class PayrollImporter
{
    public const STEP = 'payroll';
    /** Zdroj převzatých mezd; `other` je obecný zdroj pro mzdové systémy bez vlastního podavače. */
    private const SOURCE = 'other';
    private const REFERENCE = 'PREMIER';
    private const NOTE = 'Převzato z PREMIER: ';
    private const SAVEPOINT = 'premier_payroll';
    private const MESSAGE_LIMIT = 20;
    /** Začátek popisu zdroje počátečních stavů, které zapsal tento převod. */
    private const OPENING_REFERENCE = 'PREMIER:';

    private int $messages = 0;

    public function __construct(
        private readonly Connection $db,
        private readonly PremierImportRepository $map,
        private readonly PayrollPersonCreateService $personCreate,
        private readonly PayrollPersonCreateValidator $personValidator,
        private readonly PayrollEmploymentRepository $employments,
        private readonly PayrollEmploymentValidator $employmentValidator,
        private readonly PayrollPersonProfileRepository $profiles,
        private readonly PayrollPersonProfileValidator $profileValidator,
        private readonly PayrollPersonStatutoryEvidenceRepository $statutory,
        private readonly PayrollRegistrationIdentityRepository $registrations,
        private readonly PayrollRegistrationIdentityService $identities,
        private readonly PayrollOpeningBalanceService $openings,
        private readonly PayrollMigrationReferenceTotalsWriter $referenceTotals,
        private readonly PayrollHistoricalPeriodService $historical,
    ) {}

    public function import(PremierContext $ctx): void
    {
        $p = $ctx->protocol;
        $this->messages = 0;
        $payroll = $ctx->payroll ?? PremierPayroll::fromBackup($ctx->backup);
        if (!$payroll->hasData()) {
            return;
        }
        if ($payroll->missingTables !== []) {
            $p->info(self::STEP, 'payroll_tables_missing', 'Záloha neobsahuje tabulky mezd (' . implode(', ', $payroll->missingTables)
                . '), zaměstnanci a mzdy se nepřevedou. Mzdové zápisy jsou v převedeném deníku.');
            $this->reconcile($ctx, $payroll);
            return;
        }
        $lastPeriod = substr($ctx->endsOn(), 0, 7);
        $relations = [];
        foreach ($payroll->relations as $relation) {
            if ($relation['start'] === null || $relation['start'] > $ctx->endsOn()) {
                $p->count(self::STEP, 'employees_later');
                continue;
            }
            $relations[] = $relation;
        }
        $blocker = $this->prerequisite($ctx->supplierId);
        if ($blocker !== null) {
            $months = 0;
            foreach ($relations as $relation) {
                $months += count(array_filter(array_keys($relation['months']), static fn (string $m): bool => $m <= $lastPeriod));
            }
            $p->count(self::STEP, 'months_skipped', $months);
            $p->warn(self::STEP, 'payroll_module_missing', $blocker . ' Zaměstnanci (' . count($relations) . ') a mzdové měsíce (' . $months
                . ') se proto nepřevedly. Mzdové zápisy jsou v převedeném deníku; převod roku po nastavení mezd zopakujte a mzdy se doplní.');
            $this->reconcile($ctx, $payroll);
            return;
        }

        // Měsíce od začátku vedení mezd v MyÚčtu počítá MyÚčto; převzatý údaj by vedle
        // spočítaného stál jako druhé, neověřené číslo za týž měsíc.
        $start = $this->historical->startPeriod($ctx->supplierId);
        $afterStart = 0;
        $totals = [];
        $byEmployee = [];
        foreach ($relations as $relation) {
            $pair = $this->inSavepoint($ctx, $relation, fn (): ?array => $this->relation($ctx, $relation));
            if ($pair === null) {
                continue;
            }
            [$employeeId, $employmentId] = $pair;
            $activity = $this->activityCode($ctx->supplierId, $employmentId);
            foreach ($relation['months'] as $period => $m) {
                if ($period > $lastPeriod) {
                    continue;
                }
                if ($start !== null && $period >= $start) {
                    $afterStart++;
                    continue;
                }
                $totals[] = self::referenceTotals($relation, (string) $period, $m, $employeeId, $employmentId, $activity);
                $key = $relation['key'] . '|' . $period;
                if ($this->map->get($ctx->supplierId, PremierImportRepository::KIND_PAYROLL_MONTH, $key) === null) {
                    $this->map->put($ctx->supplierId, PremierImportRepository::KIND_PAYROLL_MONTH, $key, $employmentId, $ctx->runId);
                    $p->count(self::STEP, 'months');
                } else {
                    $p->count(self::STEP, 'months_existing');
                }
                $byEmployee[$employeeId][(string) $period][] = $m;
            }
        }
        if ($afterStart > 0) {
            $p->count(self::STEP, 'months_after_start', $afterStart);
            $this->info($p, 'months_after_start', "Mzdové měsíce od začátku vedení mezd v MyÚčtu ({$start}) se nepřevzaly (celkem {$afterStart}), počítá je MyÚčto.");
        }
        if ($totals !== []) {
            $this->referenceTotals->store($ctx->supplierId, self::SOURCE, $totals, self::REFERENCE . ' ' . $ctx->backup->ico);
        }
        $this->openingBalances($ctx, $byEmployee, $payroll);
        $this->reconcile($ctx, $payroll);
    }

    /**
     * Bez zapnutých mezd a výchozí mzdové účtárny pracovní vztah založit nejde (stejná
     * podmínka jako u převodu mezd z PAMICA).
     */
    public function prerequisite(int $supplierId): ?string
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT payroll_enabled FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        if ((int) $stmt->fetchColumn() !== 1) {
            return 'Firma nemá zapnutý modul Mzdy (Nastavení → Moduly).';
        }
        if (!$this->db->hasTable('payroll_employer_settings')) {
            return 'Chybí nastavení mezd zaměstnavatele.';
        }
        $office = $pdo->prepare(
            'SELECT 1 FROM payroll_employer_settings s
               JOIN payroll_offices o ON o.supplier_id = s.supplier_id AND o.id = s.default_office_id AND o.is_active = 1
              WHERE s.supplier_id = ?'
        );
        $office->execute([$supplierId]);
        return $office->fetchColumn() === false ? 'Chybí výchozí mzdová účtárna zaměstnavatele (Mzdy → Nastavení).' : null;
    }

    /**
     * Osoba a pracovní vztah: z mapy převodu, převzetím vztahu se stejným osobním číslem
     * a jménem, nebo nově. Pak údaje karty, sjednaná mzda, skončení a zákonné termíny.
     *
     * @param array<string,mixed> $relation
     * @return array{0:int,1:int}|null
     */
    private function relation(PremierContext $ctx, array $relation): ?array
    {
        $p = $ctx->protocol;
        $supplierId = $ctx->supplierId;
        $userId = $ctx->userOrNull();
        $number = (string) $relation['personal_number'];
        $employmentId = $this->map->get($supplierId, PremierImportRepository::KIND_PAYROLL_EMPLOYMENT, (string) $relation['key']);
        $employeeId = $this->map->get($supplierId, PremierImportRepository::KIND_PAYROLL_EMPLOYEE, (string) $relation['person_key']);
        if ($employmentId !== null) {
            $row = $this->employmentById($supplierId, $employmentId);
            if ($row === null) {
                $p->count(self::STEP, 'deleted');
                $this->warn($p, 'employment_deleted', "Osobní číslo {$number}: pracovní vztah převedený dřívějším převodem ve firmě už není, mzdy se k němu nepřevedly.");
                return null;
            }
            $employeeId = (int) $row['employee_id'];
            $p->count(self::STEP, 'existing');
        } else {
            $adopted = $this->adoptable($supplierId, $number, $relation, $employeeId);
            if ($adopted !== null) {
                [$employeeId, $employmentId] = $adopted;
                $p->count(self::STEP, 'matched');
            } elseif ($employeeId !== null) {
                $employmentId = $this->addEmployment($ctx, $employeeId, $relation);
                $p->count(self::STEP, 'employments_created');
            } else {
                [$employeeId, $employmentId] = $this->createPerson($ctx, $relation);
                $p->count(self::STEP, 'employees_created');
                $p->count(self::STEP, 'employments_created');
            }
            if ($this->map->get($supplierId, PremierImportRepository::KIND_PAYROLL_EMPLOYEE, (string) $relation['person_key']) === null) {
                $this->map->put($supplierId, PremierImportRepository::KIND_PAYROLL_EMPLOYEE, (string) $relation['person_key'], $employeeId, $ctx->runId);
            }
            $this->map->put($supplierId, PremierImportRepository::KIND_PAYROLL_EMPLOYMENT, (string) $relation['key'], $employmentId, $ctx->runId);
            if ($relation['relation_type_derived']) {
                $this->info($p, 'relation_type_default', "Osobní číslo {$number}: druh vztahu z kategorie „{$relation['category']}\" nejde určit, "
                    . 'vztah je založený jako pracovní poměr. Zkontrolujte ho na kartě zaměstnance.');
            }
        }

        $this->activate($ctx, $employmentId, $relation);
        $this->detail($ctx, $number, 'Sjednaná mzda', fn (): array => $this->wages($ctx, $employmentId, $relation));
        $this->detail($ctx, $number, 'Údaje o narození a občanství', fn (): array => $this->identity($supplierId, $employeeId, $relation));
        $this->detail($ctx, $number, 'Adresa a kontakt', fn (): array => $this->personCard($supplierId, $employeeId, $relation, $userId));
        $this->detail($ctx, $number, 'Zákonná evidence', fn (): array => $this->statutoryEvidence($ctx, $employeeId, $relation));
        $this->detail($ctx, $number, 'Výplatní účet', fn (): array => $this->payoutAccount($supplierId, $employeeId, $relation, $userId));
        $this->detail($ctx, $number, 'Skončení vztahu', fn (): array => $this->termination($ctx, $employmentId, $relation));
        $this->detail($ctx, $number, 'Zákonné termíny', fn (): array => $this->checklist($ctx, $employmentId, $relation));
        return [$employeeId, $employmentId];
    }

    /**
     * Vztah se stejným osobním číslem, který ve firmě už je (založený ručně nebo jiným
     * importem): převezme se, jen když sedí i jméno osoby. Jinak se nesahá na cizí vztah.
     *
     * @param array<string,mixed> $relation
     * @return array{0:int,1:int}|null
     */
    private function adoptable(int $supplierId, string $number, array $relation, ?int $employeeId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT e.id, e.employee_id, p.full_name FROM payroll_employments e
               JOIN payroll_employees p ON p.id = e.employee_id AND p.supplier_id = e.supplier_id
              WHERE e.supplier_id = ? AND e.code = ?'
        );
        $stmt->execute([$supplierId, $number]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $same = mb_strtolower(trim((string) $row['full_name'])) === mb_strtolower(trim((string) $relation['full_name']));
        if (!$same || ($employeeId !== null && $employeeId !== (int) $row['employee_id'])) {
            return null;
        }
        if (in_array((int) $row['id'], $this->map->targets($supplierId, PremierImportRepository::KIND_PAYROLL_EMPLOYMENT), true)) {
            return null;
        }
        return [(int) $row['employee_id'], (int) $row['id']];
    }

    /**
     * Nová osoba s prvním vztahem. Rodné číslo nebo kód pojišťovny, které kontrola
     * odmítne, osobu nezastaví: založí se bez nich a protokol to řekne.
     *
     * @param array<string,mixed> $relation
     * @return array{0:int,1:int}
     */
    private function createPerson(PremierContext $ctx, array $relation): array
    {
        $number = (string) $relation['personal_number'];
        $name = (string) $relation['full_name'];
        if ($name === '') {
            $name = 'Zaměstnanec ' . $number;
            $this->warn($ctx->protocol, 'person_name_missing', "Osobní číslo {$number}: záloha nemá jméno osoby, zaměstnanec je založený jako „{$name}\". Doplňte jméno na kartě.");
        }
        $input = [
            'full_name' => $name,
            'first_name' => $relation['first_name'],
            'last_name' => $relation['last_name'],
            'birth_date' => $relation['birth_date'],
            'birth_number' => $relation['birth_number'],
            'health_insurer_code' => $relation['insurer_code'],
            'relation_type' => $relation['relation_type'],
            'planned_start_on' => $relation['start'],
            'monthly_gross' => self::firstWage($relation, $ctx->endsOn()),
            'employment_code' => $this->codeAvailable($ctx->supplierId, $number) ? $number : null,
        ];
        $attempts = [$input];
        if ($input['birth_number'] !== null) {
            $attempts[] = ['birth_number' => null] + $input;
        }
        if ($input['health_insurer_code'] !== null) {
            $attempts[] = ['birth_number' => null, 'health_insurer_code' => null] + $input;
        }
        $last = null;
        foreach ($attempts as $index => $attempt) {
            $this->db->pdo()->exec('SAVEPOINT premier_payroll_person');
            try {
                $person = $this->personCreate->create($ctx->supplierId, $attempt, $ctx->userOrNull(), null, null);
                $this->db->pdo()->exec('RELEASE SAVEPOINT premier_payroll_person');
            } catch (\InvalidArgumentException|\DomainException $e) {
                $this->db->pdo()->exec('ROLLBACK TO SAVEPOINT premier_payroll_person');
                $this->db->pdo()->exec('RELEASE SAVEPOINT premier_payroll_person');
                $last = $e;
                continue;
            }
            if ($index > 0) {
                $dropped = $attempt['health_insurer_code'] === null && $input['health_insurer_code'] !== null ? 'rodné číslo ani kód zdravotní pojišťovny' : 'rodné číslo';
                $this->warn($ctx->protocol, 'person_partial', "Osobní číslo {$number}: {$dropped} z PREMIER neprošlo kontrolou ({$last?->getMessage()}), osoba je založená bez nich. Doplňte je na kartě.");
            }
            if ($input['employment_code'] === null) {
                $this->info($ctx->protocol, 'personal_number_taken', "Osobní číslo {$number} už ve firmě má jiná osoba, vztah dostal číslo přidělené aplikací.");
            }
            $employeeId = (int) ($person['id'] ?? 0);
            $employment = $this->latestEmployment($ctx->supplierId, $employeeId)
                ?? throw new \LogicException('Nově založený pracovní vztah nebyl nalezen.');
            return [$employeeId, (int) $employment['id']];
        }
        throw $last ?? new \LogicException('Osobu se nepodařilo založit.');
    }

    /**
     * Další vztah osoby, která už ve firmě je (souběh nebo opakovaný nástup).
     *
     * @param array<string,mixed> $relation
     */
    private function addEmployment(PremierContext $ctx, int $employeeId, array $relation): int
    {
        $number = (string) $relation['personal_number'];
        $validated = $this->personValidator->validate([
            'full_name' => (string) ($relation['full_name'] ?: 'Zaměstnanec ' . $number),
            'relation_type' => $relation['relation_type'],
            'planned_start_on' => $relation['start'],
            'monthly_gross' => self::firstWage($relation, $ctx->endsOn()),
            'employment_code' => $this->codeAvailable($ctx->supplierId, $number) ? $number : null,
        ]);
        $employment = $validated['employment'];
        $employment['terms']['is_primary'] = !$this->hasPrimary($ctx->supplierId, $employeeId);
        $employment['code'] = $validated['employment_code'] ?? '';
        $created = $this->employments->create($ctx->supplierId, $employeeId, $employment, $ctx->userOrNull(), null, null);
        return (int) $created['id'];
    }

    /** @param array<string,mixed> $relation */
    private function activate(PremierContext $ctx, int $employmentId, array $relation): void
    {
        $row = $this->employmentById($ctx->supplierId, $employmentId);
        $start = (string) $relation['start'];
        if ($row === null || $row['status'] !== 'planned' || $start > date('Y-m-d')) {
            return;
        }
        $this->employments->transition($ctx->supplierId, $employmentId, 'active', (int) $row['row_version'], $start,
            self::NOTE . 'vztah vedený v PREMIER od ' . self::czechDate($start) . '.', $ctx->userOrNull(), null, null);
    }

    /**
     * Změny sjednané mzdy z historie PREMIER (`PERS_HYS`) jako verze podmínek vztahu od
     * měsíce změny. První mzdu dostal vztah při založení.
     *
     * @param array<string,mixed> $relation
     * @return array<string,int>
     */
    private function wages(PremierContext $ctx, int $employmentId, array $relation): array
    {
        $written = 0;
        foreach ($relation['wages'] as $from => $amount) {
            if ($from > $ctx->endsOn()) {
                continue;
            }
            $row = $this->employmentById($ctx->supplierId, $employmentId);
            if ($row === null || !in_array($row['status'], ['planned', 'active', 'suspended'], true)) {
                break;
            }
            $current = $this->employments->currentTerms($ctx->supplierId, $employmentId);
            $minor = (int) round(((float) $amount) * 100);
            if ($current === null || (string) $current['effective_from'] >= $from || (int) ($current['monthly_gross_minor'] ?? 0) === $minor) {
                continue;
            }
            $terms = $this->employmentValidator->terms(
                RegistrationImportWriter::termsBody($current, 'Sjednaná mzda z PREMIER.') + ['effective_from' => (string) $from],
                $this->employments->currentCzIscoCode($ctx->supplierId, $employmentId),
                $this->employments->currentOtherWithholdingEligibility($ctx->supplierId, $employmentId),
                $this->employments->currentRelationType($ctx->supplierId, $employmentId),
            );
            $this->employments->addTerms($ctx->supplierId, $employmentId, $terms, (int) $row['row_version'], $ctx->userOrNull(), null, null, true, $minor);
            $written++;
        }
        return $written > 0 ? ['wage_changes' => $written] : [];
    }

    /**
     * @param array<string,mixed> $relation
     * @return array<string,int>
     */
    private function identity(int $supplierId, int $employeeId, array $relation): array
    {
        $identity = $this->registrations->identityAt($supplierId, $employeeId, date('Y-m-d'));
        if ($identity === null) {
            return [];
        }
        $merged = [];
        $changed = false;
        foreach (['title_prefix', 'title_suffix', 'birth_date', 'birth_place', 'birth_country_code', 'citizenship_country_code', 'sex'] as $field) {
            $current = $identity[$field] ?? null;
            $current = $current === null || $current === '' ? null : (string) $current;
            $incoming = $field === 'birth_date' ? $relation['birth_date'] : ($relation['identity'][$field] ?? null);
            if ($current === null && is_string($incoming) && $incoming !== '') {
                $current = $incoming;
                $changed = true;
            }
            $merged[$field] = $current;
        }
        if (!$changed) {
            return [];
        }
        $this->identities->saveIdentityFacts($supplierId, $employeeId, (int) $identity['id'], (int) $identity['row_version'], $merged);
        return ['identity' => 1];
    }

    /**
     * Adresa trvalého pobytu, kontakt a rodné příjmení - jen do prázdné karty.
     *
     * @param array<string,mixed> $relation
     * @return array<string,int>
     */
    private function personCard(int $supplierId, int $employeeId, array $relation, ?int $userId): array
    {
        $current = $this->profiles->get($supplierId, $employeeId);
        if ($current === null) {
            return [];
        }
        $today = date('Y-m-d');
        $from = min((string) $relation['start'], $today);
        $addresses = [];
        if (is_array($relation['residence']) && $current['addresses'] === []) {
            $addresses[] = $relation['residence'] + ['id' => null, 'address_type' => 'residence', 'effective_from' => $from, 'effective_to' => null];
        }
        $contacts = [];
        $hasContact = false;
        foreach ($current['contacts'] as $row) {
            $hasContact = $hasContact || (!empty($row['is_active']) && !empty($row['is_primary']));
        }
        if (!$hasContact) {
            foreach (['email' => $relation['email'], 'phone' => $relation['phone']] as $type => $value) {
                if (is_string($value)) {
                    $contacts[] = ['id' => null, 'contact_type' => $type, 'value' => $value, 'is_primary' => true, 'is_active' => true];
                }
            }
        }
        $identity = [];
        if (is_string($relation['birth_surname']) && mb_strtolower($relation['birth_surname']) !== mb_strtolower((string) $relation['last_name'])) {
            $version = $current['identity_history'][0] ?? null;
            if ($version !== null && ($version['birth_surname_masked'] ?? null) === null) {
                $identity[] = [
                    'id' => $version['id'],
                    'full_name' => $version['full_name'],
                    'first_name' => $version['first_name'],
                    'last_name' => $version['last_name'],
                    'birth_surname' => $relation['birth_surname'],
                    'effective_from' => $version['effective_from'],
                    'effective_to' => $version['effective_to'],
                ];
            }
        }
        if ($addresses === [] && $contacts === [] && $identity === []) {
            return [];
        }
        $this->profiles->save($supplierId, $employeeId, $this->profileValidator->validate([
            'row_version' => $current['row_version'],
            'profile_status' => $current['profile_status'] === 'missing' ? 'setup' : $current['profile_status'],
            'payout_method' => $current['payout_method'],
            'partner_settlement_account_code' => $current['partner_settlement_account_code'],
            'cash_allocation_basis_points' => $current['cash_allocation_basis_points'],
            'payout_effective_on' => $current['payout_effective_on'] ?? $today,
            'secure_delivery_channel' => $current['secure_delivery_channel'],
            'identity_history' => $identity,
            'addresses' => $addresses,
            'contacts' => $contacts,
            'identifiers' => [],
            'accounts' => [],
        ]), $current['row_version'], $userId, null, null);
        return ['person_card' => 1];
    }

    /**
     * Daňová rezidence, prohlášení poplatníka po měsících mezd, zdravotní pojištění
     * a příslušnost k sociálnímu pojištění - jen do prázdných řad zákonné evidence.
     *
     * @param array<string,mixed> $relation
     * @return array<string,int>
     */
    private function statutoryEvidence(PremierContext $ctx, int $employeeId, array $relation): array
    {
        $today = date('Y-m-d');
        $view = $this->statutory->editorView($ctx->supplierId, $employeeId, $today);
        if ($view === null) {
            return [];
        }
        /** @var array<string,list<array<string,mixed>>> $sections */
        $sections = $view['sections'];
        $from = (string) $relation['start'];
        $counts = [];
        if (($sections['tax_residences'] ?? []) === []) {
            if ($relation['non_resident'] === true) {
                $this->warn($ctx->protocol, 'tax_residence_manual', "Osobní číslo {$relation['personal_number']}: PREMIER vede osobu jako daňového nerezidenta. Daňovou rezidenci doplňte ručně.");
            } else {
                $sections['tax_residences'] = [[
                    'residence' => 'czech-resident',
                    'country_code' => 'CZ',
                    'evidence_reference' => 'premier:per_main:rezident',
                    'effective_from' => $from,
                    'effective_to' => null,
                    'evidence_note' => self::NOTE . 'osoba není v PREMIER vedená jako daňový nerezident.',
                ]];
                $counts['tax_residence'] = 1;
            }
        }
        $declarations = self::declarations($relation, $ctx->endsOn());
        if (($sections['tax_declarations'] ?? []) === [] && $declarations !== []) {
            $sections['tax_declarations'] = array_map(static fn (array $run): array => [
                'status' => $run['status'],
                'evidence_reference' => 'premier:mzdy:' . $run['period'],
                'effective_from' => $run['from'],
                'effective_to' => $run['to'],
                'evidence_note' => self::NOTE . ($run['status'] === 'signed' ? 'podepsané' : 'nepodepsané') . ' prohlášení poplatníka od mzdy za ' . $run['period'] . '.',
            ], $declarations);
            $counts['tax_declarations'] = 1;
        }
        if (($sections['health_coverages'] ?? []) === [] && is_string($relation['insurer_code'])) {
            $sections['health_coverages'] = [[
                'jurisdiction' => 'czech_regime_verified',
                'foreign_country_code' => null,
                'jurisdiction_evidence_reference' => null,
                'insurer_status' => 'verified',
                'insurer_code' => $relation['insurer_code'],
                'insurer_evidence_reference' => null,
                'health_evidence_document_id' => null,
                'health_evidence_document_sha256' => null,
                'effective_from' => $from,
                'effective_to' => null,
                'evidence_note' => self::NOTE . 'zdravotní pojišťovna ' . $relation['insurer_code'] . '.',
            ]];
            $counts['health_coverage'] = 1;
        }
        if (($sections['social_jurisdictions'] ?? []) === []) {
            if ($relation['foreign_legislation'] === true) {
                $this->warn($ctx->protocol, 'social_jurisdiction_manual', "Osobní číslo {$relation['personal_number']}: PREMIER vede osobu jako vyslanou nebo pojištěnou v cizině. Příslušnost k sociálnímu pojištění doplňte ručně.");
            } else {
                $sections['social_jurisdictions'] = [[
                    'jurisdiction' => 'czech_regime_verified',
                    'foreign_country_code' => null,
                    'jurisdiction_evidence_reference' => null,
                    'a1_status' => 'not_applicable',
                    'a1_certificate_reference' => null,
                    'a1_valid_until' => null,
                    'effective_from' => $from,
                    'effective_to' => null,
                    'evidence_note' => self::NOTE . 'osoba nepodléhá v PREMIER cizím právním předpisům.',
                ]];
                $counts['social_jurisdiction'] = 1;
            }
        }
        if ($counts === []) {
            return [];
        }
        $this->statutory->save($ctx->supplierId, $employeeId, ['sections' => $sections], $today, $ctx->userOrNull(), null, null);
        return $counts;
    }

    /**
     * Výplatní účet z karty vztahu. Neověřený: PREMIER nevede datum výplaty, kterým by šlo
     * doložit, že na účet mzda opravdu chodila.
     *
     * @param array<string,mixed> $relation
     * @return array<string,int>
     */
    private function payoutAccount(int $supplierId, int $employeeId, array $relation, ?int $userId): array
    {
        $account = $relation['account'];
        if (!is_array($account)) {
            return [];
        }
        $current = $this->profiles->get($supplierId, $employeeId);
        if ($current === null || $current['accounts'] !== []) {
            return [];
        }
        $today = date('Y-m-d');
        $this->profiles->save($supplierId, $employeeId, $this->profileValidator->validate([
            'row_version' => $current['row_version'],
            'profile_status' => $current['profile_status'] === 'missing' ? 'setup' : $current['profile_status'],
            'payout_method' => $current['payout_method'] === 'cash' ? 'bank' : $current['payout_method'],
            'partner_settlement_account_code' => $current['partner_settlement_account_code'],
            'cash_allocation_basis_points' => $current['cash_allocation_basis_points'],
            'payout_effective_on' => $current['payout_effective_on'] ?? $today,
            'secure_delivery_channel' => $current['secure_delivery_channel'],
            'identity_history' => [],
            'addresses' => [],
            'contacts' => [],
            'identifiers' => [],
            'accounts' => [[
                'id' => null,
                'label' => 'Výplatní účet z PREMIER',
                'bank_account' => $account['account'] . '/' . $account['bank_code'],
                'allocation_basis_points' => 10000,
                'effective_from' => min((string) $relation['start'], $today),
                'effective_to' => null,
                'is_active' => true,
            ]],
        ]), $current['row_version'], $userId, null, null);
        return ['payout_accounts_to_verify' => 1];
    }

    /**
     * @param array<string,mixed> $relation
     * @return array<string,int>
     */
    private function termination(PremierContext $ctx, int $employmentId, array $relation): array
    {
        $end = $relation['end'];
        if (!is_string($end) || $end > $ctx->endsOn() || $end > date('Y-m-d') || $end < (string) $relation['start']) {
            return [];
        }
        $row = $this->employmentById($ctx->supplierId, $employmentId);
        if ($row === null || !in_array($row['status'], ['active', 'suspended'], true)) {
            return [];
        }
        $this->employments->transition($ctx->supplierId, $employmentId, 'ended', (int) $row['row_version'], $end,
            self::NOTE . 'vztah skončil ' . self::czechDate($end) . '.', $ctx->userOrNull(), null, null);
        return ['ended' => 1];
    }

    /**
     * Položky zákonných termínů, které proběhly v PREMIER: smlouva vztahu, přihláška
     * zdravotní pojišťovně (přijaté oznámení) a doklad o skončení.
     *
     * @param array<string,mixed> $relation
     * @return array<string,int>
     */
    private function checklist(PremierContext $ctx, int $employmentId, array $relation): array
    {
        $notes = ['employment_contract' => self::NOTE . 'vztah vedený v předchozím mzdovém systému, nástup ' . self::czechDate((string) $relation['start']) . '.'];
        if ($relation['insurer_registered'] === true) {
            $notes['health_insurance_registration'] = self::NOTE . 'přihláška zdravotní pojišťovně přijatá v PREMIER.';
        }
        $row = $this->employmentById($ctx->supplierId, $employmentId);
        if ($row !== null && $row['status'] === 'ended' && is_string($relation['end'])) {
            $notes['termination_document'] = self::NOTE . 'vztah skončil ' . self::czechDate($relation['end']) . '.';
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT item_key, row_version FROM payroll_employment_checklist_items
              WHERE supplier_id = ? AND employment_id = ? AND status = 'pending' ORDER BY id"
        );
        $stmt->execute([$ctx->supplierId, $employmentId]);
        $done = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $key = (string) $item['item_key'];
            if (!isset($notes[$key])) {
                continue;
            }
            try {
                $this->employments->updateChecklist($ctx->supplierId, $employmentId, $key, (int) $item['row_version'], 'completed', $notes[$key], $ctx->userOrNull(), null, null);
                $done++;
            } catch (\DomainException|\InvalidArgumentException|\RuntimeException) {
                continue;
            }
        }
        return $done > 0 ? ['checklist_completed' => $done] : [];
    }

    /**
     * Počáteční stavy ročních kumulací za měsíce roku, ve kterém začíná vedení mezd
     * v MyÚčtu, před jeho prvním měsícem - jen souvislá řada a jen tam, kde stavy nejsou.
     *
     * @param array<int,array<string,list<array<string,mixed>>>> $byEmployee
     */
    private function openingBalances(PremierContext $ctx, array $byEmployee, PremierPayroll $payroll): void
    {
        $p = $ctx->protocol;
        $start = $this->historical->startPeriod($ctx->supplierId);
        if ($start === null) {
            $last = null;
            foreach ($payroll->relations as $relation) {
                foreach (array_keys($relation['months']) as $period) {
                    $last = $last === null || $period > $last ? (string) $period : $last;
                }
            }
            if ($last !== null && $byEmployee !== []) {
                $next = (new \DateTimeImmutable($last . '-01'))->modify('+1 month')->format('Y-m');
                $this->info($p, 'payroll_start_missing', "Firma nemá nastavený začátek vedení mezd v MyÚčtu (Mzdy → Nastavení). Poslední mzdy zpracované v PREMIER jsou za {$last}, "
                    . "začátek tedy nejspíš bude {$next}. Po jeho nastavení převod roku zopakujte: doplní počáteční stavy ročních kumulací za měsíce před ním.");
            }
            return;
        }
        $startYear = (int) substr($start, 0, 4);
        $startMonth = (int) substr($start, 5, 2);
        if ($startYear > $ctx->year) {
            return;
        }
        foreach ($byEmployee as $employeeId => $periods) {
            $months = [];
            foreach ($periods as $period => $rows) {
                if ((int) substr($period, 0, 4) !== $startYear || (int) substr($period, 5, 2) >= $startMonth) {
                    continue;
                }
                $sums = ['social' => 0.0, 'health' => 0.0, 'health_employee' => 0.0, 'health_employer' => 0.0, 'health_top_up' => 0.0, 'advance_base' => 0.0, 'advance_tax' => 0.0, 'withholding_base' => 0.0, 'withholding_tax' => 0.0,
                    'non_refundable' => 0.0, 'child' => 0.0, 'bonus' => 0.0];
                foreach ($rows as $m) {
                    $sums['social'] += $m['social_base'];
                    $sums['health'] += $m['health_base'];
                    $sums['health_employee'] += $m['employee_health'];
                    $sums['health_employer'] += $m['employer_health'];
                    $sums['health_top_up'] += $m['health_top_up'];
                    $sums['advance_base'] += $m['advance_base'];
                    $sums['advance_tax'] += $m['advance_tax'];
                    $sums['withholding_base'] += $m['withholding_base'];
                    $sums['withholding_tax'] += $m['withholding_tax'];
                    $sums['non_refundable'] += $m['non_refundable'];
                    $sums['child'] += $m['child'];
                    $sums['bonus'] += $m['tax_bonus'];
                }
                $months[(int) substr($period, 5, 2)] = $sums;
            }
            if ($months === []) {
                continue;
            }
            ksort($months);
            $this->inSavepoint($ctx, ['personal_number' => (string) $employeeId], function () use ($ctx, $employeeId, $months, $startYear): void {
                // Stavy zadané jinak než tímto převodem (ručně, z hlášení) převod nepřepisuje.
                // Vlastní stavy uloží znovu: shodná čísla nic nezapíšou, změněná záloha je
                // opraví novou verzí.
                $current = $this->openings->current($ctx->supplierId, $employeeId, $startYear);
                $existing = array_filter($current['openings'], static fn (?int $id): bool => $id !== null);
                if ($existing !== [] && !str_starts_with($current['source_reference'], self::OPENING_REFERENCE)) {
                    $ctx->protocol->count(self::STEP, 'openings_existing');
                    return;
                }
                $numbers = array_keys($months);
                if (count($numbers) !== max($numbers) - min($numbers) + 1) {
                    $this->warn($ctx->protocol, 'openings_gap', "Zaměstnanec {$employeeId}: mzdy v PREMIER před začátkem vedení mezd nejsou za souvislou řadu měsíců, počáteční stavy kumulací zadejte ručně.");
                    return;
                }
                $rows = [];
                foreach ($months as $month => $sums) {
                    $rows[] = [
                        'month' => $month,
                        'social_assessment_base_minor_units' => self::minor($sums['social']),
                        'health_assessment_base_minor_units' => self::minor($sums['health']),
                        'health_employee_contribution_minor_units' => self::minor($sums['health_employee']),
                        'health_employer_contribution_minor_units' => self::minor($sums['health_employer']),
                        'health_minimum_top_up_minor_units' => self::minor($sums['health_top_up']),
                        'advance_base_minor_units' => self::minor($sums['advance_base']),
                        'advance_tax_minor_units' => self::minor($sums['advance_tax']),
                        'withholding_base_minor_units' => self::minor($sums['withholding_base']),
                        'withholding_tax_minor_units' => self::minor($sums['withholding_tax']),
                        'applied_non_refundable_credits_minor_units' => self::minor($sums['non_refundable']),
                        'applied_child_credit_minor_units' => self::minor($sums['child']),
                        'tax_bonus_minor_units' => self::minor($sums['bonus']),
                        'bonus_qualifying_income_minor_units' => self::minor($sums['advance_base']),
                    ];
                }
                $saved = $this->openings->save($ctx->supplierId, $employeeId, $startYear, $rows,
                    sprintf('%s zpracované mzdy %d. až %d. měsíc %d', self::OPENING_REFERENCE, min($numbers), max($numbers), $startYear), $ctx->userOrNull());
                $ctx->protocol->count(self::STEP, $saved['openings'] == $current['openings'] ? 'openings_existing' : 'openings');
            });
        }
    }

    /** Mzdy roku převodu proti deníku PREMIER. */
    private function reconcile(PremierContext $ctx, PremierPayroll $payroll): void
    {
        $p = $ctx->protocol;
        $months = PremierPayroll::reconcile($payroll->monthTotals($ctx->year), PremierPayroll::ledgerTotals($ctx->journal, $ctx->year));
        if ($months === []) {
            return;
        }
        $p->set('payroll_reconciliation', [['year' => $ctx->year, 'ok' => array_filter($months, static fn (array $m): bool => !$m['ok']) === [], 'months' => $months]]);
        $p->setCount(self::STEP, 'reconciled_months', count($months));
        $labels = ['gross' => 'hrubé příjmy', 'employee_insurance' => 'pojistné zaměstnance', 'employer_insurance' => 'pojistné zaměstnavatele', 'tax' => 'daň'];
        $diffs = 0;
        foreach ($months as $m) {
            if ($m['ok']) {
                continue;
            }
            $diffs++;
            $parts = [];
            foreach ($m['diffs'] as $key => $difference) {
                $parts[] = sprintf('%s o %s Kč (mzdy %s, deník %s)', $labels[$key], self::money($difference), self::money($m['payroll'][$key]), self::money($m['ledger'][$key]));
            }
            $this->warn($p, 'payroll_ledger_diff', "{$m['period']}: mzdy PREMIER a deník se liší - " . implode(', ', $parts) . '.', ['period' => $m['period']]);
        }
        if ($diffs > 0) {
            $p->setCount(self::STEP, 'reconciliation_diffs', $diffs);
            return;
        }
        $p->info(self::STEP, 'payroll_reconciled', sprintf('Mzdy roku %d sedí na deník v %d měsících: hrubé příjmy, pojistné zaměstnance i zaměstnavatele a daň.', $ctx->year, count($months)));
    }

    /**
     * @param array<string,mixed> $relation
     * @param array<string,mixed> $m
     */
    private static function referenceTotals(array $relation, string $period, array $m, int $employeeId, int $employmentId, ?string $activity): PayrollMigrationReferenceTotals
    {
        $end = is_string($relation['end']) && $relation['end'] >= (string) $relation['start'] ? $relation['end'] : null;
        return new PayrollMigrationReferenceTotals(
            $period,
            'premier:' . $relation['person_key'],
            'premier:' . $relation['key'],
            $employeeId,
            $employmentId,
            self::minor($m['gross']),
            self::minor($m['net']),
            self::minor($m['social_base']),
            self::minor($m['health_base']),
            self::minor($m['employee_social']),
            self::minor($m['employee_health']),
            self::minor($m['employer_social']),
            self::minor($m['employer_health']),
            self::minor($m['advance_tax']),
            self::minor($m['withholding_tax']),
            self::minor($m['tax_bonus']),
            new PayrollMigrationTakeoverFacts(
                relationshipStartDate: $relation['start'],
                relationshipEndDate: $end,
                relationType: $relation['relation_type'],
                activityCode: $activity,
                pensionParticipation: $m['pension_participation'],
                insuranceDays: $m['insurance_days'],
                excludedDays: $m['excluded_days'],
                workedDaysHundredths: (int) round($m['worked_days'] * 100),
                workedMinutes: $m['worked_minutes'],
                deductionsMinor: self::minor($m['deductions']),
                netPayableMinor: self::minor($m['net_payable']),
            ),
        );
    }

    /**
     * Prohlášení poplatníka jako souvislé úseky stejného stavu po měsících mezd.
     *
     * @param array<string,mixed> $relation
     * @return list<array{from:string,to:?string,status:string,period:string}>
     */
    private static function declarations(array $relation, string $until): array
    {
        $runs = [];
        foreach ($relation['months'] as $period => $m) {
            if ($period . '-01' > $until) {
                break;
            }
            $status = $m['signed'] === true ? 'signed' : 'not-signed';
            $last = array_key_last($runs);
            if ($last !== null && $runs[$last]['status'] === $status) {
                continue;
            }
            $from = max($period . '-01', (string) $relation['start']);
            if ($last !== null) {
                $runs[$last]['to'] = (new \DateTimeImmutable($from))->modify('-1 day')->format('Y-m-d');
            }
            $runs[] = ['from' => $from, 'to' => null, 'status' => $status, 'period' => (string) $period];
        }
        return $runs;
    }

    /** @param array<string,mixed> $relation */
    private static function firstWage(array $relation, string $until): ?int
    {
        foreach ($relation['wages'] as $from => $amount) {
            if ($from <= $until) {
                return (int) round((float) $amount);
            }
        }
        return null;
    }

    /**
     * Každá osoba ve vlastním savepointu: chyba jedné osoby zbytek nezastaví.
     *
     * @template T
     * @param array<string,mixed> $relation
     * @param callable():T $work
     * @return T|null
     */
    private function inSavepoint(PremierContext $ctx, array $relation, callable $work): mixed
    {
        $pdo = $this->db->pdo();
        $pdo->exec('SAVEPOINT ' . self::SAVEPOINT);
        try {
            $result = $work();
            $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            return $result;
        } catch (PremierException|\PDOException $e) {
            $pdo->exec('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT);
            throw $e;
        } catch (\InvalidArgumentException|\DomainException|\RuntimeException $e) {
            $pdo->exec('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT);
            $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            $ctx->protocol->count(self::STEP, 'failed');
            $this->warn($ctx->protocol, 'person_failed', "Osobní číslo {$relation['personal_number']}: zaměstnance se nepodařilo převést - {$e->getMessage()}");
            return null;
        }
    }

    /** Údaj karty ve vlastním savepointu: když neprojde kontrolou, zbytek se zapíše. */
    private function detail(PremierContext $ctx, string $number, string $label, callable $work): void
    {
        $pdo = $this->db->pdo();
        $pdo->exec('SAVEPOINT premier_payroll_detail');
        try {
            foreach ($work() as $key => $count) {
                $ctx->protocol->count(self::STEP, (string) $key, $count);
            }
            $pdo->exec('RELEASE SAVEPOINT premier_payroll_detail');
        } catch (\PDOException $e) {
            $pdo->exec('ROLLBACK TO SAVEPOINT premier_payroll_detail');
            throw $e;
        } catch (\InvalidArgumentException|\DomainException|\RuntimeException $e) {
            $pdo->exec('ROLLBACK TO SAVEPOINT premier_payroll_detail');
            $pdo->exec('RELEASE SAVEPOINT premier_payroll_detail');
            $ctx->protocol->count(self::STEP, 'details_failed');
            $this->warn($ctx->protocol, 'detail_failed', "Osobní číslo {$number}: {$label} se nepřevzal - {$e->getMessage()}");
        }
    }

    /** @return array{id:int,employee_id:int,status:string,row_version:int}|null */
    private function employmentById(int $supplierId, int $employmentId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, employee_id, status, row_version FROM payroll_employments WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$supplierId, $employmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : ['id' => (int) $row['id'], 'employee_id' => (int) $row['employee_id'], 'status' => (string) $row['status'], 'row_version' => (int) $row['row_version']];
    }

    /** @return array<string,mixed>|null */
    private function latestEmployment(int $supplierId, int $employeeId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM payroll_employments WHERE supplier_id = ? AND employee_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$supplierId, $employeeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function codeAvailable(int $supplierId, string $code): bool
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,63}$/', $code) !== 1) {
            return false;
        }
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM payroll_employments WHERE supplier_id = ? AND code = ?');
        $stmt->execute([$supplierId, $code]);
        return $stmt->fetchColumn() === false;
    }

    private function hasPrimary(int $supplierId, int $employeeId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM payroll_employments WHERE supplier_id = ? AND employee_id = ? AND is_primary = 1 AND status IN ('planned', 'active', 'suspended')"
        );
        $stmt->execute([$supplierId, $employeeId]);
        return $stmt->fetchColumn() !== false;
    }

    private function activityCode(int $supplierId, int $employmentId): ?string
    {
        $terms = $this->employments->currentTerms($supplierId, $employmentId);
        $code = is_array($terms) ? trim((string) ($terms['activity_code'] ?? '')) : '';
        return $code === '' ? null : $code;
    }

    /** @param array<string,mixed> $context */
    private function warn(ImportProtocol $p, string $code, string $text, array $context = []): void
    {
        if ($this->messages++ < self::MESSAGE_LIMIT) {
            $p->warn(self::STEP, $code, $text, $context);
            return;
        }
        $p->count(self::STEP, 'messages_truncated');
        $p->finish(self::STEP, 'warning');
    }

    private function info(ImportProtocol $p, string $code, string $text): void
    {
        if ($this->messages++ < self::MESSAGE_LIMIT) {
            $p->info(self::STEP, $code, $text);
            return;
        }
        $p->count(self::STEP, 'messages_truncated');
    }

    private static function minor(float $value): int
    {
        return (int) round($value * 100);
    }

    private static function money(float $value): string
    {
        return number_format($value, 2, ',', ' ');
    }

    private static function czechDate(string $iso): string
    {
        return (new \DateTimeImmutable($iso))->format('j. n. Y');
    }
}
