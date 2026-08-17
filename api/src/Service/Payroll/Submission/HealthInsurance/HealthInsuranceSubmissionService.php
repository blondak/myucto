<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\HealthInsurance;

use MyInvoice\Repository\Payroll\PayrollHealthNotificationRepository;
use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\PayrollObligationService;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;

/**
 * Most mezi zdravotní agendou a platformou podání MZ-19.
 *
 * Čtyři rozhodnutí, na kterých vrstva stojí:
 *
 * 1. **Povinnost vzniká i tam, kde podání vzniknout nemůže.** Lhůta osmi dnů
 *    běží bez ohledu na to, jestli aplikace umí soubor odeslat. Obligace se
 *    proto zakládá vždy a inbox povinností o ní ví — druhá věc je, kdo ji
 *    splní a jak.
 * 2. **Chybějící XSD nezahazuje artefakt, zastaví ho před stavem `validated`.**
 *    Podání zůstane v `draft` s blokující výhradou ve fázi `xsd`. Účetní tak
 *    vidí soubor i důvod, proč není připravený k odeslání; kdyby se prepare
 *    celý zhroutil, nezůstalo by po něm nic a povinnost by se tvářila jako
 *    nezpracovaná.
 * 3. **Kanál se nehádá.** Ani jedna ze sedmi pojišťoven nemá veřejně popsanou
 *    transportní obálku, takže se nic neodesílá a
 *    {@see HealthInsurerChannelCatalog} to pojmenuje.
 * 4. **Stav `ready` znamená „lze odeslat", ne „odesláno".** Přechod dál patří
 *    transportu, který zatím doložený není.
 */
final readonly class HealthInsuranceSubmissionService
{
    public const AGENDA_BULK_NOTIFICATION = HealthInsuranceSchemaCatalog::HOZ;
    public const AGENDA_PAYMENT_OVERVIEW = HealthInsuranceSchemaCatalog::PPZ;

    public const SOURCE_EVENT_NOTIFICATION = 'payroll_health_notification';
    public const SOURCE_EVENT_OVERVIEW = 'payroll_health_payment_overview';

    private const CHANNEL = 'health_portal';
    private const SUBJECT_EMPLOYMENT = 'employment';
    private const SUBJECT_RUN = 'payroll_run';

    /** Strop stránky je tvrdý — z URL ho zvednout nejde. */
    public const PERIOD_MAX_LIMIT = 200;
    public const PERIOD_DEFAULT_LIMIT = 50;

    public function __construct(
        private PayrollHealthNotificationRepository $facts,
        private HealthNotificationDutyResolver $resolver,
        private HealthNotificationDutyCatalog $duties,
        private HealthNotificationCodeCatalog $codes,
        private HealthNotificationDeadlinePolicy $deadlines,
        private HealthInsuranceSchemaCatalog $schemas,
        private HealthInsurerChannelCatalog $channels,
        private HealthInsuranceXmlSerializer $serializer,
        private HealthInsuranceXmlValidator $validator,
        private HealthPaymentOverviewService $overviews,
        private PayrollObligationService $obligations,
        private PayrollSubmissionService $submissions,
        private PayrollSubmissionRepository $submissionRepository,
    ) {}

    /**
     * Co aplikace u zdravotních pojišťoven umí a co ne, i s důvody. Slouží
     * obrazovce, aby nemusela odvozovat schopnosti z chybových hlášek.
     *
     * @return array<string,mixed>
     */
    public function capability(): array
    {
        $documents = [];
        foreach ($this->schemas->documentTypes() as $documentType) {
            $manifest = $this->schemas->manifestFor($documentType);
            $documents[$documentType] = [
                'xsd_version' => $manifest['xsd_version'],
                'namespace' => $manifest['namespace'],
                'root' => $manifest['root'],
                'schema_pinned' => $manifest['available'],
                'schema_sha256' => $manifest['sha256'],
                'schema_url' => $manifest['url'],
            ];
        }
        $channels = [];
        foreach ($this->channels->channels() as $code => $channel) {
            $channels[$code] = $channel->toArray();
        }

        return [
            'schema_reference' => 'payroll-health-submission-capability.v1',
            'shared_data_message_since' => '2026-01-01',
            'documents' => $documents,
            'channels' => $channels,
            'automated_dispatch' => [
                'supported' => false,
                'reason_code' =>
                    HealthInsurerChannelCatalog::REASON_TRANSPORT_UNDOCUMENTED,
            ],
            'change_codes' => [
                'total' => count($this->codes->codes()),
                'narrowing_effective_from' =>
                    HealthNotificationDutyCatalog::NARROWING_EFFECTIVE_FROM,
                // Mapování je doložené anotací připnutého XSD, ale jen tam,
                // kde schéma určuje jediný kód; zbytek zůstává fail-closed.
                'mapping_from_duty_documented' => array_values(array_filter(
                    array_map(
                        fn (HealthNotificationDutyKind $kind): ?string =>
                            $this->codes->isCodeMappingDocumented($kind)
                                ? $kind->value
                                : null,
                        HealthNotificationDutyKind::cases(),
                    ),
                )),
            ],
            'duties' => array_map(
                static fn (HealthNotificationDutyRule $rule): array =>
                    $rule->toArray(),
                $this->duties->rules(),
            ),
            'verification_reference' =>
                HealthNotificationDutyCatalog::VERIFICATION_REFERENCE,
        ];
    }

    /**
     * Povinnosti plynoucí z jednoho pracovního vztahu ke dni skutečnosti.
     *
     * @return list<array<string,mixed>>
     */
    public function duties(
        int $supplierId,
        int $employmentId,
        string $onDate,
    ): array {
        $duties = $this->resolver->resolve(
            $this->requireFacts($supplierId, $employmentId, $onDate),
        );

        return array_map(
            static fn (HealthNotificationDuty $duty): array => $duty->toArray(),
            $duties,
        );
    }

    /**
     * Přehled oznamovacích povinností za mzdové období.
     *
     * Filtruje i stránkuje SERVER. Filtrovat na klientovi a stránkovat na
     * serveru by znamenalo, že `total` popisuje jiný seznam, než uživatel
     * vidí — a že se filtr uplatní jen na právě načtenou stránku.
     *
     * @param array{
     *   insurer_code?:?string,kind?:?string,reported?:?bool,
     *   dispatchable_only?:?bool
     * } $filters
     * @return array{
     *   period:string,items:list<array<string,mixed>>,total:int,
     *   limit:int,offset:int,summary:array<string,int>,
     *   unresolved_employments:list<array{employment_id:int,full_name:string,reason_code:string,reason:string}>
     * }
     */
    public function dutiesForPeriod(
        int $supplierId,
        string $environment,
        string $period,
        array $filters = [],
        int $limit = self::PERIOD_DEFAULT_LIMIT,
        int $offset = 0,
    ): array {
        $window = $this->periodBounds($period);
        $limit = max(1, min(self::PERIOD_MAX_LIMIT, $limit));
        $offset = max(0, $offset);

        $rows = $this->facts->listNotificationFacts(
            $supplierId,
            $window['from'],
            $window['to'],
        );

        $all = [];
        $unresolved = [];
        foreach ($rows as $row) {
            try {
                $duties = $this->resolver->resolve($this->factsFromRow($row));
            } catch (HealthNotificationException $exception) {
                // Vztah, u kterého se povinnost odvodit NELZE, se nesmí tiše
                // vypustit — chybějící pojišťovna je přesně ta vada, kvůli
                // které by oznámení nebylo komu poslat. Vrací se pojmenovaně
                // vedle seznamu, ne jako prázdno v něm.
                $unresolved[] = [
                    'employment_id' => $row['employment_id'],
                    'full_name' => $row['full_name'],
                    'reason_code' => $exception->errorCode,
                    'reason' => $exception->getMessage(),
                ];
                continue;
            }
            foreach ($duties as $duty) {
                if ($duty->occurredOn < $window['from']
                    || $duty->occurredOn > $window['to']
                ) {
                    continue;
                }
                $all[] = $this->periodItem($duty, $row['full_name']);
            }
        }

        // Souhrn se počítá nad CELÝM obdobím, ne nad filtrem ani nad stránkou —
        // stejně jako u inboxu podání. „Kolik je po lhůtě" nesmí záviset na
        // tom, jaký filtr má účetní zrovna zapnutý; kdyby závisel, dal by se
        // zúžením filtru schovat propadlý termín. `total` v odpovědi naopak
        // popisuje filtrovaný seznam, protože ten stránkuje.
        $summary = [
            'total' => count($all),
            'reported_by_employer' => 0,
            'reported_by_insured' => 0,
            'code_documented' => 0,
            'code_undocumented' => 0,
            'overdue' => 0,
        ];
        $today = (new \DateTimeImmutable(
            'now',
            new \DateTimeZone('Europe/Prague'),
        ))->format('Y-m-d');
        foreach ($all as $item) {
            $summary[$item['reported_by_employer']
                ? 'reported_by_employer'
                : 'reported_by_insured']++;
            $summary[$item['change_code']['documented']
                ? 'code_documented'
                : 'code_undocumented']++;
            $dueOn = $item['deadline']['due_on'] ?? null;
            if (is_string($dueOn) && $dueOn < $today) {
                $summary['overdue']++;
            }
        }

        $filtered = array_values(array_filter(
            $all,
            fn (array $item): bool => $this->matchesFilters($item, $filters),
        ));
        usort(
            $filtered,
            static fn (array $a, array $b): int =>
                [$a['occurred_on'], $a['full_name'], $a['kind']]
                <=> [$b['occurred_on'], $b['full_name'], $b['kind']],
        );

        return [
            'period' => $period,
            'environment' => $environment,
            'items' => array_slice($filtered, $offset, $limit),
            'total' => count($filtered),
            'limit' => $limit,
            'offset' => $offset,
            'summary' => $summary,
            'unresolved_employments' => $unresolved,
        ];
    }

    /**
     * Zaeviduje povinnosti, které na zaměstnavatele skutečně dopadají.
     * Povinnost, kterou od 1. 1. 2026 hlásí sám pojištěnec, se do registru
     * NEZAKLÁDÁ — připomínat termín, který zaměstnavatel nemá, je šum.
     *
     * @return list<array<string,mixed>>
     */
    public function registerObligations(
        int $supplierId,
        string $environment,
        int $employmentId,
        string $onDate,
        ?int $createdBy = null,
    ): array {
        $registered = [];
        foreach ($this->resolver->resolve(
            $this->requireFacts($supplierId, $employmentId, $onDate),
        ) as $duty) {
            if (!$duty->reportedByEmployer || $duty->deadline === null) {
                $registered[] = [
                    'duty' => $duty->toArray(),
                    'obligation_id' => null,
                    'skipped_reason_code' =>
                        'zp_duty_not_reported_by_employer',
                ];
                continue;
            }
            $sourceHash = hash('sha256', CanonicalJson::encode([
                'schema_reference' => 'payroll-health-notification-obligation.v1',
                'employment_id' => $duty->employmentId,
                'kind' => $duty->kind->value,
                'insurer_code' => $duty->insurerCode,
                'occurred_on' => $duty->occurredOn,
            ]));
            $obligation = $this->obligations->register(
                $supplierId,
                self::AGENDA_BULK_NOTIFICATION,
                self::SUBJECT_EMPLOYMENT,
                $duty->subjectReference(),
                $duty->occurredOn,
                $duty->deadline->dueOn,
                'regular',
                self::CHANNEL,
                self::SOURCE_EVENT_NOTIFICATION,
                $duty->sourceEventReference(),
                $sourceHash,
                $duty->deadline->earliestSubmissionOn,
                $duty->deadline->dueOn,
                $duty->deadline->calendarBasis,
                $duty->deadline->rulesetId,
                $duty->deadline->rulesetHash,
                'health-notification:' . $environment . ':' . $sourceHash,
                null,
                $createdBy,
                null,
                $environment,
            );
            $registered[] = [
                'duty' => $duty->toArray(),
                'obligation_id' => $obligation['id'],
                'skipped_reason_code' => null,
            ];
        }

        return $registered;
    }

    /**
     * Zmrazí přehled o platbě pojistného do odesílatelné podoby. Neodesílá.
     *
     * @return array<string,mixed>
     */
    public function preparePaymentOverview(
        int $supplierId,
        string $environment,
        int $revisionId,
        string $insurerCode,
        ?int $createdBy = null,
    ): array {
        $this->schemas->assertInsurerCode($insurerCode);
        $overview = $this->overviews->overview(
            $supplierId,
            $revisionId,
            $insurerCode,
        );
        $payload = $this->payload($supplierId, $overview);
        $xml = $this->serializer->serializePaymentOverview($payload);
        $window = $this->deadlines->forPaymentOverview($overview->period);
        $sourceHash = hash('sha256', CanonicalJson::encode([
            'schema_reference' => 'payroll-health-payment-overview-submission.v1',
            'revision_id' => $overview->revisionId,
            'insurer_code' => $insurerCode,
            'statutory_result_hash' => $overview->statutoryResultHash,
            'xml_sha256' => hash('sha256', $xml),
        ]));
        $obligation = $this->obligations->register(
            $supplierId,
            self::AGENDA_PAYMENT_OVERVIEW,
            self::SUBJECT_RUN,
            'payroll_run:' . $overview->runId . ':' . $insurerCode,
            // Období povinnosti je mzdový měsíc, za který se pojistné platí,
            // ne okno lhůty. Lhůta má vlastní pole a plést je znamená, že se
            // přehled zařadí do jiného měsíce, než za který je.
            $overview->period . '-01',
            $window->earliestSubmissionOn,
            'regular',
            self::CHANNEL,
            self::SOURCE_EVENT_OVERVIEW,
            'payroll_health_payment_overview:' . $overview->revisionId
                . ':' . $insurerCode,
            $sourceHash,
            $window->earliestSubmissionOn,
            $window->dueOn,
            $window->calendarBasis,
            $window->rulesetId,
            $window->rulesetHash,
            'health-overview-obligation:' . $environment . ':' . $sourceHash,
            null,
            $createdBy,
            null,
            $environment,
        );

        return $this->submissionRepository->transaction(function () use (
            $supplierId,
            $environment,
            $insurerCode,
            $overview,
            $payload,
            $xml,
            $sourceHash,
            $obligation,
            $window,
            $createdBy,
        ): array {
            if (!$this->submissionRepository->lockSupplier($supplierId)) {
                throw new HealthNotificationException(
                    'zp_supplier_missing',
                    'Firma přehledu o platbě nebyla nalezena.',
                );
            }
            $keys = $this->idempotencyKeys($environment, $sourceHash);
            $submission = $this->submissions->prepare(
                $supplierId,
                $obligation['id'],
                'regular',
                self::CHANNEL,
                $sourceHash,
                $keys['submission'],
                // Revize se jako zdroj NEVÁŽE: platforma na ní trvá jen tehdy,
                // když je otisk podání shodný s otiskem výsledku revize. Otisk
                // přehledu ale váže XML a zdravotní výsledek, ne celý běh —
                // provázat je by znamenalo tvrdit shodu, která neplatí.
                null,
                null,
                $createdBy,
                $environment,
            );
            if (!$submission['created']) {
                return [
                    'submission_id' => (int) $submission['id'],
                    'obligation_id' => $obligation['id'],
                    'status' => (string) $submission['status'],
                    'row_version' => (int) $submission['row_version'],
                    'insurer_code' => $insurerCode,
                    'period' => $overview->period,
                    'agenda_code' => self::AGENDA_PAYMENT_OVERVIEW,
                    'artifact_sha256' => hash('sha256', $xml),
                    'created' => false,
                    'deadline' => $window->toArray(),
                    'schema_validated' => $this->schemas->isBundleAvailable(),
                    'dispatch' => $this->dispatchDescription($insurerCode),
                ];
            }
            $part = $this->submissions->addPart(
                $supplierId,
                (int) $submission['id'],
                (int) $submission['row_version'],
                'ppz:' . $overview->revisionId . ':' . $insurerCode,
                self::AGENDA_PAYMENT_OVERVIEW,
                'payroll_run:' . $overview->runId . ':' . $insurerCode,
                'payroll_revision',
                'payroll_health_payment_overview:' . $overview->revisionId
                    . ':' . $insurerCode,
                $sourceHash,
            );
            $artifact = $this->submissions->storeArtifact(
                $supplierId,
                (int) $submission['id'],
                (int) $part['submission_row_version'],
                (int) $part['id'],
                'outbound_xml',
                'outbound',
                'application/xml',
                $xml,
                HealthInsuranceSchemaCatalog::XSD_VERSION,
                null,
                self::CHANNEL,
                $keys['artifact'],
                $createdBy,
            );
            if (!hash_equals(
                hash('sha256', $xml),
                (string) $artifact['artifact_sha256'],
            )) {
                throw new HealthNotificationException(
                    'zp_artifact_mismatch',
                    'Otisk uloženého artefaktu neodpovídá zmrazené datové větě.',
                );
            }

            $rowVersion = (int) $artifact['submission_row_version'];
            $status = (string) $submission['status'];
            $validated = false;
            try {
                $this->validator->validatePaymentOverview($payload, $xml);
                $validated = true;
            } catch (HealthNotificationException $e) {
                // Bez připnutého XSD se podání nesmí označit za ověřené.
                // Artefakt ale zůstává uložený i s výhradou, aby bylo vidět,
                // co se vyrobilo a proč to není připravené k odeslání.
                $issue = $this->submissions->recordIssue(
                    $supplierId,
                    (int) $submission['id'],
                    $rowVersion,
                    (int) $part['id'],
                    'blocker',
                    'xsd',
                    $e->errorCode,
                    'payroll_revision',
                    (string) $overview->revisionId,
                    ['message' => $e->getMessage()],
                    $createdBy,
                );
                $rowVersion = (int) $issue['submission_row_version'];
            }
            if ($validated) {
                $transition = $this->submissions->transition(
                    $supplierId,
                    (int) $submission['id'],
                    $rowVersion,
                    'validated',
                );
                $ready = $this->submissions->transition(
                    $supplierId,
                    (int) $submission['id'],
                    (int) $transition['row_version'],
                    'ready',
                );
                $rowVersion = (int) $ready['row_version'];
                $status = (string) $ready['status'];
            }

            return [
                'submission_id' => (int) $submission['id'],
                'obligation_id' => $obligation['id'],
                'part_id' => (int) $part['id'],
                'artifact_id' => (int) $artifact['id'],
                'status' => $status,
                'row_version' => $rowVersion,
                'insurer_code' => $insurerCode,
                'period' => $overview->period,
                'agenda_code' => self::AGENDA_PAYMENT_OVERVIEW,
                'artifact_sha256' => (string) $artifact['artifact_sha256'],
                'created' => true,
                'deadline' => $window->toArray(),
                'schema_validated' => $validated,
                'dispatch' => $this->dispatchDescription($insurerCode),
            ];
        });
    }

    /**
     * Jak se soubor dostane k pojišťovně.
     *
     * `assertDispatchable()` je `never` — u všech sedmi pojišťoven skončí
     * výjimkou. Metoda proto vždy vrátí popis nedostupnosti; kdyby katalog
     * někdy začal odesílání dokládat, přestala by se výjimka házet a tady
     * by chyběl návrat, takže se `never` vědomě NEobchází a případný doklad
     * si vyžádá i změnu tady.
     *
     * @return array<string,mixed>
     */
    private function dispatchDescription(string $insurerCode): array
    {
        try {
            $this->channels->assertDispatchable($insurerCode);
        } catch (HealthNotificationException $e) {
            return [
                'supported' => false,
                'reason_code' => $e->errorCode,
                'reason' => $e->getMessage(),
                'channel' => $this->channels
                    ->forInsurer($insurerCode)
                    ->toArray(),
            ];
        }
    }

    /**
     * Jedna položka přehledu za období: povinnost obohacená o jméno, kanál
     * a o to, jestli se z ní dá vyrobit kód změny.
     *
     * @return array<string,mixed>
     */
    private function periodItem(
        HealthNotificationDuty $duty,
        string $fullName,
    ): array {
        $documented = $this->codes->isCodeMappingDocumented($duty->kind);
        $code = null;
        $codeReason = null;
        try {
            $code = $this->codes->codeFor($duty->kind);
        } catch (HealthNotificationException $exception) {
            // Konkrétní důvod, ne obecné „nepodařilo se" — u každého ze tří
            // nedoložených druhů je jiný a uživatel podle něj pozná, jestli
            // chybí doklad, nebo povinnost.
            $codeReason = $exception->getMessage();
        }
        $channel = null;
        try {
            $channel = $this->channels->forInsurer($duty->insurerCode)->toArray();
        } catch (HealthNotificationException $exception) {
            $channel = [
                'insurer_code' => $duty->insurerCode,
                'insurer_name' => null,
                'undocumented_reason_code' => $exception->errorCode,
                'note' => $exception->getMessage(),
            ];
        }

        return [
            'id' => $duty->sourceEventReference(),
            'employment_id' => $duty->employmentId,
            'employee_id' => $duty->employeeId,
            'full_name' => $fullName,
            'kind' => $duty->kind->value,
            'label' => $duty->rule->label,
            'insurer_code' => $duty->insurerCode,
            'occurred_on' => $duty->occurredOn,
            'reported_by_employer' => $duty->reportedByEmployer,
            'rule' => $duty->rule->toArray(),
            'deadline' => $duty->deadline?->toArray(),
            'change_code' => [
                'documented' => $documented,
                'code' => $code,
                'reason' => $codeReason,
            ],
            'channel' => $channel,
            'dispatch' => $this->dispatchDescription($duty->insurerCode),
        ];
    }

    /**
     * @param array<string,mixed> $item
     * @param array<string,mixed> $filters
     */
    private function matchesFilters(array $item, array $filters): bool
    {
        $insurer = $filters['insurer_code'] ?? null;
        if (is_string($insurer) && $insurer !== ''
            && $item['insurer_code'] !== $insurer
        ) {
            return false;
        }
        $kind = $filters['kind'] ?? null;
        if (is_string($kind) && $kind !== '' && $item['kind'] !== $kind) {
            return false;
        }
        $reported = $filters['reported'] ?? null;
        if (is_bool($reported) && $item['reported_by_employer'] !== $reported) {
            return false;
        }
        $undocumented = $filters['undocumented_code_only'] ?? null;
        if ($undocumented === true && $item['change_code']['documented']) {
            return false;
        }

        return true;
    }

    /** @return array{from:string,to:string} */
    private function periodBounds(string $period): array
    {
        if (preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/D', $period) !== 1) {
            throw new HealthNotificationException(
                'zp_period_invalid',
                'Mzdové období musí mít tvar RRRR-MM.',
            );
        }
        $from = new \DateTimeImmutable(
            $period . '-01',
            new \DateTimeZone('Europe/Prague'),
        );

        return [
            'from' => $from->format('Y-m-d'),
            'to' => $from->modify('last day of this month')->format('Y-m-d'),
        ];
    }

    /** @param array<string,mixed> $row */
    private function factsFromRow(array $row): HealthNotificationFacts
    {
        return new HealthNotificationFacts(
            employmentId: (int) $row['employment_id'],
            employeeId: (int) $row['employee_id'],
            relationType: (string) $row['relation_type'],
            participates: (bool) $row['participates'],
            insurerCode: $row['insurer_code'],
            startedOn: $row['start_date'],
            endedOn: $row['end_date'],
            previousInsurerCode: $row['previous_insurer_code'],
            insurerChangedOn: $row['insurer_changed_on'],
            maternityLeaveStartedOn: $row['maternity_leave_started_on'],
            parentalLeaveStartedOn: $row['parental_leave_started_on'],
            maternityOrParentalLeaveEndedOn:
                $row['maternity_or_parental_leave_ended_on'],
        );
    }

    private function payload(
        int $supplierId,
        HealthPaymentOverview $overview,
    ): HealthPaymentOverviewPayload {
        $employer = $this->requireEmployer($supplierId);
        $contribution = $overview->totals['total_contribution_minor_units'];
        if ($contribution % 100 !== 0) {
            throw new HealthNotificationException(
                'zp_contribution_not_whole_crowns',
                'Součet pojistného obsahuje haléře, ale datová věta má '
                . 'nonNegativeInteger, tedy celé koruny. Zaokrouhlit smí '
                . 'jen výpočet pojistného, ne podání.',
            );
        }

        return new HealthPaymentOverviewPayload(
            insurerCode: $overview->insurerCode,
            overviewKind: HealthPaymentOverviewPayload::KIND_REGULAR,
            employer: $employer,
            month: (int) substr($overview->period, 5, 2),
            year: (int) substr($overview->period, 0, 4),
            employeeCount: $overview->totals['person_count'],
            assessmentBaseMinorUnits:
                $overview->totals['assessment_base_minor_units'],
            contributionCzk: intdiv($contribution, 100),
        );
    }

    private function requireEmployer(
        int $supplierId,
    ): HealthEmployerIdentification {
        $row = $this->facts->findEmployerIdentification($supplierId);
        if ($row === null) {
            throw new HealthNotificationException(
                'zp_supplier_missing',
                'Firma podání nebyla nalezena.',
            );
        }
        if ($row['business_id'] === null) {
            throw new HealthNotificationException(
                'zp_payer_business_id_missing',
                'Firma nemá vyplněné IČO. Doplňte ho v nastavení firmy — '
                . 'bez něj nelze sestavit identifikační číslo plátce.',
            );
        }

        return HealthEmployerIdentification::fromBusinessId(
            businessId: $row['business_id'],
            // Číslo účtárny plátce aplikace neeviduje; výchozí `00` je
            // doložený tvar pro zaměstnavatele s jedinou účtárnou.
            accountingUnit: '00',
            name: $row['name'],
            street: (string) ($row['street'] ?? ''),
            houseNumber: (string) ($row['house_number'] ?? ''),
            postalCode: (string) ($row['postal_code'] ?? ''),
            city: (string) ($row['city'] ?? ''),
            phone: (string) ($row['phone'] ?? ''),
        );
    }

    private function requireFacts(
        int $supplierId,
        int $employmentId,
        string $onDate,
    ): HealthNotificationFacts {
        if ($supplierId <= 0 || $employmentId <= 0) {
            throw new \InvalidArgumentException(
                'Rozsah zdravotního oznámení není platný.',
            );
        }
        $row = $this->facts->findNotificationFacts(
            $supplierId,
            $employmentId,
            $onDate,
        );
        if ($row === null) {
            throw new \OutOfBoundsException(
                'Pracovní vztah pro zdravotní oznámení nebyl nalezen.',
            );
        }

        return $this->factsFromRow($row);
    }

    /** @return array{submission:string,artifact:string} */
    private function idempotencyKeys(
        string $environment,
        string $sourceHash,
    ): array {
        $fingerprint = hash('sha256', CanonicalJson::encode([
            'schema_reference' => 'payroll-health-overview-submission.v1',
            'environment' => $environment,
            'source_hash' => $sourceHash,
        ]));

        return [
            'submission' => 'health-overview-submission:' . $fingerprint,
            'artifact' => 'health-overview-artifact:' . $fingerprint,
        ];
    }
}
