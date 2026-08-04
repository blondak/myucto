<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollAnnualDocumentRepository;
use MyInvoice\Repository\Payroll\PayrollDocumentRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use PDO;

class AnnualTaxCertificateSnapshotBuilder
{
    public const SCHEMA_VERSION = 'annual-tax-certificate-snapshot.v4';
    public const MAPPING_VERSION = 'annual-tax-certificate-2026-mapping.v4';

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollAnnualDocumentRepository $annualRevisions,
        private readonly PayrollDocumentRepository $documents,
        private readonly PayrollDocumentEmployerSnapshotProvider $employers,
        private readonly AnnualTaxCertificatePaymentEvidenceProvider $payments,
        private readonly PayrollSensitiveData $sensitiveData,
        private readonly SecretEncryption $encryption,
    ) {}

    /**
     * @return array{
     *   revision:array<string,mixed>,
     *   document:AnnualTaxCertificateDocumentData,
     *   archived_document?:array<string,mixed>
     * }
     */
    public function build(
        int $supplierId,
        int $employeeId,
        int $taxYear,
        PayrollDocumentKind $kind,
        ?int $actorUserId,
        ?int $supersedesDocumentId = null,
        ?string $correctionReason = null,
    ): array {
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) {
            throw new \LogicException(
                'Roční daňový snapshot vyžaduje aktivní transakci.',
            );
        }
        if ($supplierId <= 0 || $employeeId <= 0) {
            throw new \InvalidArgumentException(
                'Identita ročního daňového potvrzení není platná.',
            );
        }
        $form = AnnualTaxCertificateFormCatalog::resolve($taxYear, $kind);
        $sources = $this->annualRevisions->lockApprovedYearSources(
            $supplierId,
            $employeeId,
            $taxYear,
        );
        if ($sources === []) {
            throw new \DomainException(
                'Daňové potvrzení nelze vytvořit bez schváleného výsledku '
                . 'zaměstnance v daném roce.',
            );
        }
        $this->assertAnnualSettlementAbsent(
            $supplierId,
            $employeeId,
            $taxYear,
        );
        $profile = $this->profileSnapshot(
            $supplierId,
            $employeeId,
            $taxYear,
        );
        $employer = ($this->employers)($supplierId);
        $cutoff = ($taxYear + 1) . '-01-31';
        [
            'months' => $months,
            'income_minor_units' => $incomeMinorUnits,
            'tax_minor_units' => $taxMinorUnits,
            'tax_bonus_minor_units' => $taxBonusMinorUnits,
            'last_payment_date' => $lastPaymentDate,
            'manifest_sources' => $manifestSources,
            'payment_evidence' => $paymentEvidence,
            'tax_declaration' => $taxDeclaration,
            'tax_residence' => $taxResidence,
        ] = $this->certificateAmounts(
            $sources,
            $supplierId,
            $employeeId,
            $kind,
            $cutoff,
        );
        if ($months === [] || $lastPaymentDate === null) {
            throw new \DomainException(
                'Pro zvolený druh potvrzení neexistuje doložený zdanitelný příjem.',
            );
        }

        $formSnapshot = self::formSnapshot($form);
        $profileHash = $this->fingerprint(
            $profile,
            'annual-tax-certificate-profile-v1',
            $supplierId,
        );
        $employerSnapshot = $employer->toArray();
        $employerHash = $this->fingerprint(
            $employerSnapshot,
            'annual-tax-certificate-employer-v1',
            $supplierId,
        );
        $correctionReason = self::correctionReason($correctionReason);
        if (($supersedesDocumentId === null) !== ($correctionReason === null)) {
            throw new \DomainException(
                'Oprava vyžaduje ID nahrazovaného potvrzení i konkrétní důvod.',
            );
        }
        if ($supersedesDocumentId !== null && $supersedesDocumentId <= 0) {
            throw new \DomainException(
                'ID nahrazovaného potvrzení není platné.',
            );
        }
        $supersededDocument = $supersedesDocumentId === null
            ? null
            : $this->documents->find($supplierId, $supersedesDocumentId);
        if ($supersedesDocumentId !== null
            && !$this->isCompatibleDocument(
                $supersededDocument,
                $employeeId,
                $taxYear,
                $kind,
            )
        ) {
            throw new \DomainException(
                'Nahrazované potvrzení nepatří zvolené osobě, roku a druhu.',
            );
        }
        $replacesIssuedAt = $supersededDocument === null
            ? null
            : $this->documentIssuedAt($supersededDocument);
        $issuanceManifest = [
            'supersedes_document_id' => $supersedesDocumentId,
            'replaces_issued_at' => $replacesIssuedAt,
            'correction_reason' => $correctionReason,
        ];
        $manifest = [
            'schema_version' =>
                'annual-tax-certificate-source-manifest.v2',
            'document_schema_version' =>
                AnnualTaxCertificateDocumentData::SCHEMA_VERSION,
            'renderer_version' => AnnualTaxCertificatePdfRenderer::VERSION,
            'snapshot_schema_version' => self::SCHEMA_VERSION,
            'mapping_version' => self::MAPPING_VERSION,
            'purpose' => $kind->value,
            'tax_year' => $taxYear,
            'employee_id' => $employeeId,
            'form' => $formSnapshot,
            'profile_snapshot_hash' => $profileHash,
            'employer_snapshot_hash' => $employerHash,
            'sources' => $manifestSources,
            'issuance' => $issuanceManifest,
        ];
        $manifestJson = CanonicalJson::encode($manifest);
        $manifestHash = hash('sha256', $manifestJson);
        $existing = $this->annualRevisions->findBySourceManifest(
            $supplierId,
            $employeeId,
            $taxYear,
            $kind->value,
            $manifestHash,
        );
        if ($existing !== null) {
            $archived = $this->documents->findAnnualArtifact(
                $supplierId,
                $this->positiveInt($existing, 'id'),
                $employeeId,
                $kind->value,
                AnnualTaxCertificateDocumentData::SCHEMA_VERSION,
                AnnualTaxCertificatePdfRenderer::VERSION,
            );
            $existingSnapshotHash = $this->hash(
                $existing,
                'snapshot_hash',
            );

            $prepared = [
                'revision' => $existing,
                'document' => $this->hydrate(
                    $this->decryptSnapshot($existing, $kind),
                    $existingSnapshotHash,
                    $kind,
                ),
            ];
            if ($archived !== null) {
                $prepared['archived_document'] = $archived;
            }

            return $prepared;
        }
        $latestDocument = $this->documents->latestForAnnualKind(
            $supplierId,
            $employeeId,
            $taxYear,
            $kind->value,
            $kind->value,
        );
        if ($latestDocument !== null && $supersedesDocumentId === null) {
            throw new \DomainException(
                'Opravné daňové potvrzení vyžaduje aktuální ID '
                . 'nahrazovaného dokumentu a konkrétní důvod.',
            );
        }
        if ($latestDocument === null && $supersedesDocumentId !== null) {
            throw new \DomainException(
                'Opravu nelze vytvořit bez předchozího vydaného potvrzení.',
            );
        }
        if ($latestDocument !== null
            && $this->positiveInt($latestDocument, 'id')
                !== $supersedesDocumentId
        ) {
            throw new \DomainException(
                'Nahrazované potvrzení již není aktuální; obnovte seznam dokumentů.',
            );
        }
        $issuedAt = $this->databaseTimestamp();
        $snapshot = [
            'schema_version' => self::SCHEMA_VERSION,
            'mapping_version' => self::MAPPING_VERSION,
            'purpose' => $kind->value,
            'tax_year' => $taxYear,
            'form' => $formSnapshot,
            'employer' => $employerSnapshot,
            'employee' => $profile,
            'months' => $months,
            'tax_declaration' => $taxDeclaration,
            'tax_residence' => $taxResidence,
            'issued_at' => $issuedAt,
            'supersedes_document_id' => $supersedesDocumentId,
            'replaces_issued_at' => $replacesIssuedAt,
            'correction_reason' => $correctionReason,
            'employer_product_contributions_minor_units' => [
                'supplementary_pension' => 0,
                'pension_insurance' => 0,
                'private_life_insurance' => 0,
                'long_term_investment_product' => 0,
            ],
            'child_tax_benefits' => [],
            'disability_tax_credits' => [],
            'annual_settlement' => [
                'performed' => false,
                'result' => null,
            ],
            'nonresident_insurance_minor_units' => null,
            'accrued_income_minor_units' => $incomeMinorUnits,
            'paid_income_minor_units' => $incomeMinorUnits,
            'advance_tax_minor_units' =>
                $kind
                === PayrollDocumentKind::TaxableIncomeAdvanceCertificate
                    ? $taxMinorUnits
                    : 0,
            'withholding_tax_minor_units' =>
                $kind
                === PayrollDocumentKind::TaxableIncomeWithholdingCertificate
                    ? $taxMinorUnits
                    : 0,
            'tax_bonus_minor_units' => $taxBonusMinorUnits,
            'payment_evidence_cutoff' => $cutoff,
            'last_proven_payment_date' => $lastPaymentDate,
            'payment_evidence' => $paymentEvidence,
        ];
        $snapshotJson = CanonicalJson::encode($snapshot);
        $snapshotHash = $this->sensitiveData->keyedFingerprint(
            $snapshotJson,
            'annual-tax-certificate-snapshot-v1',
            $supplierId,
        );

        $previous = $this->annualRevisions->latest(
            $supplierId,
            $employeeId,
            $taxYear,
            $kind->value,
        );
        $ciphertext = $this->encryption->encryptFor(
            $snapshotJson,
            $this->encryptionContext(
                $supplierId,
                $employeeId,
                $taxYear,
                $kind,
                $manifestHash,
            ),
        );
        $revision = $this->annualRevisions->insertApproved([
            'supplier_id' => $supplierId,
            'employee_id' => $employeeId,
            'tax_year' => $taxYear,
            'purpose' => $kind->value,
            'revision_no' =>
                $previous === null
                    ? 1
                    : $this->positiveInt($previous, 'revision_no') + 1,
            'previous_revision_id' =>
                $previous === null
                    ? null
                    : $this->positiveInt($previous, 'id'),
            'snapshot_ciphertext' => $ciphertext,
            'snapshot_hash' => $snapshotHash,
            'source_manifest_json' => $manifestJson,
            'source_manifest_hash' => $manifestHash,
            'approved_by' => $actorUserId,
        ], $sources);

        return [
            'revision' => $revision,
            'document' => $this->hydrate($snapshot, $snapshotHash, $kind),
        ];
    }

    /**
     * @param list<array<string,mixed>> $sources
     * @return array{
     *   months:list<int>,
     *   income_minor_units:int,
     *   tax_minor_units:int,
     *   tax_bonus_minor_units:int,
     *   last_payment_date:?string,
     *   manifest_sources:list<array<string,mixed>>,
     *   payment_evidence:list<array<string,mixed>>,
     *   tax_declaration:?array{
     *     status:string,
     *     signed_months:list<int>,
     *     monthly_evidence:list<array{
     *       month:int,
     *       status:string,
     *       effective_from:string,
     *       effective_to:?string
     *     }>
     *   },
     *   tax_residence:array{status:string,country_code:string}
     * }
     */
    private function certificateAmounts(
        array $sources,
        int $supplierId,
        int $employeeId,
        PayrollDocumentKind $kind,
        string $cutoff,
    ): array {
        $months = [];
        $income = 0;
        $tax = 0;
        $taxBonus = 0;
        $lastPaymentDate = null;
        $manifestSources = [];
        $paymentEvidence = [];
        $declarationEvidence = [];
        $taxResidence = null;
        foreach ($sources as $source) {
            $inputJson = $this->text($source, 'input_snapshot_json');
            $resultJson = $this->text($source, 'result_snapshot_json');
            $personJson = $this->text($source, 'person_result_json');
            $inputHash = $this->hash($source, 'input_snapshot_hash');
            $resultHash = $this->hash($source, 'result_snapshot_hash');
            $personHash = $this->hash($source, 'person_result_hash');
            $this->assertJsonHash($inputJson, $inputHash, 'vstupní revize');
            $this->assertJsonHash($resultJson, $resultHash, 'výsledné revize');
            $this->assertJsonHash($personJson, $personHash, 'výsledku osoby');

            $inputRoot = $this->decodeObject(
                $inputJson,
                'Vstupní snapshot schválené revize',
            );
            $resultRoot = $this->decodeObject(
                $resultJson,
                'Výsledek schválené revize',
            );
            $storedPerson = $this->decodeObject(
                $personJson,
                'Výsledek zaměstnance',
            );
            $resultPerson = $this->personFromResult(
                $resultRoot,
                $employeeId,
            );
            if (!hash_equals(
                $personHash,
                hash('sha256', CanonicalJson::encode($resultPerson)),
            ) || !hash_equals(
                $personHash,
                hash('sha256', CanonicalJson::encode($storedPerson)),
            )) {
                throw new \DomainException(
                    'Výsledek zaměstnance nesouhlasí se schválenou revizí.',
                );
            }
            $inputPerson = $this->personFromInput(
                $inputRoot,
                $employeeId,
            );
            $periodStart = $this->text($source, 'period_start');
            if (preg_match('/^\d{4}-(0[1-9]|1[0-2])-01$/D', $periodStart) !== 1) {
                throw new \DomainException(
                    'Zdroj daňového potvrzení má neplatné období.',
                );
            }
            $monthNumber = (int) substr($periodStart, 5, 2);
            $amounts = $this->monthAmounts(
                $storedPerson,
                $inputPerson,
                $kind,
            );
            $proofHash = null;
            if ($amounts['income_minor_units'] > 0) {
                $proof = $this->payments->prove(
                    $supplierId,
                    $employeeId,
                    $this->positiveInt($source, 'run_id'),
                    $this->positiveInt($source, 'revision_id'),
                    $amounts['expected_net_minor_units'],
                    $cutoff,
                );
                $proofHash = $this->fingerprint(
                    $proof,
                    'annual-tax-certificate-payment-proof-v1',
                    $supplierId,
                );
                $paymentEvidence[] = $proof;
                $months[$monthNumber] = true;
                $monthTaxEvidence = $amounts['tax_evidence'];
                $monthResidence = $monthTaxEvidence['residence'];
                if ($taxResidence !== null
                    && $taxResidence !== $monthResidence
                ) {
                    throw new \DomainException(
                        'Daňová rezidence není ve zdrojových měsících jednotná.',
                    );
                }
                $taxResidence = $monthResidence;
                if ($kind
                    === PayrollDocumentKind::TaxableIncomeAdvanceCertificate
                ) {
                    $declaration = $monthTaxEvidence['declaration'];
                    if ($declaration === null) {
                        throw new \DomainException(
                            'Prohlášení poplatníka ve zdrojovém měsíci chybí.',
                        );
                    }
                    $declarationEvidence[] = [
                        'month' => $monthNumber,
                        'status' => $declaration['status'],
                        'effective_from' => $declaration['effective_from'],
                        'effective_to' => $declaration['effective_to'],
                    ];
                }
                $income = $this->add(
                    $income,
                    $amounts['income_minor_units'],
                );
                $tax = $this->add($tax, $amounts['tax_minor_units']);
                $taxBonus = $this->add(
                    $taxBonus,
                    $amounts['tax_bonus_minor_units'],
                );
                if ($lastPaymentDate === null
                    || $proof['last_payment_date'] > $lastPaymentDate
                ) {
                    $lastPaymentDate = $proof['last_payment_date'];
                }
            }
            $manifestSources[] = [
                'period_start' => $periodStart,
                'run_id' => $this->positiveInt($source, 'run_id'),
                'revision_id' => $this->positiveInt($source, 'revision_id'),
                'input_snapshot_hash' => $inputHash,
                'result_snapshot_hash' => $resultHash,
                'person_result_hash' => $personHash,
                'payment_evidence_hash' => $proofHash,
            ];
        }
        $monthList = array_map(
            static fn (int|string $month): int => (int) $month,
            array_keys($months),
        );
        sort($monthList, SORT_NUMERIC);
        usort(
            $declarationEvidence,
            static fn (array $left, array $right): int =>
                $left['month'] <=> $right['month'],
        );
        $signedMonths = array_values(array_map(
            static fn (array $evidence): int => $evidence['month'],
            array_filter(
                $declarationEvidence,
                static fn (array $evidence): bool =>
                    $evidence['status'] === 'signed',
            ),
        ));
        $taxDeclaration = null;
        if ($kind === PayrollDocumentKind::TaxableIncomeAdvanceCertificate) {
            $status = match (count($signedMonths)) {
                0 => 'not-signed',
                count($monthList) => 'signed',
                default => 'mixed',
            };
            $taxDeclaration = [
                'status' => $status,
                'signed_months' => $signedMonths,
                'monthly_evidence' => $declarationEvidence,
            ];
        }
        if ($taxResidence === null) {
            throw new \DomainException(
                'Daňové potvrzení nemá doloženou daňovou rezidenci.',
            );
        }

        return [
            'months' => $monthList,
            'income_minor_units' => $income,
            'tax_minor_units' => $tax,
            'tax_bonus_minor_units' => $taxBonus,
            'last_payment_date' => $lastPaymentDate,
            'manifest_sources' => $manifestSources,
            'payment_evidence' => $paymentEvidence,
            'tax_declaration' => $taxDeclaration,
            'tax_residence' => $taxResidence,
        ];
    }

    /**
     * @param array<string,mixed> $person
     * @param array<string,mixed> $inputPerson
     * @return array{
     *   income_minor_units:int,
     *   tax_minor_units:int,
     *   tax_bonus_minor_units:int,
     *   expected_net_minor_units:int,
     *   tax_evidence:array{
     *     declaration:?array{
     *       status:string,
     *       effective_from:string,
     *       effective_to:?string
     *     },
     *     residence:array{status:string,country_code:string}
     *   }
     * }
     */
    private function monthAmounts(
        array $person,
        array $inputPerson,
        PayrollDocumentKind $kind,
    ): array {
        $statutory = $this->object(
            $person['statutory'] ?? null,
            'statutory',
        );
        if (($statutory['status'] ?? null) !== 'calculated') {
            throw new \DomainException(
                'Daňové potvrzení vyžaduje uzavřený zákonný výpočet.',
            );
        }
        $tax = $this->object(
            $statutory['income_tax'] ?? null,
            'income_tax',
        );
        if (($tax['status'] ?? null) !== 'calculated') {
            throw new \DomainException(
                'Daňové potvrzení vyžaduje uzavřený výpočet daně.',
            );
        }
        $net = $this->object(
            $statutory['net_pay'] ?? null,
            'net_pay',
        );
        $advance = ($tax['advance_tax'] ?? null) === null
            ? []
            : $this->object($tax['advance_tax'], 'advance_tax');
        $income = $kind
            === PayrollDocumentKind::TaxableIncomeAdvanceCertificate
                ? ($advance === []
                    ? 0
                    : $this->nonNegativeInt(
                        $advance,
                        'taxable_income_minor_units',
                    ))
                : $this->nonNegativeInt(
                    $tax,
                    'withholding_base_minor_units',
                );
        $taxAmount = $kind
            === PayrollDocumentKind::TaxableIncomeAdvanceCertificate
                ? $this->nonNegativeInt($net, 'advance_tax_minor_units')
                : $this->nonNegativeInt(
                    $tax,
                    'withholding_tax_minor_units',
                );
        $taxBonus = $kind
            === PayrollDocumentKind::TaxableIncomeAdvanceCertificate
                ? $this->nonNegativeInt($net, 'tax_bonus_minor_units')
                : 0;
        if ($income > 0) {
            if ($this->nonNegativeInt(
                $net,
                'non_cash_income_minor_units',
            ) > 0) {
                throw new \DomainException(
                    'Nepeněžní příjem nemá doložené datum skutečného obdržení.',
                );
            }
            $taxEvidence = $this->supportedInputEvidence(
                $inputPerson,
                $kind,
            );
            $this->assertNoBackpay($inputPerson);
            foreach ([
                'zdanitelný příjem' => $income,
                'skutečně sražená daň' => $taxAmount,
                'daňový bonus' => $taxBonus,
            ] as $label => $amount) {
                if ($amount % 100 !== 0) {
                    throw new \DomainException(
                        "Měsíční {$label} nelze vykázat v celých Kč.",
                    );
                }
            }
        }
        $expectedNet = $this->nonNegativeInt(
            $person,
            'payable_after_enforcement_minor',
        );
        if ($income > 0 && $expectedNet <= 0) {
            throw new \DomainException(
                'Skutečnou výplatu zdanitelného příjmu nelze doložit '
                . 'nulovým závazkem čisté mzdy.',
            );
        }

        return [
            'income_minor_units' => $income,
            'tax_minor_units' => $taxAmount,
            'tax_bonus_minor_units' => $taxBonus,
            'expected_net_minor_units' => $expectedNet,
            'tax_evidence' => $taxEvidence ?? [
                'declaration' => null,
                'residence' => [
                    'status' => 'czech-resident',
                    'country_code' => 'CZ',
                ],
            ],
        ];
    }

    /**
     * @param array<string,mixed> $inputPerson
     * @return array{
     *   declaration:?array{
     *     status:string,
     *     effective_from:string,
     *     effective_to:?string
     *   },
     *   residence:array{status:string,country_code:string}
     * }
     */
    private function supportedInputEvidence(
        array $inputPerson,
        PayrollDocumentKind $kind,
    ): array {
        $statutory = $this->object(
            $inputPerson['statutory_evidence'] ?? null,
            'statutory_evidence',
        );
        $incomeTax = $this->object(
            $statutory['income_tax'] ?? null,
            'statutory_evidence.income_tax',
        );
        $residence = $this->object(
            $incomeTax['residence'] ?? null,
            'statutory_evidence.income_tax.residence',
        );
        if (($residence['residence'] ?? null) !== 'czech-resident'
            || ($residence['country_code'] ?? null) !== 'CZ'
        ) {
            throw new \DomainException(
                'Daňové potvrzení nerezidenta vyžaduje rozšířená pole, '
                . 'která tato verze nevymýšlí.',
            );
        }
        $declaration = null;
        if ($kind === PayrollDocumentKind::TaxableIncomeAdvanceCertificate) {
            if (($incomeTax['declaration'] ?? null) === null) {
                throw new \DomainException(
                    'Prohlášení poplatníka ve zdrojovém měsíci chybí.',
                );
            }
            $declaration = $this->object(
                $incomeTax['declaration'],
                'statutory_evidence.income_tax.declaration',
            );
            $status = $declaration['status'] ?? null;
            if (!in_array($status, ['signed', 'not-signed'], true)) {
                throw new \DomainException(
                    'Prohlášení poplatníka nemá ověřený stav.',
                );
            }
            $effectiveFrom = $this->text(
                $declaration,
                'effective_from',
            );
            $effectiveTo = $declaration['effective_to'] ?? null;
            if ($effectiveTo !== null && !is_string($effectiveTo)) {
                throw new \DomainException(
                    'Konec účinnosti Prohlášení poplatníka není platný.',
                );
            }
            $declaration = [
                'status' => $status,
                'effective_from' => $effectiveFrom,
                'effective_to' => $effectiveTo,
            ];
        }
        foreach ($this->list(
            $incomeTax['credit_claims'] ?? null,
            'statutory_evidence.income_tax.credit_claims',
        ) as $claim) {
            $claim = $this->object($claim, 'credit_claims[]');
            if (($claim['credit_kind'] ?? null) !== 'taxpayer'
                || ($claim['evidence_status'] ?? null) !== 'verified'
            ) {
                throw new \DomainException(
                    'Daňové potvrzení s invalidní, ZTP/P nebo jinou '
                    . 'rozšířenou slevou vyžaduje samostatné mapování formuláře.',
                );
            }
        }
        if ($this->list(
            $incomeTax['child_claims'] ?? null,
            'statutory_evidence.income_tax.child_claims',
        ) !== []) {
            throw new \DomainException(
                'Daňové potvrzení s daňovým zvýhodněním na dítě vyžaduje '
                . 'identitu dítěte, kterou mzdový snapshot neobsahuje.',
            );
        }

        return [
            'declaration' => $declaration,
            'residence' => [
                'status' => 'czech-resident',
                'country_code' => 'CZ',
            ],
        ];
    }

    /** @param array<string,mixed> $inputPerson */
    private function assertNoBackpay(array $inputPerson): void
    {
        foreach ($this->list(
            $inputPerson['employments'] ?? null,
            'input.employments',
        ) as $employment) {
            $employment = $this->object($employment, 'input.employments[]');
            foreach ($this->list(
                $employment['inputs'] ?? null,
                'input.employments[].inputs',
            ) as $input) {
                $input = $this->object($input, 'input');
                $component = $this->object(
                    $input['component'] ?? null,
                    'input.component',
                );
                $amount = $this->int($input, 'amount_minor');
                $componentKind = $component['kind'] ?? null;
                if ($amount !== 0) {
                    if ($componentKind === 'backpay') {
                        throw new \DomainException(
                            'Doplatek mzdy nelze bez rozlišení původního roku '
                            . 'správně rozdělit do řádků formuláře.',
                        );
                    }
                    if (in_array($componentKind, [
                        'benefit_pension',
                        'benefit_care',
                        'risky_savings',
                    ], true)) {
                        throw new \DomainException(
                            'Příspěvek zaměstnavatele na podporovaný produkt '
                            . 'vyžaduje samostatné mapování řádku 10 formuláře.',
                        );
                    }
                }
            }
        }
    }

    /** @return array<string,mixed> */
    private function profileSnapshot(
        int $supplierId,
        int $employeeId,
        int $taxYear,
    ): array {
        $from = sprintf('%04d-01-01', $taxYear);
        $to = sprintf('%04d-12-31', $taxYear);
        $identities = $this->db->pdo()->prepare(
            'SELECT id, full_name, first_name, last_name, birth_surname,
                    effective_from, effective_to
               FROM payroll_person_identity_history
              WHERE supplier_id = ? AND employee_id = ?
                AND effective_from <= ?
                AND (effective_to IS NULL OR effective_to >= ?)
              ORDER BY effective_from, id
              FOR UPDATE',
        );
        $identities->execute([$supplierId, $employeeId, $to, $from]);
        $fetchedIdentities = $identities->fetchAll(PDO::FETCH_ASSOC);
        if ($fetchedIdentities === []) {
            throw new \DomainException(
                'Pro daňové potvrzení chybí účinná historie jména.',
            );
        }
        $identityRows = array_map(
            $this->associativeRow(...),
            $fetchedIdentities,
        );
        $currentIdentity = $identityRows[array_key_last($identityRows)];
        $currentName = $this->text($currentIdentity, 'full_name');
        $firstName = $this->text($currentIdentity, 'first_name');
        $lastName = $this->text($currentIdentity, 'last_name');
        $names = [];
        foreach ($identityRows as $identity) {
            foreach ([
                $identity['full_name'] ?? null,
                $identity['birth_surname'] ?? null,
            ] as $name) {
                if (is_string($name)
                    && trim($name) !== ''
                    && trim($name) !== $currentName
                ) {
                    $names[trim($name)] = true;
                }
            }
        }

        $address = $this->db->pdo()->prepare(
            'SELECT id, street_line, city, postal_code, country_code
               FROM payroll_person_addresses
              WHERE supplier_id = ? AND employee_id = ?
                AND address_type = "residence"
                AND effective_from <= ?
                AND (effective_to IS NULL OR effective_to >= ?)
              ORDER BY effective_from DESC, id DESC
              LIMIT 1
              FOR UPDATE',
        );
        $address->execute([$supplierId, $employeeId, $to, $from]);
        $fetchedAddress = $address->fetch(PDO::FETCH_ASSOC);
        if ($fetchedAddress === false) {
            throw new \DomainException(
                'Pro daňové potvrzení chybí účinná adresa bydliště.',
            );
        }
        $addressRow = $this->associativeRow($fetchedAddress);
        if (($addressRow['country_code'] ?? null) !== 'CZ') {
            throw new \DomainException(
                'Daňové potvrzení zahraniční osoby vyžaduje rozšířenou '
                . 'identitu, kterou tato verze nevymýšlí.',
            );
        }
        $identifier = $this->db->pdo()->prepare(
            'SELECT id, value_ciphertext
               FROM payroll_person_identifiers
              WHERE supplier_id = ? AND employee_id = ?
                AND identifier_type = "birth_number"
              LIMIT 1
              FOR UPDATE',
        );
        $identifier->execute([$supplierId, $employeeId]);
        $fetchedIdentifier = $identifier->fetch(PDO::FETCH_ASSOC);
        if ($fetchedIdentifier === false) {
            throw new \DomainException(
                'Pro daňové potvrzení českého rezidenta chybí rodné číslo.',
            );
        }
        $identifierRow = $this->associativeRow($fetchedIdentifier);

        return [
            'name' => $currentName,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'previous_names' => array_keys($names),
            'identifier_label' => 'Rodné číslo',
            'identifier_value' => $this->sensitiveData->reveal(
                $this->text($identifierRow, 'value_ciphertext'),
                PayrollSensitiveField::PERSONAL_IDENTIFIER,
                $supplierId,
                $this->positiveInt($identifierRow, 'id'),
            ),
            'address' => implode(', ', [
                $this->text($addressRow, 'street_line'),
                trim(
                    $this->text($addressRow, 'postal_code')
                    . ' '
                    . $this->text($addressRow, 'city'),
                ),
                'CZ',
            ]),
        ];
    }

    private function assertAnnualSettlementAbsent(
        int $supplierId,
        int $employeeId,
        int $taxYear,
    ): void {
        $statement = $this->db->pdo()->prepare(
            'SELECT id
               FROM payroll_annual_document_revisions
              WHERE supplier_id = ?
                AND employee_id = ?
                AND tax_year = ?
                AND purpose = "annual_settlement_result"
              LIMIT 1
              FOR UPDATE',
        );
        $statement->execute([$supplierId, $employeeId, $taxYear]);
        if ($statement->fetchColumn() !== false) {
            throw new \DomainException(
                'Schválené roční zúčtování vyžaduje mapování dalších řádků '
                . 'potvrzení a nelze je tiše vynechat.',
            );
        }
    }

    /** @param array<string,mixed> $root
     *  @return array<string,mixed>
     */
    private function personFromResult(array $root, int $employeeId): array
    {
        $match = null;
        foreach ($this->list($root['people'] ?? null, 'result.people') as $row) {
            $person = $this->object($row, 'result.people[]');
            if ($this->positiveInt($person, 'employee_id') === $employeeId) {
                if ($match !== null) {
                    throw new \DomainException(
                        'Schválená revize obsahuje zaměstnance vícekrát.',
                    );
                }
                $match = $person;
            }
        }
        if ($match === null) {
            throw new \DomainException(
                'Schválená revize neobsahuje zaměstnance.',
            );
        }

        return $match;
    }

    /** @param array<string,mixed> $root
     *  @return array<string,mixed>
     */
    private function personFromInput(array $root, int $employeeId): array
    {
        $match = null;
        foreach ($this->list($root['people'] ?? null, 'input.people') as $row) {
            $person = $this->object($row, 'input.people[]');
            $employee = $this->object(
                $person['employee'] ?? null,
                'input.people[].employee',
            );
            if ($this->positiveInt($employee, 'id') === $employeeId) {
                if ($match !== null) {
                    throw new \DomainException(
                        'Vstupní revize obsahuje zaměstnance vícekrát.',
                    );
                }
                $match = $person;
            }
        }
        if ($match === null) {
            throw new \DomainException(
                'Vstupní revize neobsahuje zaměstnance.',
            );
        }

        return $match;
    }

    /**
     * @param array<string,mixed> $revision
     * @return array<string,mixed>
     */
    private function decryptSnapshot(
        array $revision,
        PayrollDocumentKind $kind,
    ): array {
        $json = $this->encryption->decryptFor(
            $this->text($revision, 'snapshot_ciphertext'),
            $this->encryptionContext(
                $this->positiveInt($revision, 'supplier_id'),
                $this->positiveInt($revision, 'employee_id'),
                $this->positiveInt($revision, 'tax_year'),
                $kind,
                $this->hash($revision, 'source_manifest_hash'),
            ),
        );
        $hash = $this->hash($revision, 'snapshot_hash');
        $expected = $this->sensitiveData->keyedFingerprint(
            $json,
            'annual-tax-certificate-snapshot-v1',
            $this->positiveInt($revision, 'supplier_id'),
        );
        if (!hash_equals($hash, $expected)) {
            throw new \DomainException(
                'Otisk ročního daňového snapshotu nesouhlasí.',
            );
        }

        return $this->decodeObject($json, 'Roční daňový snapshot');
    }

    /**
     * @param array<string,mixed> $snapshot
     */
    private function hydrate(
        array $snapshot,
        string $snapshotHash,
        PayrollDocumentKind $kind,
    ): AnnualTaxCertificateDocumentData {
        if (($snapshot['schema_version'] ?? null) !== self::SCHEMA_VERSION
            || ($snapshot['mapping_version'] ?? null) !== self::MAPPING_VERSION
            || ($snapshot['purpose'] ?? null) !== $kind->value
        ) {
            throw new \DomainException(
                'Roční daňový snapshot má nepodporované schéma nebo účel.',
            );
        }
        $taxYear = $this->positiveInt($snapshot, 'tax_year');
        $expectedForm = self::formSnapshot(
            AnnualTaxCertificateFormCatalog::resolve($taxYear, $kind),
        );
        $storedForm = $this->object($snapshot['form'] ?? null, 'form');
        if (!hash_equals(
            CanonicalJson::encode($storedForm),
            CanonicalJson::encode($expectedForm),
        )) {
            throw new \DomainException(
                'Roční daňový snapshot neodpovídá katalogu formulářů.',
            );
        }
        $employer = $this->object($snapshot['employer'] ?? null, 'employer');
        $employerAddress = $this->object(
            $employer['address'] ?? null,
            'employer.address',
        );
        $issuer = $this->object(
            $employer['issuer'] ?? null,
            'employer.issuer',
        );
        $employee = $this->object($snapshot['employee'] ?? null, 'employee');
        $previousNames = $this->list(
            $employee['previous_names'] ?? null,
            'employee.previous_names',
        );
        foreach ($previousNames as $name) {
            if (!is_string($name)) {
                throw new \DomainException(
                    'Dřívější jméno v daňovém snapshotu není text.',
                );
            }
        }
        $months = $this->list($snapshot['months'] ?? null, 'months');
        foreach ($months as $month) {
            if (!is_int($month)) {
                throw new \DomainException(
                    'Měsíc v daňovém snapshotu není celé číslo.',
                );
            }
        }
        $taxResidence = $this->object(
            $snapshot['tax_residence'] ?? null,
            'tax_residence',
        );
        $taxDeclaration = $snapshot['tax_declaration'] ?? null;
        $taxDeclarationStatus = null;
        $taxDeclarationSignedMonths = [];
        if ($kind === PayrollDocumentKind::TaxableIncomeAdvanceCertificate) {
            $taxDeclaration = $this->object(
                $taxDeclaration,
                'tax_declaration',
            );
            $taxDeclarationStatus = $this->text(
                $taxDeclaration,
                'status',
            );
            $taxDeclarationSignedMonths = $this->list(
                $taxDeclaration['signed_months'] ?? null,
                'tax_declaration.signed_months',
            );
            foreach ($taxDeclarationSignedMonths as $month) {
                if (!is_int($month)) {
                    throw new \DomainException(
                        'Podepsaný měsíc Prohlášení není celé číslo.',
                    );
                }
            }
        } elseif ($taxDeclaration !== null) {
            throw new \DomainException(
                'Srážkový snapshot nesmí obsahovat Prohlášení poplatníka.',
            );
        }

        return new AnnualTaxCertificateDocumentData(
            sourceSnapshotSha256: $snapshotHash,
            kind: $kind,
            taxYear: $taxYear,
            form: AnnualTaxCertificateFormCatalog::resolve($taxYear, $kind),
            employer: new PayrollDocumentEmployerSnapshot(
                name: $this->text($employer, 'name'),
                identificationNumber:
                    $this->text($employer, 'identification_number'),
                taxIdentificationNumber:
                    $this->text($employer, 'tax_identification_number'),
                streetLine: $this->text($employerAddress, 'street_line'),
                city: $this->text($employerAddress, 'city'),
                postalCode: $this->text($employerAddress, 'postal_code'),
                countryCode: $this->text($employerAddress, 'country_code'),
                countryName: $this->text($employerAddress, 'country_name'),
                issuerName: $this->text($issuer, 'name'),
                issuerEmail: $this->text($issuer, 'email'),
                issuerPhone: $this->text($issuer, 'phone'),
            ),
            employeeName: $this->text($employee, 'name'),
            employeeFirstName: $this->text($employee, 'first_name'),
            employeeLastName: $this->text($employee, 'last_name'),
            previousNames: $previousNames,
            personalIdentifierLabel:
                $this->text($employee, 'identifier_label'),
            personalIdentifierValue:
                $this->text($employee, 'identifier_value'),
            employeeAddress: $this->text($employee, 'address'),
            months: $months,
            taxDeclarationStatus: $taxDeclarationStatus,
            taxDeclarationSignedMonths: $taxDeclarationSignedMonths,
            taxResidenceStatus: $this->text($taxResidence, 'status'),
            taxResidenceCountryCode:
                $this->text($taxResidence, 'country_code'),
            issuedAt: $this->timestamp($snapshot, 'issued_at'),
            replacesIssuedAt:
                $this->nullableTimestamp($snapshot, 'replaces_issued_at'),
            correctionReason:
                $this->nullableText($snapshot, 'correction_reason'),
            employerProductContributionsMinorUnits:
                $this->productContributions($snapshot),
            childTaxBenefits:
                $this->objectList(
                    $snapshot['child_tax_benefits'] ?? null,
                    'child_tax_benefits',
                ),
            disabilityTaxCredits:
                $this->objectList(
                    $snapshot['disability_tax_credits'] ?? null,
                    'disability_tax_credits',
                ),
            annualSettlement:
                $this->annualSettlement($snapshot),
            nonresidentInsuranceMinorUnits:
                $this->nullableNonNegativeInt(
                    $snapshot,
                    'nonresident_insurance_minor_units',
                ),
            accruedIncomeMinorUnits:
                $this->nonNegativeInt(
                    $snapshot,
                    'accrued_income_minor_units',
                ),
            paidIncomeMinorUnits:
                $this->nonNegativeInt($snapshot, 'paid_income_minor_units'),
            advanceTaxMinorUnits:
                $this->nonNegativeInt($snapshot, 'advance_tax_minor_units'),
            withholdingTaxMinorUnits:
                $this->nonNegativeInt(
                    $snapshot,
                    'withholding_tax_minor_units',
                ),
            taxBonusMinorUnits:
                $this->nonNegativeInt($snapshot, 'tax_bonus_minor_units'),
            paymentEvidenceCutoff:
                $this->text($snapshot, 'payment_evidence_cutoff'),
            lastProvenPaymentDate:
                $this->text($snapshot, 'last_proven_payment_date'),
        );
    }

    private function encryptionContext(
        int $supplierId,
        int $employeeId,
        int $taxYear,
        PayrollDocumentKind $kind,
        string $manifestHash,
    ): string {
        return implode(':', [
            'payroll-annual-document',
            (string) $supplierId,
            (string) $employeeId,
            (string) $taxYear,
            $kind->value,
            $manifestHash,
        ]);
    }

    /** @param array<string,mixed>|null $document */
    private function isCompatibleDocument(
        ?array $document,
        int $employeeId,
        int $taxYear,
        PayrollDocumentKind $kind,
    ): bool {
        if ($document === null
            || ($document['employee_id'] ?? null) !== $employeeId
            || ($document['document_kind'] ?? null) !== $kind->value
            || !is_int($document['supplier_id'] ?? null)
            || !is_int($document['annual_revision_id'] ?? null)
        ) {
            return false;
        }
        $annual = $this->documents->approvedAnnualRevision(
            $document['supplier_id'],
            $document['annual_revision_id'],
        );

        return $annual !== null
            && ($annual['tax_year'] ?? null) === $taxYear
            && ($annual['purpose'] ?? null) === $kind->value;
    }

    /** @param array<string,mixed> $document */
    private function documentIssuedAt(array $document): string
    {
        $createdAt = $this->text($document, 'created_at');
        if (preg_match(
            '/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})$/D',
            $createdAt,
            $matches,
        ) !== 1) {
            throw new \DomainException(
                'Datum vydání nahrazovaného daňového potvrzení není platné.',
            );
        }
        $timestamp = $matches[1] . ' ' . $matches[2];
        $date = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $timestamp,
        );
        if ($date === false
            || $date->format('Y-m-d H:i:s') !== $timestamp
        ) {
            throw new \DomainException(
                'Datum vydání nahrazovaného daňového potvrzení není platné.',
            );
        }

        return $timestamp;
    }

    private function databaseTimestamp(): string
    {
        $statement = $this->db->pdo()->query(
            'SELECT DATE_FORMAT(CURRENT_TIMESTAMP, "%Y-%m-%d %H:%i:%s")',
        );
        if ($statement === false) {
            throw new \RuntimeException(
                'Databáze neposkytla datum vydání daňového potvrzení.',
            );
        }
        $value = $statement->fetchColumn();
        if (!is_string($value)) {
            throw new \RuntimeException(
                'Databáze neposkytla datum vydání daňového potvrzení.',
            );
        }

        return self::validatedTimestamp($value, 'issued_at');
    }

    /** @param array<string,mixed> $source */
    private function timestamp(array $source, string $key): string
    {
        $value = $source[$key] ?? null;
        if (!is_string($value)) {
            throw new \DomainException(
                "Pole {$key} daňového snapshotu není platné datum a čas.",
            );
        }

        return self::validatedTimestamp($value, $key);
    }

    /** @param array<string,mixed> $source */
    private function nullableTimestamp(array $source, string $key): ?string
    {
        $value = $source[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new \DomainException(
                "Pole {$key} daňového snapshotu není platné datum a čas.",
            );
        }

        return self::validatedTimestamp($value, $key);
    }

    private static function validatedTimestamp(string $value, string $key): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
        if ($date === false || $date->format('Y-m-d H:i:s') !== $value) {
            throw new \DomainException(
                "Pole {$key} daňového snapshotu není platné datum a čas.",
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $source */
    private function nullableText(array $source, string $key): ?string
    {
        $value = $source[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)
            || trim($value) === ''
            || trim($value) !== $value
        ) {
            throw new \DomainException(
                "Pole {$key} daňového snapshotu není platný text.",
            );
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array{
     *   supplementary_pension:int,
     *   pension_insurance:int,
     *   private_life_insurance:int,
     *   long_term_investment_product:int
     * }
     */
    private function productContributions(array $snapshot): array
    {
        $products = $this->object(
            $snapshot['employer_product_contributions_minor_units'] ?? null,
            'employer_product_contributions_minor_units',
        );

        return [
            'supplementary_pension' =>
                $this->nonNegativeInt($products, 'supplementary_pension'),
            'pension_insurance' =>
                $this->nonNegativeInt($products, 'pension_insurance'),
            'private_life_insurance' =>
                $this->nonNegativeInt($products, 'private_life_insurance'),
            'long_term_investment_product' =>
                $this->nonNegativeInt(
                    $products,
                    'long_term_investment_product',
                ),
        ];
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array{performed:bool,result:?array<string,mixed>}
     */
    private function annualSettlement(array $snapshot): array
    {
        $settlement = $this->object(
            $snapshot['annual_settlement'] ?? null,
            'annual_settlement',
        );
        if (($settlement['performed'] ?? null) !== false
            || ($settlement['result'] ?? null) !== null
        ) {
            throw new \DomainException(
                'Roční zúčtování v daňovém snapshotu není podporované.',
            );
        }

        return ['performed' => false, 'result' => null];
    }

    /** @param array<string,mixed> $source */
    private function nullableNonNegativeInt(
        array $source,
        string $key,
    ): ?int {
        if (($source[$key] ?? null) === null) {
            return null;
        }

        return $this->nonNegativeInt($source, $key);
    }

    private static function correctionReason(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if ($value === ''
            || mb_strlen($value) > 1000
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) === 1
        ) {
            throw new \DomainException(
                'Důvod opravného potvrzení není platný.',
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $value */
    private function fingerprint(
        array $value,
        string $purpose,
        int $supplierId,
    ): string {
        return $this->sensitiveData->keyedFingerprint(
            CanonicalJson::encode($value),
            $purpose,
            $supplierId,
        );
    }

    /**
     * @param array{
     *   tax_year:int,
     *   document_kind:PayrollDocumentKind,
     *   form_number:string,
     *   ministry_form:string,
     *   pattern_number:int,
     *   valid_from:string,
     *   valid_to:string,
     *   official_url:string
     * } $form
     * @return array{
     *   tax_year:int,
     *   document_kind:string,
     *   form_number:string,
     *   ministry_form:string,
     *   pattern_number:int,
     *   valid_from:string,
     *   valid_to:string,
     *   official_url:string
     * }
     */
    private static function formSnapshot(array $form): array
    {
        $snapshot = $form;
        $snapshot['document_kind'] = $form['document_kind']->value;

        return $snapshot;
    }

    private function assertJsonHash(
        string $json,
        string $hash,
        string $context,
    ): void {
        if (!hash_equals($hash, hash('sha256', $json))) {
            throw new \DomainException("Otisk {$context} nesouhlasí.");
        }
    }

    /** @return array<string,mixed> */
    private function decodeObject(string $json, string $context): array
    {
        return $this->object(
            json_decode($json, true, flags: JSON_THROW_ON_ERROR),
            $context,
        );
    }

    /** @param array<string,mixed> $row */
    private function hash(array $row, string $field): string
    {
        $value = $this->text($row, $field);
        if (preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new \DomainException("Pole {$field} není platný SHA-256.");
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private function text(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new \DomainException("Chybí textové pole {$field}.");
        }

        return trim($value);
    }

    /** @param array<string,mixed> $row */
    private function int(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if ((is_int($value) || (is_string($value)
                && preg_match('/^-?\d+$/D', $value) === 1))
        ) {
            return (int) $value;
        }
        throw new \DomainException("Pole {$field} není celé číslo.");
    }

    /** @param array<string,mixed> $row */
    private function positiveInt(array $row, string $field): int
    {
        $value = $this->int($row, $field);
        if ($value <= 0) {
            throw new \DomainException(
                "Pole {$field} není kladné celé číslo.",
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private function nonNegativeInt(array $row, string $field): int
    {
        $value = $this->int($row, $field);
        if ($value < 0) {
            throw new \DomainException(
                "Pole {$field} není nezáporné celé číslo.",
            );
        }

        return $value;
    }

    /** @return array<string,mixed> */
    private function object(mixed $value, string $context): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \DomainException("{$context} není objekt.");
        }

        return $this->associativeRow($value);
    }

    /** @return list<mixed> */
    private function list(mixed $value, string $context): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \DomainException("{$context} není seznam.");
        }

        return $value;
    }

    /** @return list<array<string,mixed>> */
    private function objectList(mixed $value, string $context): array
    {
        $result = [];
        foreach ($this->list($value, $context) as $index => $row) {
            $result[] = $this->object($row, "{$context}.{$index}");
        }

        return $result;
    }

    /** @return array<string,mixed> */
    private function associativeRow(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \UnexpectedValueException(
                'Databáze vrátila neplatný řádek daňového potvrzení.',
            );
        }
        $row = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException(
                    'Objekt daňového potvrzení má neplatný klíč.',
                );
            }
            $row[$key] = $item;
        }

        return $row;
    }

    private function add(int $left, int $right): int
    {
        if ($right > 0 && $left > PHP_INT_MAX - $right) {
            throw new \OverflowException(
                'Roční součet daňového potvrzení přetekl.',
            );
        }

        return $left + $right;
    }
}
