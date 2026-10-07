<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Sickness;

use MyInvoice\Repository\Payroll\PayrollSicknessCaseRepository;

/**
 * Evidence případů dávek nemocenského pojištění.
 *
 * Případ vzniká DŘÍV než podání a žije i tehdy, když ho nikdo nepodá — přesně
 * proto tu je. Lhůta podle § 97 odst. 2 zák. č. 187/2006 Sb. běží od 15. dne
 * trvání dočasné pracovní neschopnosti bez ohledu na to, jestli si toho někdo
 * všiml; kdyby existovala jen jako vedlejší produkt podání, neuhlídal by ji
 * nikdo.
 *
 * Služba vědomě NEUMÍ nastavit stav `accepted` přímo. Povinnost je splněná
 * PŘEDÁNÍM územní správě sociálního zabezpečení, takže přijetí se zapisuje jen
 * přes {@see self::recordReceipt()}, vždy u konkrétního podání (NEMPRI, HZUPN)
 * a vždy se dnem z protokolu.
 *
 * ## Co se po vyřízení podání ještě smí měnit
 *
 * NEMPRI a HZUPN mají vlastní stav ({@see SicknessDocumentStatus}). Vyřízené
 * podání zamkne jen SVOJE údaje: přijaté NEMPRI nesmí zamknout návrat do
 * práce, dny práce ani konec neschopnosti, ze kterých se teprve sestaví HZUPN.
 * Číslo rozhodnutí a příznak opravného podání se nezamykají nikdy, a když je
 * opravné podání zaškrtnuté, odemknou se i údaje vyřízeného podání. Zamyká se
 * jen skutečná ZMĚNA hodnoty: editor posílá celý formulář a nezměněné pole
 * vyřízeného podání nesmí uložení shodit.
 */
final readonly class SicknessCaseService
{
    /** Případ evidovaný v MyÚčtu. */
    public const SOURCE_MYUCTO = 'myucto';
    /** Případ převzatý z předchozího mzdového programu nebo z doby před ním. */
    public const SOURCE_PREDECESSOR = 'predecessor';

    /** @var list<string> */
    public const SOURCES = [self::SOURCE_MYUCTO, self::SOURCE_PREDECESSOR];

    /**
     * Důvod převedení na jinou práci (§ 19 odst. 6 zák. č. 187/2006 Sb.):
     * těhotenství, mateřství, kojení.
     *
     * @var list<string>
     */
    public const TRANSFER_REASONS = ['pregnancy', 'maternity', 'breastfeeding'];

    /**
     * Údaje HZUPN u nemocenského (§ 97 odst. 3): ukončení neschopnosti, návrat
     * do práce a dny práce v době neschopnosti. U jiných dávek HZUPN není a tytéž
     * sloupce patří k NEMPRI (podklady pro výplatu).
     *
     * @var list<string>
     */
    private const HZUPN_FIELDS = [
        'incapacity_to',
        'issued_on',
        'returned_to_work',
        'return_reason',
        'returned_on',
        'hours_worked_last_day',
        'shift_hours_last_day',
        'work_days',
    ];

    /** Údaje společné oběma podáním; zamknou se až s posledním z nich. */
    private const SHARED_FIELDS = ['ossz_code', 'foreign_case', 'slovak_case', 'additional_note'];

    /** Nezamyká se nikdy: bez nich nejde podat opravné podání. */
    private const ALWAYS_EDITABLE = ['decision_number', 'correction'];

    /**
     * Sloupce, které smí zapsat klient. Whitelist, ne blacklist: stavy podání,
     * dny doručení, obě vazby na podání i `row_version` musí zůstat mimo
     * dosah HTTP požadavku, jinak by šlo prohlásit povinnost za splněnou bez
     * jediného odeslaného bajtu.
     *
     * @var array<string,string>
     */
    private const EDITABLE = [
        'ossz_code' => 'int',
        'decision_number' => 'text',
        'foreign_case' => 'bool',
        'slovak_case' => 'bool',
        'correction' => 'bool',
        'incapacity_from' => 'date',
        'incapacity_to' => 'date',
        'issued_on' => 'date',
        'payroll_payment_date' => 'date',
        'worked_on_decisive_day' => 'bool',
        'hours_worked' => 'decimal',
        'daily_working_hours' => 'decimal',
        'small_scope_income_minor' => 'int',
        'receives_pension' => 'bool',
        'pension_kind' => 'code',
        'is_student' => 'bool',
        'within_school_holidays' => 'nullable_bool',
        'first_employment_free_time' => 'bool',
        'unpaid_leave' => 'bool',
        'unpaid_leave_from' => 'date',
        'unpaid_leave_to' => 'date',
        'starts_maternity' => 'nullable_bool',
        'child_birth_date' => 'date',
        'transferred_other_work' => 'bool',
        'transferred_on' => 'date',
        'transfer_reason' => 'transfer_reason',
        'enforcement' => 'bool',
        'insolvency' => 'bool',
        'returned_to_work' => 'nullable_bool',
        'return_reason' => 'text',
        'returned_on' => 'date',
        'hours_worked_last_day' => 'decimal',
        'shift_hours_last_day' => 'decimal',
        'additional_note' => 'text',
        'action_start' => 'bool',
        'action_continuation' => 'bool',
        'action_end' => 'bool',
        'application_from' => 'date',
        'application_to' => 'date',
        'cared_dependant_id' => 'id',
        'cared_first_name' => 'text',
        'cared_last_name' => 'text',
        'cared_birth_date' => 'date',
        'care_reason' => 'care_reason',
        'school_name' => 'text',
        'school_business_id' => 'text',
        'shared_household' => 'nullable_bool',
        'lone_caregiver' => 'nullable_bool',
        'child_under_16' => 'nullable_bool',
        'other_maternity_claim' => 'nullable_bool',
        'other_parental_claim' => 'nullable_bool',
        'other_person_s57' => 'nullable_bool',
        'cared_personally' => 'nullable_bool',
        'care_days' => 'periods',
        'relationship_code' => 'code',
        'alternation' => 'nullable_bool',
        'long_term_care_consent' => 'ltc_consent',
        'long_term_care_consent_on' => 'date',
        'long_term_care_refusal_reason' => 'text',
        'paternity_reason' => 'code',
        'maternity_care_reason' => 'code',
        'child_order' => 'int',
        'worked_last_day' => 'nullable_bool',
        'planned_shifts' => 'nullable_bool',
        'planned_shifts_worked' => 'nullable_bool',
        'dlo_has_leave' => 'nullable_bool',
        'dlo_leave_periods' => 'periods',
        'dlo_shift_schedule' => 'periods',
        'probable_income_czk' => 'int',
        'contact_worker_name' => 'text',
        'contact_worker_phone' => 'text',
        'contact_worker_email' => 'text',
    ];

    public const LONG_TERM_CARE_GRANTED = 'granted';
    public const LONG_TERM_CARE_REFUSED = 'refused';

    /** @var list<string> */
    public const LONG_TERM_CARE_CONSENTS = [
        self::LONG_TERM_CARE_GRANTED,
        self::LONG_TERM_CARE_REFUSED,
    ];

    public function __construct(
        private PayrollSicknessCaseRepository $cases,
        private SicknessProtectionPeriodPolicy $protection,
    ) {}

    /**
     * Zaměstnavatel dlouhodobou péči odmítl (§ 191a zákoníku práce), takže
     * zaměstnanec v práci nechybí a dávka mu nenáleží — žádost se nepředává.
     *
     * @param array<string,mixed> $row
     */
    public function assertLongTermCareNotRefused(SicknessBenefitKind $kind, array $row): void
    {
        if ($kind === SicknessBenefitKind::Dlo
            && ($row['long_term_care_consent'] ?? null) === self::LONG_TERM_CARE_REFUSED
        ) {
            throw new SicknessException(
                'dlo_employer_refused',
                'Zaměstnavatel dlouhodobou péči odmítl (' . (string) ($row['long_term_care_consent_on'] ?? '')
                . '), takže zaměstnanec v práci nechybí a dlouhodobé ošetřovné z tohoto zaměstnání '
                . 'nenáleží. Změní-li zaměstnavatel rozhodnutí, zapište souhlas v případu dávky.',
            );
        }
    }

    /** @return list<array<string,mixed>> */
    public function list(
        int $supplierId,
        string $environment,
        ?int $employmentId = null,
    ): array {
        $rows = $this->cases->listForSupplier(
            $supplierId,
            $environment,
            $employmentId,
        );
        foreach ($rows as $index => $row) {
            $rows[$index] = $this->decorate($supplierId, $environment, $row);
            $rows[$index]['protection_period'] = $this->protectionStatus($row);
        }

        return $rows;
    }

    /**
     * Vznikla sociální událost za trvání vztahu, nebo v ochranné lhůtě podle
     * § 15 zák. č. 187/2006 Sb.? Mimo obojí nárok z tohoto vztahu nevznikl
     * a politika to odmítne s konkrétní větou.
     *
     * @param array<string,mixed> $context
     * @param array<string,mixed> $row
     * @return array{status:string,employment_end:?string,protection_until:?string,legal_reference:string}
     */
    public function assertEventCovered(
        SicknessBenefitKind $kind,
        string $eventFrom,
        array $context,
        array $row,
    ): array {
        return $this->protection->assess($kind, $eventFrom, $context, $row);
    }

    /**
     * Stav ochranné lhůty pro seznam. Případ mimo ochrannou lhůtu se tu
     * neodmítá — už existuje a obrazovka musí říct PROČ z něj podání nepůjde.
     *
     * @param array<string,mixed> $row řádek seznamu s `employment_*` sloupci
     * @return array<string,mixed>
     */
    private function protectionStatus(array $row): array
    {
        $kind = SicknessBenefitKind::tryFrom((string) ($row['benefit_kind'] ?? ''));
        if ($kind === null) {
            return ['status' => 'unknown'];
        }
        try {
            return $this->protection->assess(
                $kind,
                (string) $row['incapacity_from'],
                [
                    'start_date' => $row['employment_start_date'] ?? null,
                    'actual_start_date' => $row['employment_actual_start_date'] ?? null,
                    'end_date' => $row['employment_end_date'] ?? null,
                    'relation_type' => $row['employment_relation_type'] ?? null,
                ],
                $row,
            );
        } catch (SicknessException $exception) {
            return [
                'status' => 'outside',
                'employment_end' => $row['employment_end_date'] ?? null,
                'protection_until' => null,
                'legal_reference' => SicknessProtectionPeriodPolicy::LEGAL_REFERENCE,
                'reason_code' => $exception->validationCode,
                'message' => $exception->getMessage(),
            ];
        }
    }

    /**
     * Řádek případu doplněný o dny práce, ručně doplněné měsíce rozhodného
     * období a rozbalené dny péče.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function decorate(int $supplierId, string $environment, array $row): array
    {
        $caseId = (int) $row['id'];
        $row['work_days'] = $this->cases->workDays($supplierId, $environment, $caseId);
        $months = [];
        foreach ($this->cases->decisiveMonths($supplierId, $environment, $caseId) as $period => $month) {
            $months[] = [
                'period' => $period,
                'income_minor' => $month['income_minor'],
                'excluded_days' => $month['excluded_days'],
            ];
        }
        $row['decisive_months'] = $months;
        $careDays = is_string($row['care_days'] ?? null)
            ? json_decode((string) $row['care_days'], true)
            : null;
        $row['care_days'] = is_array($careDays) ? $careDays : [];
        foreach (['dlo_leave_periods', 'dlo_shift_schedule'] as $column) {
            if (is_string($row[$column] ?? null)) {
                $decoded = json_decode((string) $row[$column], true);
                $row[$column] = is_array($decoded) ? $decoded : null;
            }
        }

        return $row;
    }

    /**
     * `$system` nese údaje, které klient zapsat nesmí a zakládá je jen aplikace
     * sama: stav podání vyřízeného předchozím programem, původ případu a odkaz
     * na převzatý záznam ({@see SicknessCaseFromAbsenceService}). HTTP akce ho
     * nepředává nikdy.
     *
     * @param array<string,mixed> $input
     * @param array{
     *   nempri_status?:string,nempri_accepted_on?:?string,
     *   hzupn_status?:string,hzupn_accepted_on?:?string,
     *   source?:string,external_reference?:?string
     * } $system
     * @return array<string,mixed>
     */
    public function create(
        int $supplierId,
        string $environment,
        int $employmentId,
        string $benefitKind,
        array $input,
        int $createdBy,
        array $system = [],
    ): array {
        $kind = $this->benefitKind($benefitKind);
        $values = $this->normalize($input, true);
        $this->assertCodebooks($kind, $values);
        $this->assertLongTermCareConsent($kind, $values, []);
        $values = $this->assertBenefitBasis($kind, $values, []);
        $values = [...$values, ...$this->systemValues($kind, $system)];
        $incapacityFrom = (string) $values['incapacity_from'];
        $context = $this->requireContext(
            $supplierId,
            $employmentId,
            $incapacityFrom,
        );
        $this->protection->assess($kind, $incapacityFrom, $context, $values);
        $overlapping = $this->cases->overlappingForEmployment(
            $supplierId,
            $environment,
            $employmentId,
            $kind->value,
            $incapacityFrom,
            ($values['incapacity_to'] ?? null) === null
                ? null
                : (string) $values['incapacity_to'],
        );
        if ($overlapping !== []) {
            throw new SicknessException(
                'sickness_case_overlaps',
                'Pro tenhle pracovní vztah už je evidovaný případ téhož druhu dávky, '
                . 'který se s obdobím překrývá. Dvě podání za tutéž událost ČSSZ nespáruje.',
            );
        }
        if ($values['ossz_code'] === null) {
            $values['ossz_code'] = $this->defaultOsszCode($context);
        }
        $this->assertCaredDependant($supplierId, (int) $context['employee_id'], $values);
        $workDays = $this->workIntervals($input);
        $decisiveMonths = $this->decisiveMonthsInput($input);

        $caseId = $this->cases->transaction(function () use (
            $supplierId,
            $environment,
            $employmentId,
            $kind,
            $values,
            $context,
            $createdBy,
            $workDays,
            $decisiveMonths,
        ): int {
            $id = $this->cases->insert($supplierId, $environment, [
                'employee_id' => (int) $context['employee_id'],
                'employment_id' => $employmentId,
                'benefit_kind' => $kind->value,
                'created_by' => $createdBy,
                ...$values,
            ]);
            $this->cases->replaceWorkDays(
                $supplierId,
                $environment,
                $id,
                $workDays,
            );
            if ($decisiveMonths !== null) {
                $this->cases->replaceDecisiveMonths(
                    $supplierId,
                    $environment,
                    $id,
                    $decisiveMonths,
                );
            }

            return $id;
        });

        return $this->requireCase($supplierId, $environment, $caseId);
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function update(
        int $supplierId,
        string $environment,
        int $caseId,
        int $rowVersion,
        array $input,
    ): array {
        $row = $this->requireCase($supplierId, $environment, $caseId);
        if ((int) ($row['cancelled'] ?? 0) === 1) {
            throw new SicknessException(
                'sickness_case_not_editable',
                'Zrušený případ se už needituje. Vznikla-li událost znovu, založte nový případ.',
            );
        }
        $values = $this->normalize($input, false);
        $kind = SicknessBenefitKind::from((string) $row['benefit_kind']);
        $this->assertSettledDocumentsUnchanged($kind, $row, $values, $input);
        $this->assertCodebooks($kind, $values);
        $this->assertLongTermCareConsent($kind, $values, $row);
        $values = $this->assertBenefitBasis($kind, $values, $row);
        if (array_key_exists('incapacity_from', $values) && $values['incapacity_from'] !== null) {
            $this->protection->assess(
                $kind,
                (string) $values['incapacity_from'],
                $this->requireContext(
                    $supplierId,
                    (int) $row['employment_id'],
                    (string) $values['incapacity_from'],
                ),
                [...$row, ...$values],
            );
        }
        $this->assertCaredDependant($supplierId, (int) $row['employee_id'], $values);
        $decisiveMonths = $this->decisiveMonthsInput($input);
        if ($values !== []) {
            if (!$this->cases->update(
                $supplierId,
                $environment,
                $caseId,
                $rowVersion,
                $values,
            )) {
                throw new SicknessException(
                    'sickness_case_conflict',
                    'Případ mezitím někdo změnil. Načtěte ho znovu a úpravu zopakujte.',
                );
            }
        }
        if (array_key_exists('work_days', $input)) {
            $this->cases->replaceWorkDays(
                $supplierId,
                $environment,
                $caseId,
                $this->workIntervals($input),
            );
        }
        if ($decisiveMonths !== null) {
            $this->cases->replaceDecisiveMonths(
                $supplierId,
                $environment,
                $caseId,
                $decisiveMonths,
            );
        }

        return $this->requireCase($supplierId, $environment, $caseId);
    }

    /**
     * Kódy z číselníků ČSSZ se kontrolují už při uložení, ne až při přípravě
     * podání: kód jiného druhu dávky nebo mimo číselník by jinak ležel
     * v případu, dokud by ho neodmítla územní správa.
     *
     * Kontroluje se jen to, co požadavek mění — starý neplatný kód, který
     * uživatel právě neopravuje, nesmí zablokovat úpravu jiného pole. Do věty
     * se stejně nedostane: odmítne ho {@see SicknessXmlValidator}.
     *
     * @param array<string,mixed> $values
     */
    private function assertCodebooks(SicknessBenefitKind $kind, array $values): void
    {
        if (array_key_exists('pension_kind', $values)) {
            NempriCodebook::assertPensionKind($values['pension_kind']);
        }
        $touched = array_intersect_key(
            $values,
            array_flip(['relationship_code', 'paternity_reason', 'maternity_care_reason']),
        );
        if ($touched === []) {
            return;
        }
        NempriCodebook::assertValid(
            $kind,
            array_key_exists('relationship_code', $touched) ? $touched['relationship_code'] : null,
            array_key_exists('paternity_reason', $touched) ? $touched['paternity_reason'] : null,
            array_key_exists('maternity_care_reason', $touched) ? $touched['maternity_care_reason'] : null,
        );
    }

    /**
     * Dítě nebo ošetřovaná osoba z evidence musí patřit TÉMUŽ zaměstnanci.
     * Cizí vyživovaná osoba by do žádosti vnesla rodné číslo někoho jiného.
     *
     * @param array<string,mixed> $values
     */
    private function assertCaredDependant(int $supplierId, int $employeeId, array $values): void
    {
        $dependantId = $values['cared_dependant_id'] ?? null;
        if ($dependantId === null) {
            return;
        }
        if ($this->cases->dependant($supplierId, $employeeId, (int) $dependantId) === null) {
            throw new SicknessException(
                'nempri_cared_person_not_found',
                'Vybraná osoba není vyživovanou osobou tohoto zaměstnance.',
            );
        }
    }

    /**
     * Ručně doplněné měsíce rozhodného období. `null` = vstup je neobsahuje
     * a uložené měsíce se nemění.
     *
     * @param array<string,mixed> $input
     * @return array<string,array{income_minor:int,excluded_days:int}>|null
     */
    private function decisiveMonthsInput(array $input): ?array
    {
        if (!array_key_exists('decisive_months', $input)) {
            return null;
        }
        $raw = $input['decisive_months'] ?? [];
        if (!is_array($raw)) {
            throw new SicknessException(
                'nempri_decisive_months_invalid',
                'Měsíce rozhodného období musí být seznam.',
            );
        }
        $months = [];
        foreach ($raw as $item) {
            $period = is_array($item) ? trim((string) ($item['period'] ?? '')) : '';
            $income = is_array($item) ? ($item['income_minor'] ?? null) : null;
            $excluded = is_array($item) ? ($item['excluded_days'] ?? 0) : null;
            if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $period) !== 1
                || !is_numeric($income) || (int) $income < 0
                || !is_numeric($excluded) || (int) $excluded < 0 || (int) $excluded > 31
            ) {
                throw new SicknessException(
                    'nempri_decisive_months_invalid',
                    'Každý měsíc rozhodného období musí mít měsíc (RRRR-MM), nezáporný '
                    . 'započitatelný příjem a 0 až 31 vyloučených dnů.',
                );
            }
            $months[$period] = [
                'income_minor' => (int) $income,
                'excluded_days' => (int) $excluded,
            ];
        }
        ksort($months, SORT_STRING);

        return $months;
    }

    /**
     * Zápis výsledku podání z protokolu ČSSZ — vždy u KONKRÉTNÍHO podání.
     *
     * NEMPRI a HZUPN se podávají zvlášť a zvlášť se i přijímají; přijetí NEMPRI
     * proto nic neříká o HZUPN a jeho lhůta se hlídá dál.
     *
     * - `accepted` vyžaduje den doručení. Bez něj by povinnost byla „splněná
     *   někdy" a hlídač termínů by neměl co porovnat s lhůtou. Podruhé jde
     *   zapsat jen u opravného podání.
     * - `rejected` vyžaduje důvod; vyřízené podání odmítnout nejde.
     * - `predecessor` jen u převzatého případu: podání podal předchozí program,
     *   den doručení je nepovinný.
     * - `pending` vrací podání vedené jako podané předchozím programem zpět
     *   k podání z MyÚčta. Převod ho tak označí podle lhůty, ne podle toho, co
     *   předchozí program skutečně odeslal; když to nesedí, povinnost se jinak
     *   přestane hlídat a podání nejde připravit. Zrušení případu cestou není:
     *   nový případ téže události by narazil na jedinečný klíč.
     * - `cancelled` zruší celý případ ({@see self::cancel()}).
     *
     * @return array<string,mixed>
     */
    public function recordReceipt(
        int $supplierId,
        string $environment,
        int $caseId,
        SicknessDocumentKind $document,
        string $outcome,
        ?string $acceptedOn,
        ?string $reason,
    ): array {
        if ($outcome === 'cancelled') {
            return $this->cancel($supplierId, $environment, $caseId);
        }
        $row = $this->requireCase($supplierId, $environment, $caseId);
        if ((int) ($row['cancelled'] ?? 0) === 1) {
            throw new SicknessException(
                'sickness_case_cancelled',
                'Případ je zrušený, výsledek podání se k němu nezapisuje.',
            );
        }
        $kind = SicknessBenefitKind::from((string) $row['benefit_kind']);
        if ($document === SicknessDocumentKind::Hzupn && !$kind->hasEndOfIncapacityReport()) {
            throw new SicknessException(
                'hzupn_not_for_benefit_kind',
                'Hlášení při ukončení pracovní neschopnosti (HZUPN) se podává jen u nemocenského, '
                . 'u tohoto druhu dávky se jeho výsledek nezapisuje.',
            );
        }
        $current = self::documentStatus($row, $document);
        $label = $document->agendaCode();
        $correction = (int) ($row['correction'] ?? 0) === 1;
        $changes = match ($outcome) {
            'accepted' => (function () use ($current, $correction, $label, $acceptedOn): array {
                if ($current === SicknessDocumentStatus::Predecessor) {
                    throw new SicknessException(
                        'sickness_receipt_predecessor',
                        $label . ' podal předchozí mzdový program, MyÚčto ho nepodávalo. '
                        . 'Přijetí se zapisuje jen k podání odeslanému z MyÚčta.',
                    );
                }
                if ($current === SicknessDocumentStatus::Accepted && !$correction) {
                    throw new SicknessException(
                        'sickness_receipt_already_recorded',
                        'Přijetí ' . $label . ' je už zapsané. Další přijetí patří jen k opravnému '
                        . 'podání: zaškrtněte u případu Opravné podání.',
                    );
                }

                return [
                    'status' => SicknessDocumentStatus::Accepted->value,
                    'accepted_on' => $this->requireDate(
                        $acceptedOn,
                        'sickness_receipt_date_missing',
                        'Přijetí musí nést den doručení podání z protokolu ČSSZ.',
                    ),
                    'rejection_reason' => null,
                ];
            })(),
            'rejected' => (function () use ($current, $label, $reason): array {
                if ($current->isSettled()) {
                    throw new SicknessException(
                        'sickness_receipt_already_settled',
                        $label . ' je už vyřízené, odmítnutí k němu zapsat nejde. Odmítnuté '
                        . 'opravné podání podejte znovu.',
                    );
                }

                return [
                    'status' => SicknessDocumentStatus::Rejected->value,
                    'accepted_on' => null,
                    'rejection_reason' => $this->requireText(
                        $reason,
                        'sickness_rejection_reason_missing',
                        'Odmítnutí musí nést důvod z protokolu ČSSZ.',
                    ),
                ];
            })(),
            'predecessor' => (function () use ($row, $current, $label, $acceptedOn): array {
                if (($row['source'] ?? self::SOURCE_MYUCTO) !== self::SOURCE_PREDECESSOR) {
                    throw new SicknessException(
                        'sickness_receipt_predecessor_not_takeover',
                        'Vyřízení předchozím programem jde zapsat jen u případu převzatého '
                        . 'z předchozího mzdového programu. Podání z MyÚčta se zapisuje '
                        . 'dnem doručení z protokolu ČSSZ.',
                    );
                }
                if ($current === SicknessDocumentStatus::Accepted) {
                    throw new SicknessException(
                        'sickness_receipt_already_settled',
                        $label . ' je už přijaté z MyÚčta, předchozí program ho nepodával.',
                    );
                }

                return [
                    'status' => SicknessDocumentStatus::Predecessor->value,
                    'accepted_on' => $acceptedOn === null || trim($acceptedOn) === ''
                        ? null
                        : $this->requireDate(
                            $acceptedOn,
                            'sickness_date_invalid',
                            'Den doručení podání předchozím programem musí být ve tvaru RRRR-MM-DD.',
                        ),
                    'rejection_reason' => null,
                ];
            })(),
            'pending' => (function () use ($row, $current, $label): array {
                if (($row['source'] ?? self::SOURCE_MYUCTO) !== self::SOURCE_PREDECESSOR
                    || $current !== SicknessDocumentStatus::Predecessor
                ) {
                    throw new SicknessException(
                        'sickness_receipt_reopen_not_predecessor',
                        'Zpět k podání jde vrátit jen ' . $label . ' vedené jako podané předchozím '
                        . 'programem. Výsledek podání z MyÚčta se opravuje opravným podáním.',
                    );
                }

                return [
                    'status' => SicknessDocumentStatus::Pending->value,
                    'accepted_on' => null,
                    'rejection_reason' => null,
                ];
            })(),
            default => throw new SicknessException(
                'sickness_receipt_outcome_invalid',
                'Výsledek podání musí být accepted, rejected, predecessor, pending nebo cancelled.',
            ),
        };
        if (!$this->cases->update(
            $supplierId,
            $environment,
            $caseId,
            (int) $row['row_version'],
            [
                $document->statusColumn() => $changes['status'],
                $document->acceptedOnColumn() => $changes['accepted_on'],
                $document->rejectionReasonColumn() => $changes['rejection_reason'],
            ],
        )) {
            throw new SicknessException(
                'sickness_case_conflict',
                'Případ mezitím někdo změnil. Načtěte ho znovu a výsledek zapište znovu.',
            );
        }

        return $this->requireCase($supplierId, $environment, $caseId);
    }

    /**
     * Zrušení celého případu (událost nenastala, absence byla zrušena). Zrušený
     * případ se nehlídá a nic se z něj nepřipravuje.
     *
     * @return array<string,mixed>
     */
    public function cancel(
        int $supplierId,
        string $environment,
        int $caseId,
    ): array {
        $row = $this->requireCase($supplierId, $environment, $caseId);
        if ((int) ($row['cancelled'] ?? 0) === 1) {
            return $row;
        }
        if (!$this->cases->update(
            $supplierId,
            $environment,
            $caseId,
            (int) $row['row_version'],
            ['cancelled' => 1],
        )) {
            throw new SicknessException(
                'sickness_case_conflict',
                'Případ mezitím někdo změnil. Načtěte ho znovu a zrušení zopakujte.',
            );
        }

        return $this->requireCase($supplierId, $environment, $caseId);
    }

    /**
     * Stav jednoho podání případu. Řádek bez sloupce (starší data) je `pending`.
     *
     * @param array<string,mixed> $row
     */
    public static function documentStatus(array $row, SicknessDocumentKind $document): SicknessDocumentStatus
    {
        return SicknessDocumentStatus::tryFrom((string) ($row[$document->statusColumn()] ?? ''))
            ?? SicknessDocumentStatus::Pending;
    }

    /**
     * Pracoval zaměstnanec v den vzniku neschopnosti CELOU směnu? Pak se podle
     * § 26 odst. 3 zák. č. 187/2006 Sb. za první den neschopnosti považuje
     * následující kalendářní den a posouvá se i den, od kterého náleží
     * nemocenské a běží lhůta NEMPRI. Odpracovaná jen část směny den nevznikl
     * neposouvá; jsou-li hodiny známé, musí dosáhnout pracovní doby.
     *
     * @param array<string,mixed> $row
     */
    public static function firstDayFullyWorked(array $row): bool
    {
        if ((int) ($row['worked_on_decisive_day'] ?? 0) !== 1) {
            return false;
        }
        $worked = $row['hours_worked'] ?? null;
        $shift = $row['daily_working_hours'] ?? null;
        if (!is_numeric($worked) || !is_numeric($shift)) {
            return true;
        }

        return round((float) $worked * 100) >= round((float) $shift * 100);
    }

    /**
     * Údaje vyřízeného podání (přijatého nebo podaného předchozím programem) se
     * už nemění — jinak by evidence tvrdila něco jiného, než co ČSSZ dostala.
     * Výjimkou je opravné podání: s ním se údaje mění právě proto, aby se
     * mohly podat znovu.
     *
     * Kontroluje se jen skutečná změna hodnoty; editor posílá celý formulář.
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed> $values normalizované hodnoty z požadavku
     * @param array<string,mixed> $input  syrový požadavek (dny práce, ruční měsíce)
     */
    private function assertSettledDocumentsUnchanged(
        SicknessBenefitKind $kind,
        array $row,
        array $values,
        array $input,
    ): void {
        $correction = array_key_exists('correction', $values)
            ? (int) $values['correction'] === 1
            : (int) ($row['correction'] ?? 0) === 1;
        if ($correction) {
            return;
        }
        $nempriSettled = self::documentStatus($row, SicknessDocumentKind::Nempri)->isSettled();
        $hasHzupn = $kind->hasEndOfIncapacityReport();
        $hzupnSettled = $hasHzupn
            && self::documentStatus($row, SicknessDocumentKind::Hzupn)->isSettled();
        if (!$nempriSettled && !$hzupnSettled) {
            return;
        }

        $changed = [];
        foreach ($values as $column => $value) {
            if (in_array($column, self::ALWAYS_EDITABLE, true)) {
                continue;
            }
            $document = $this->fieldDocument($kind, $column);
            $locked = match ($document) {
                'hzupn' => $hzupnSettled,
                'nempri' => $nempriSettled,
                default => $nempriSettled && (!$hasHzupn || $hzupnSettled),
            };
            if ($locked && !$this->sameValue(self::EDITABLE[$column] ?? 'text', $value, $row[$column] ?? null)) {
                $changed[$document === 'hzupn' ? 'HZUPN' : 'NEMPRI'] = true;
            }
        }
        if (array_key_exists('work_days', $input)) {
            $locked = $hasHzupn ? $hzupnSettled : $nempriSettled;
            if ($locked && $this->workIntervals($input) !== ($row['work_days'] ?? [])) {
                $changed[$hasHzupn ? 'HZUPN' : 'NEMPRI'] = true;
            }
        }
        if ($nempriSettled && array_key_exists('decisive_months', $input)) {
            $stored = [];
            foreach ($row['decisive_months'] ?? [] as $month) {
                $stored[(string) $month['period']] = [
                    'income_minor' => (int) $month['income_minor'],
                    'excluded_days' => (int) $month['excluded_days'],
                ];
            }
            if ($this->decisiveMonthsInput($input) !== $stored) {
                $changed['NEMPRI'] = true;
            }
        }
        if ($changed === []) {
            return;
        }
        $documents = implode(' a ', array_keys($changed));
        throw new SicknessException(
            'sickness_case_document_settled',
            $documents . ' je už vyřízené (přijaté, nebo podané předchozím programem), takže '
            . 'se jeho údaje nemění — evidence musí odpovídat tomu, co ČSSZ dostala. Opravu '
            . 'podejte opravným podáním: zaškrtněte Opravné podání a vyplňte číslo rozhodnutí.',
        );
    }

    /** Ke kterému podání pole patří: `nempri`, `hzupn`, nebo `shared`. */
    private function fieldDocument(SicknessBenefitKind $kind, string $column): string
    {
        if (in_array($column, self::SHARED_FIELDS, true)) {
            return 'shared';
        }
        if ($kind->hasEndOfIncapacityReport() && in_array($column, self::HZUPN_FIELDS, true)) {
            return 'hzupn';
        }

        return 'nempri';
    }

    private function sameValue(string $type, mixed $new, mixed $old): bool
    {
        if ($new === null || $old === null) {
            // Nevyplněné prohlášení a „ne" znamenají pro uložení totéž:
            // editor posílá u nezaškrtnutého pole jednou null, jindy 0.
            if ($type === 'bool' || $type === 'nullable_bool') {
                return (int) ($new ?? 0) === (int) ($old ?? 0);
            }

            return $new === null && $old === null;
        }

        return match ($type) {
            'decimal' => round((float) $new * 100) === round((float) $old * 100),
            'int', 'id', 'bool', 'nullable_bool' => (int) $new === (int) $old,
            'periods' => json_decode((string) $new, true)
                == (is_array($old) ? $old : json_decode((string) $old, true)),
            default => (string) $new === (string) $old,
        };
    }

    /**
     * Podklady, které závisí na jiném poli téhož případu: důvod převedení jen
     * při převedení (§ 19 odst. 6), podklady pro výplatu DLO jen u dlouhodobého
     * ošetřovného — období volna při `maVolno`, rozvrh směn při plánovaných
     * směnách (DV NEMPRI25, Podklady pro výplatu DLO). Posuzuje se výsledný
     * stav (uložený + měněný); zrušené převedení s sebou smaže i den a důvod.
     *
     * @param array<string,mixed> $values
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function assertBenefitBasis(SicknessBenefitKind $kind, array $values, array $row): array
    {
        if (array_key_exists('transferred_other_work', $values) && (int) $values['transferred_other_work'] === 0) {
            $values['transferred_on'] = null;
            $values['transfer_reason'] = null;
        }
        $merged = static fn (string $column): mixed => array_key_exists($column, $values)
            ? $values[$column]
            : ($row[$column] ?? null);
        if ($merged('transfer_reason') !== null && (int) ($merged('transferred_other_work') ?? 0) !== 1) {
            throw new SicknessException(
                'sickness_transfer_reason_without_transfer',
                'Důvod převedení na jinou práci se vyplňuje jen tehdy, když byla zaměstnankyně '
                . 'převedena. Zaškrtněte převedení a doplňte jeho den.',
            );
        }

        $dloColumns = ['dlo_has_leave', 'dlo_leave_periods', 'dlo_shift_schedule'];
        if ($kind !== SicknessBenefitKind::Dlo) {
            foreach ($dloColumns as $column) {
                if (($values[$column] ?? null) !== null) {
                    throw new SicknessException(
                        'dlo_basis_not_in_kind',
                        'Pracovní volno a rozvrh směn se jako podklad pro výplatu vyplňují jen '
                        . 'u dlouhodobého ošetřovného.',
                    );
                }
            }

            return $values;
        }
        if ((int) ($merged('dlo_has_leave') ?? 0) !== 1 && array_key_exists('dlo_has_leave', $values)) {
            $values['dlo_leave_periods'] = null;
        }
        if ($merged('dlo_leave_periods') !== null && (int) ($merged('dlo_has_leave') ?? 0) !== 1) {
            throw new SicknessException(
                'dlo_leave_periods_without_leave',
                'Období pracovního volna patří k údaji „má volno“. Zaškrtněte ho, nebo období smažte.',
            );
        }
        if ($merged('dlo_shift_schedule') !== null && (int) ($merged('planned_shifts') ?? 0) !== 1) {
            throw new SicknessException(
                'dlo_shift_schedule_without_planned_shifts',
                'Rozvrh směn patří k údaji „měl plánované směny“. Zaškrtněte ho, nebo rozvrh smažte.',
            );
        }

        return $values;
    }

    /**
     * Údaje, které při založení případu doplňuje jen aplikace.
     *
     * @param array<string,mixed> $system
     * @return array<string,mixed>
     */
    private function systemValues(SicknessBenefitKind $kind, array $system): array
    {
        $values = [];
        foreach ([SicknessDocumentKind::Nempri, SicknessDocumentKind::Hzupn] as $document) {
            $status = $system[$document->statusColumn()] ?? null;
            if ($status === null) {
                continue;
            }
            $parsed = SicknessDocumentStatus::from((string) $status);
            if ($parsed === SicknessDocumentStatus::Rejected || $parsed === SicknessDocumentStatus::Accepted) {
                throw new \InvalidArgumentException('Při založení případu jde podání označit jen jako vyřízené předchozím programem.');
            }
            if ($document === SicknessDocumentKind::Hzupn && !$kind->hasEndOfIncapacityReport()) {
                continue;
            }
            $values[$document->statusColumn()] = $parsed->value;
            $acceptedOn = $system[$document->acceptedOnColumn()] ?? null;
            if ($acceptedOn !== null) {
                $values[$document->acceptedOnColumn()] = $this->requireDate(
                    (string) $acceptedOn,
                    'sickness_date_invalid',
                    'Den doručení podání musí být ve tvaru RRRR-MM-DD.',
                );
            }
        }
        if (isset($system['source'])) {
            if (!in_array($system['source'], self::SOURCES, true)) {
                throw new \InvalidArgumentException('Neznámý původ případu dávky.');
            }
            $values['source'] = $system['source'];
        }
        if (isset($system['external_reference'])) {
            $values['external_reference'] = mb_substr(trim((string) $system['external_reference']), 0, 190);
        }

        return $values;
    }

    /** @return array<string,mixed> */
    public function requireCase(
        int $supplierId,
        string $environment,
        int $caseId,
    ): array {
        $row = $this->cases->find($supplierId, $environment, $caseId);
        if ($row === null) {
            throw new \OutOfBoundsException(
                'Případ dávky nemocenského pojištění nebyl nalezen.',
            );
        }

        return $this->decorate($supplierId, $environment, $row);
    }

    /** @return array<string,mixed> */
    public function requireContext(
        int $supplierId,
        int $employmentId,
        string $onDate,
    ): array {
        $context = $this->cases->findEmploymentContext(
            $supplierId,
            $employmentId,
            $onDate,
        );
        if ($context === null) {
            throw new SicknessException(
                'sickness_employment_missing',
                'Pracovní vztah k datu vzniku sociální události neexistuje.',
            );
        }

        return $context;
    }

    public function benefitKind(string $value): SicknessBenefitKind
    {
        $kind = SicknessBenefitKind::tryFrom(strtoupper(trim($value)));
        if ($kind === null) {
            throw new SicknessException(
                'sickness_benefit_kind_invalid',
                'Druh dávky musí být NEM, VPM, OPP, PPM, OSE nebo DLO podle číselníku ČSSZ.',
            );
        }

        return $kind;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function normalize(array $input, bool $requireCore): array
    {
        $values = [];
        foreach (self::EDITABLE as $column => $type) {
            if (!array_key_exists($column, $input)) {
                continue;
            }
            $values[$column] = $this->cast($column, $type, $input[$column]);
        }
        if ($requireCore) {
            if (!isset($values['incapacity_from'])) {
                throw new SicknessException(
                    'sickness_incapacity_from_missing',
                    'Případ musí mít den vzniku sociální události.',
                );
            }
            foreach (['ossz_code'] as $optional) {
                $values[$optional] ??= null;
            }
        }
        if (isset($values['incapacity_from'], $values['incapacity_to'])
            && $values['incapacity_to'] !== null
            && $values['incapacity_to'] < $values['incapacity_from']
        ) {
            throw new SicknessException(
                'sickness_case_period_invalid',
                'Den skončení sociální události nesmí předcházet dni jejího vzniku.',
            );
        }

        return $values;
    }

    private function cast(string $column, string $type, mixed $value): mixed
    {
        if ($value === null || $value === '' || ($type === 'periods' && $value === [])) {
            if ($type === 'bool') {
                return 0;
            }

            return null;
        }

        return match ($type) {
            'int' => (int) $value,
            'id' => (int) $value > 0 ? (int) $value : null,
            'bool', 'nullable_bool' => $this->boolean($value) ? 1 : 0,
            'decimal' => $this->decimal($column, $value),
            'date' => $this->requireDate(
                (string) $value,
                'sickness_date_invalid',
                'Datum v případu musí být ve tvaru RRRR-MM-DD.',
            ),
            'care_reason' => $this->careReason($value),
            'transfer_reason' => $this->transferReason($value),
            'ltc_consent' => $this->longTermCareConsentValue($value),
            'code' => $this->codebookValue($value),
            'periods' => $this->periodsJson($value),
            default => trim((string) $value),
        };
    }

    private function careReason(mixed $value): string
    {
        $reason = trim((string) $value);
        if (!in_array($reason, NempriBenefitApplication::CARE_REASONS, true)) {
            throw new SicknessException(
                'nempri_care_reason_invalid',
                'Důvod péče musí být onemocnění, karanténa, nemožnost péče o dítě, '
                . 'nebo uzavření školy či zařízení.',
            );
        }

        return $reason;
    }

    private function transferReason(mixed $value): string
    {
        $reason = trim((string) $value);
        if (!in_array($reason, self::TRANSFER_REASONS, true)) {
            throw new SicknessException(
                'sickness_transfer_reason_invalid',
                'Důvod převedení na jinou práci musí být těhotenství, mateřství, nebo kojení '
                . '(§ 19 odst. 6 zákona č. 187/2006 Sb.).',
            );
        }

        return $reason;
    }

    private function longTermCareConsentValue(mixed $value): string
    {
        $consent = trim((string) $value);
        if (!in_array($consent, self::LONG_TERM_CARE_CONSENTS, true)) {
            throw new SicknessException(
                'dlo_employer_consent_invalid',
                'Rozhodnutí zaměstnavatele o dlouhodobém ošetřovném musí být souhlas, nebo odmítnutí.',
            );
        }

        return $consent;
    }

    /**
     * Rozhodnutí zaměstnavatele podle § 191a zákoníku práce: vyhovět žádosti
     * o nepřítomnost kvůli dlouhodobé péči musí, „ledaže mu v tom brání vážné
     * provozní důvody", a odmítnutí písemně zdůvodní. Posuzuje se výsledný
     * stav případu (uložený + měněný), protože pole přicházejí z jednoho
     * formuláře, ale uložit jde i jen jedno z nich.
     *
     * @param array<string,mixed> $values
     * @param array<string,mixed> $row
     */
    private function assertLongTermCareConsent(SicknessBenefitKind $kind, array $values, array $row): void
    {
        $fields = ['long_term_care_consent', 'long_term_care_consent_on', 'long_term_care_refusal_reason'];
        if (array_intersect_key($values, array_flip($fields)) === []) {
            return;
        }
        $merged = [];
        foreach ($fields as $field) {
            $merged[$field] = array_key_exists($field, $values) ? $values[$field] : ($row[$field] ?? null);
        }
        if ($merged['long_term_care_consent'] === null) {
            if ($merged['long_term_care_consent_on'] !== null || $merged['long_term_care_refusal_reason'] !== null) {
                throw new SicknessException(
                    'dlo_employer_consent_missing',
                    'Den rozhodnutí a důvod odmítnutí patří k rozhodnutí zaměstnavatele. '
                    . 'Vyberte, zda zaměstnavatel s dlouhodobou péčí souhlasil, nebo ji odmítl.',
                );
            }

            return;
        }
        if ($kind !== SicknessBenefitKind::Dlo) {
            throw new SicknessException(
                'dlo_employer_consent_not_in_kind',
                'Souhlas zaměstnavatele podle § 191a zákoníku práce se eviduje jen u dlouhodobého ošetřovného.',
            );
        }
        if ($merged['long_term_care_consent_on'] === null) {
            throw new SicknessException(
                'dlo_employer_consent_date_missing',
                'Rozhodnutí zaměstnavatele o dlouhodobém ošetřovném musí mít den, kdy ho zaměstnanci sdělil.',
            );
        }
        if ($merged['long_term_care_refusal_reason'] !== null
            && mb_strlen((string) $merged['long_term_care_refusal_reason']) > 500
        ) {
            throw new SicknessException(
                'dlo_employer_refusal_reason_too_long',
                'Důvod odmítnutí dlouhodobého ošetřovného může mít nejvýš 500 znaků.',
            );
        }
        if ($merged['long_term_care_consent'] === self::LONG_TERM_CARE_REFUSED) {
            if ($merged['long_term_care_refusal_reason'] === null) {
                throw new SicknessException(
                    'dlo_employer_refusal_reason_missing',
                    'Odmítnutí dlouhodobého ošetřovného musí zaměstnavatel zdůvodnit vážnými provozními '
                    . 'důvody (§ 191a zákoníku práce). Doplňte důvod, který zaměstnanci písemně sdělil.',
                );
            }
        } elseif ($merged['long_term_care_refusal_reason'] !== null) {
            throw new SicknessException(
                'dlo_employer_refusal_reason_without_refusal',
                'Důvod odmítnutí se vyplňuje jen tehdy, když zaměstnavatel dlouhodobou péči odmítl.',
            );
        }
    }

    private function codebookValue(mixed $value): string
    {
        $code = strtoupper(trim((string) $value));
        if (preg_match('/^[0-9A-Z]{1,3}$/D', $code) !== 1) {
            throw new SicknessException(
                'nempri_codebook_value_invalid',
                'Kód z číselníku ČSSZ má 1 až 3 znaky 0-9 a A-Z.',
            );
        }

        return $code;
    }

    private function periodsJson(mixed $value): string
    {
        if (!is_array($value)) {
            throw new SicknessException(
                'nempri_periods_invalid',
                'Dny péče musí být seznam období od–do.',
            );
        }

        return (string) json_encode(
            $this->workIntervals(['work_days' => $value]),
            JSON_THROW_ON_ERROR,
        );
    }

    private function boolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(
            strtolower(trim((string) $value)),
            ['1', 'true', 'ano', 'a', 'yes'],
            true,
        );
    }

    private function decimal(string $column, mixed $value): string
    {
        $normalized = str_replace(',', '.', trim((string) $value));
        if (preg_match('/^\d{1,5}(\.\d{1,2})?$/D', $normalized) !== 1) {
            throw new SicknessException(
                'sickness_hours_invalid',
                'Hodnota „' . $column . '" musí být kladné číslo s nejvýše dvěma desetinnými místy.',
            );
        }

        return $normalized;
    }

    /**
     * @param array<string,mixed> $input
     * @return list<array{from:string,to:string}>
     */
    private function workIntervals(array $input): array
    {
        $raw = $input['work_days'] ?? [];
        if (!is_array($raw)) {
            throw new SicknessException(
                'hzupn_work_intervals_invalid',
                'Dny práce v době neschopnosti musí být seznam intervalů.',
            );
        }
        $intervals = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                throw new SicknessException(
                    'hzupn_work_intervals_invalid',
                    'Každý interval práce musí mít den od a den do.',
                );
            }
            $from = $this->requireDate(
                isset($item['from']) ? (string) $item['from'] : null,
                'hzupn_work_intervals_invalid',
                'Interval práce v době neschopnosti musí mít den od ve tvaru RRRR-MM-DD.',
            );
            $to = $this->requireDate(
                isset($item['to']) ? (string) $item['to'] : null,
                'hzupn_work_intervals_invalid',
                'Interval práce v době neschopnosti musí mít den do ve tvaru RRRR-MM-DD.',
            );
            $intervals[] = ['from' => $from, 'to' => $to];
        }
        usort(
            $intervals,
            static fn (array $a, array $b): int => $a['from'] <=> $b['from'],
        );

        return $intervals;
    }

    /** @param array<string,mixed> $context */
    private function defaultOsszCode(array $context): int
    {
        $code = $context['employer_ossz_code'] ?? null;
        if (!is_numeric($code) || (int) $code < 100 || (int) $code > 999) {
            throw new SicknessException(
                'sickness_ossz_code_missing',
                'Firma nemá vyplněný tříciferný kód OSSZ podle číselníku pracovišť ČSSZ. '
                . 'Doplňte ho v Nastavení mezd → Zaměstnavatel, nebo ho zadejte u případu.',
            );
        }

        return (int) $code;
    }

    private function requireDate(
        ?string $value,
        string $code,
        string $message,
    ): string {
        $trimmed = $value === null ? '' : trim($value);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $trimmed);
        if (!$date instanceof \DateTimeImmutable
            || $date->format('Y-m-d') !== $trimmed
        ) {
            throw new SicknessException($code, $message);
        }

        return $trimmed;
    }

    private function requireText(
        ?string $value,
        string $code,
        string $message,
    ): string {
        $trimmed = $value === null ? '' : trim($value);
        if ($trimmed === '') {
            throw new SicknessException($code, $message);
        }

        return $trimmed;
    }
}
