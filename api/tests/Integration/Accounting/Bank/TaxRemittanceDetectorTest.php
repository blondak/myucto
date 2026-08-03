<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Repository\Payroll\PayrollInstitutionAccountRepository;
use MyInvoice\Service\Accounting\Bank\Detect\TaxRemittanceDetector;
use MyInvoice\Service\Accounting\OperationType;
use MyInvoice\Service\Payroll\PayrollInstitutionAccountValidator;
use MyInvoice\Service\Payroll\PayrollPaymentIdentifierResolver;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class TaxRemittanceDetectorTest extends BankPostingTestCase
{
    private TaxRemittanceDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = $this->container->get(TaxRemittanceDetector::class);
        $this->db->pdo()->prepare(
            "UPDATE supplier SET dic = 'CZ12345678', cssz_vsdp = '87654321',
                    health_insurance_number = '555666777', taxpayer_type = 'fo' WHERE id = ?"
        )->execute([$this->supplierId]);
    }

    public function testRejectsIncomingOtherBankAndForeignCurrency(): void
    {
        self::assertNull($this->detector->detect($this->supplierId, $this->tx(amount: 1000)));
        self::assertNull($this->detector->detect($this->supplierId, $this->tx(bank: '0800')));
        self::assertNull($this->detector->detect($this->supplierId, $this->tx(currency: 'EUR')));
    }

    public function testPrefixAndVariableSymbolClassifyVat(): void
    {
        $exact = $this->detector->detect($this->supplierId, $this->tx(vs: '12345678'));
        self::assertNotNull($exact);
        self::assertSame(OperationType::REMITTANCE_VAT, $exact->operationType);
        self::assertSame('343', $exact->debitAccountCode);
        self::assertSame(0.90, $exact->confidence);

        $prefixOnly = $this->detector->detect($this->supplierId, $this->tx(vs: '999999'));
        self::assertNotNull($prefixOnly);
        self::assertSame(OperationType::REMITTANCE_VAT, $prefixOnly->operationType);
        self::assertSame(0.70, $prefixOnly->confidence);

        $foreignIdentifier = $this->detector->detect($this->supplierId, $this->tx(vs: '87654321'));
        self::assertNotNull($foreignIdentifier);
        self::assertSame(OperationType::REMITTANCE_VAT, $foreignIdentifier->operationType);
        self::assertSame(0.70, $foreignIdentifier->confidence, 'VS ČSSZ nesmí zvýšit jistotu mapy DPH.');
    }

    public function testUnknownPaymentFallsBackToManualSuggestion(): void
    {
        $detected = $this->detector->detect($this->supplierId, $this->tx(account: '77628031', vs: '999999'));
        self::assertNotNull($detected);
        self::assertSame(OperationType::REMITTANCE_OTHER, $detected->operationType);
        self::assertSame(0.40, $detected->confidence);
        self::assertSame('remittance_unclassified', $detected->note);
        self::assertFalse($detected->autoAllowed);
    }

    /**
     * Zdravotní pojišťovna nemá předčíslí — identifikuje ji celé číslo účtu, které
     * musí dát plnou jistotu v obou zápisech (národním i nulami vycpaném GPC) a i
     * tehdy, když banka do VS pošle DIČ místo čísla pojištěnce. Bez toho zůstala
     * jistota na 0,70 a policy odvod pojistného nikdy nepustila na auto.
     */
    public function testHealthInsurerAccountGivesFullConfidenceRegardlessOfVariableSymbol(): void
    {
        $accounts = [
            '1111006311', '0000001111006311',   // VZP, národní i GPC zápis
            '2010201091',                       // VoZP
            '2050203761',                       // ČPZP
            '2070101041',                       // OZP
            '2092101181',                       // ZPŠ
            '2110102031', '2115106031',         // ZP MV ČR (OSVČ i zaměstnavatel)
            '2130203761',                       // RBP
        ];
        foreach ($accounts as $account) {
            foreach (['555666777', '12345678'] as $vs) {
                $detected = $this->detector->detect($this->supplierId, $this->tx(account: $account, vs: $vs));
                self::assertNotNull($detected, "účet {$account}, VS {$vs}");
                self::assertSame(OperationType::REMITTANCE_HEALTH, $detected->operationType, "účet {$account}, VS {$vs}");
                self::assertSame('336', $detected->debitAccountCode);
                self::assertSame(0.90, $detected->confidence, "účet {$account}, VS {$vs}");
                self::assertTrue($detected->autoAllowed);
            }
        }
    }

    /** ČSSZ pojistné: předčíslí 21012 + VS z cssz_vsdp → plná jistota v obou zápisech. */
    public function testSocialInsurancePrefixGivesFullConfidence(): void
    {
        foreach (['21012-7928311', '0210120007928311'] as $account) {
            $detected = $this->detector->detect($this->supplierId, $this->tx(account: $account, vs: '87654321'));
            self::assertNotNull($detected, $account);
            self::assertSame(OperationType::REMITTANCE_SOCIAL, $detected->operationType, $account);
            self::assertSame('336', $detected->debitAccountCode);
            self::assertSame(0.90, $detected->confidence, $account);
        }
    }

    public function testPhysicalPersonEmployerUsesPayrollOfficeSocialVariableSymbol(): void
    {
        $this->configurePayrollOffice('1234509876');

        $employer = $this->detector->detect(
            $this->supplierId,
            $this->tx(account: '21012-7928311', vs: '1234509876'),
        );
        self::assertNotNull($employer);
        self::assertSame(OperationType::REMITTANCE_SOCIAL_EMPLOYER, $employer->operationType);
        self::assertSame(0.90, $employer->confidence);

        $personal = $this->detector->detect(
            $this->supplierId,
            $this->tx(account: '21012-7928311', vs: '87654321'),
        );
        self::assertNotNull($personal);
        self::assertSame(OperationType::REMITTANCE_SOCIAL, $personal->operationType);
    }

    public function testPhysicalPersonEmployerUsesEffectiveInsurerPayerNumber(): void
    {
        $this->configurePayrollOffice('1234509876');
        $repository = $this->container->get(PayrollInstitutionAccountRepository::class);
        $validator = $this->container->get(PayrollInstitutionAccountValidator::class);
        $repository->create($this->supplierId, $validator->validateCreate([
            'institution_type' => 'health_insurer',
            'institution_code' => 'SYNTH-EMPLOYER-213',
            'institution_name' => 'Syntetická zaměstnanecká pojišťovna',
            'bank_account' => '1000000005/0710',
            'currency_code' => 'CZK',
            'variable_symbol' => '9876543210',
            'specific_symbol' => null,
            'constant_symbol' => null,
            'valid_from' => self::YEAR . '-01-01',
            'valid_to' => self::YEAR . '-12-31',
            'source_kind' => 'official_document',
            'source_reference' => 'SYNTHETIC-EMPLOYER-HEALTH-001',
            'verified_on' => date('Y-m-d'),
        ]), $this->userId);

        $employer = $this->detector->detect(
            $this->supplierId,
            $this->tx(account: '1000000005', vs: '9876543210'),
        );
        self::assertNotNull($employer);
        self::assertSame(OperationType::REMITTANCE_HEALTH_EMPLOYER, $employer->operationType);
        self::assertSame(0.90, $employer->confidence);

        $personal = $this->detector->detect(
            $this->supplierId,
            $this->tx(account: '77628031', vs: '555666777'),
        );
        self::assertNotNull($personal);
        self::assertSame(OperationType::REMITTANCE_HEALTH, $personal->operationType);
    }

    public function testPersonalHealthVariableSymbolIsNotOverriddenBySharedEmployerAccount(): void
    {
        $this->configurePayrollOffice('1234509876');
        $repository = $this->container->get(PayrollInstitutionAccountRepository::class);
        $validator = $this->container->get(PayrollInstitutionAccountValidator::class);
        $repository->create($this->supplierId, $validator->validateCreate([
            'institution_type' => 'health_insurer',
            'institution_code' => 'SYNTH-SHARED-111',
            'institution_name' => 'Syntetická společná zdravotní pojišťovna',
            'bank_account' => '1000000005/0710',
            'currency_code' => 'CZK',
            'variable_symbol' => '9876543210',
            'specific_symbol' => null,
            'constant_symbol' => null,
            'valid_from' => self::YEAR . '-01-01',
            'valid_to' => self::YEAR . '-12-31',
            'source_kind' => 'official_document',
            'source_reference' => 'SYNTHETIC-SHARED-HEALTH-001',
            'verified_on' => date('Y-m-d'),
        ]), $this->userId);

        $personal = $this->detector->detect(
            $this->supplierId,
            $this->tx(account: '1000000005', vs: '555666777'),
        );

        self::assertNotNull($personal);
        self::assertSame(OperationType::REMITTANCE_HEALTH, $personal->operationType);
    }

    public function testDisabledPayrollDoesNotExposeLegacyEmployerIdentifier(): void
    {
        $this->db->pdo()->prepare(
            "UPDATE supplier
                SET payroll_enabled = 0, taxpayer_type = 'po', cssz_vsdp = '87654321'
              WHERE id = ?"
        )->execute([$this->supplierId]);

        $resolved = $this->container->get(PayrollPaymentIdentifierResolver::class)
            ->defaultForOperation(
                $this->supplierId,
                OperationType::REMITTANCE_SOCIAL_EMPLOYER,
            );

        self::assertNull($resolved);
    }

    public function testCanonicalSocialIdentifierBlocksStaleLegacyDefault(): void
    {
        $this->configurePayrollOffice('1234509876');
        $this->db->pdo()->prepare(
            "UPDATE supplier
                SET taxpayer_type = 'po', cssz_vsdp = '87654321'
              WHERE id = ?"
        )->execute([$this->supplierId]);
        $this->db->pdo()->prepare(
            'UPDATE payroll_offices
                SET is_active = 0
              WHERE supplier_id = ?
                AND social_security_variable_symbol = ?'
        )->execute([$this->supplierId, '1234509876']);

        $resolved = $this->container->get(PayrollPaymentIdentifierResolver::class)
            ->defaultForOperation(
                $this->supplierId,
                OperationType::REMITTANCE_SOCIAL_EMPLOYER,
            );

        self::assertNull(
            $resolved,
            'Neaktivní kanonický záznam nesmí oživit starý osobní VS právnické osoby.',
        );
    }

    public function testInactivePayrollOfficeIdentifierDoesNotMatchLaterPayment(): void
    {
        $this->configurePayrollOffice('1234509876');
        $this->db->pdo()->prepare(
            'UPDATE payroll_offices
                SET is_active = 0
              WHERE supplier_id = ?
                AND social_security_variable_symbol = ?'
        )->execute([$this->supplierId, '1234509876']);

        $detected = $this->detector->detect(
            $this->supplierId,
            $this->tx(account: '21012-7928311', vs: '1234509876'),
        );

        self::assertNotNull($detected);
        self::assertNotSame(
            OperationType::REMITTANCE_SOCIAL_EMPLOYER,
            $detected->operationType,
            'Neaktivní účtárna nesmí klasifikovat pozdější platbu zaměstnavatele.',
        );
    }

    public function testExpiredInstitutionIdentifierDoesNotMatchLaterPayment(): void
    {
        $this->configurePayrollOffice('1234509876');
        $repository = $this->container->get(PayrollInstitutionAccountRepository::class);
        $validator = $this->container->get(PayrollInstitutionAccountValidator::class);
        $repository->create($this->supplierId, $validator->validateCreate([
            'institution_type' => 'health_insurer',
            'institution_code' => 'SYNTH-EXPIRED-111',
            'institution_name' => 'Syntetická historická pojišťovna',
            'bank_account' => '1000000005/0100',
            'currency_code' => 'CZK',
            'variable_symbol' => '444555666',
            'specific_symbol' => null,
            'constant_symbol' => null,
            'valid_from' => '2098-01-01',
            'valid_to' => '2098-12-31',
            'source_kind' => 'official_document',
            'source_reference' => 'SYNTHETIC-EXPIRED-HEALTH-001',
            'verified_on' => date('Y-m-d'),
        ]), $this->userId);

        $resolved = $this->container->get(PayrollPaymentIdentifierResolver::class)
            ->matchEmployerRemittance(
                $this->supplierId,
                '444555666',
                self::YEAR . '-06-15',
                '1000000005',
                '0100',
            );

        self::assertNull($resolved);
    }

    public function testIdentifierSharedByTwoInstitutionTypesRequiresManualReview(): void
    {
        $this->configurePayrollOffice('1234509876');
        $repository = $this->container->get(PayrollInstitutionAccountRepository::class);
        $validator = $this->container->get(PayrollInstitutionAccountValidator::class);
        $repository->create($this->supplierId, $validator->validateCreate([
            'institution_type' => 'health_insurer',
            'institution_code' => 'SYNTH-AMBIGUOUS-111',
            'institution_name' => 'Syntetická nejednoznačná pojišťovna',
            'bank_account' => '1000000005/0100',
            'currency_code' => 'CZK',
            'variable_symbol' => '1234509876',
            'specific_symbol' => null,
            'constant_symbol' => null,
            'valid_from' => self::YEAR . '-01-01',
            'valid_to' => self::YEAR . '-12-31',
            'source_kind' => 'official_document',
            'source_reference' => 'SYNTHETIC-AMBIGUOUS-HEALTH-001',
            'verified_on' => date('Y-m-d'),
        ]), $this->userId);

        $ambiguous = $this->detector->detect(
            $this->supplierId,
            $this->tx(account: '21012-7928311', vs: '1234509876'),
        );

        self::assertNotNull($ambiguous);
        self::assertSame(OperationType::REMITTANCE_OTHER, $ambiguous->operationType);
        self::assertSame(0.40, $ambiguous->confidence);
        self::assertSame('remittance_unclassified', $ambiguous->note);
        self::assertFalse($ambiguous->autoAllowed);
    }

    public function testEvidenceFromDifferentInstitutionAccountsIsNotCombined(): void
    {
        $this->configurePayrollOffice('1234509876');
        $repository = $this->container->get(PayrollInstitutionAccountRepository::class);
        $validator = $this->container->get(PayrollInstitutionAccountValidator::class);
        foreach ([
            [
                'institution_code' => 'SYNTH-ACCOUNT-MATCH',
                'institution_name' => 'Syntetická pojišťovna podle účtu',
                'bank_account' => '1000000005/0100',
                'variable_symbol' => null,
            ],
            [
                'institution_code' => 'SYNTH-VS-MATCH',
                'institution_name' => 'Syntetická pojišťovna podle VS',
                'bank_account' => '1111006311/0710',
                'variable_symbol' => '1234509876',
            ],
        ] as $index => $account) {
            $repository->create($this->supplierId, $validator->validateCreate([
                'institution_type' => 'health_insurer',
                'institution_code' => $account['institution_code'],
                'institution_name' => $account['institution_name'],
                'bank_account' => $account['bank_account'],
                'currency_code' => 'CZK',
                'variable_symbol' => $account['variable_symbol'],
                'specific_symbol' => null,
                'constant_symbol' => null,
                'valid_from' => self::YEAR . '-01-01',
                'valid_to' => self::YEAR . '-12-31',
                'source_kind' => 'official_document',
                'source_reference' => "SYNTHETIC-SPLIT-EVIDENCE-{$index}",
                'verified_on' => date('Y-m-d'),
            ]), $this->userId);
        }

        $resolved = $this->container->get(PayrollPaymentIdentifierResolver::class)
            ->matchEmployerRemittance(
                $this->supplierId,
                '1234509876',
                self::YEAR . '-06-15',
                '1000000005',
                '0100',
            );

        self::assertNotNull($resolved);
        self::assertSame(OperationType::REMITTANCE_OTHER, $resolved['operation_type']);
        self::assertTrue($resolved['ambiguous']);
    }

    public function testSocialIdentifierSentToHealthAccountRequiresManualReview(): void
    {
        $this->configurePayrollOffice('1234509876');
        $repository = $this->container->get(PayrollInstitutionAccountRepository::class);
        $validator = $this->container->get(PayrollInstitutionAccountValidator::class);
        $repository->create($this->supplierId, $validator->validateCreate([
            'institution_type' => 'health_insurer',
            'institution_code' => 'SYNTH-MISMATCH-111',
            'institution_name' => 'Syntetická pojišťovna s kontrolou VS',
            'bank_account' => '1111006311/0710',
            'currency_code' => 'CZK',
            'variable_symbol' => '9876543210',
            'specific_symbol' => null,
            'constant_symbol' => null,
            'valid_from' => self::YEAR . '-01-01',
            'valid_to' => self::YEAR . '-12-31',
            'source_kind' => 'official_document',
            'source_reference' => 'SYNTHETIC-MISMATCH-HEALTH-001',
            'verified_on' => date('Y-m-d'),
        ]), $this->userId);

        $mismatch = $this->detector->detect(
            $this->supplierId,
            $this->tx(account: '1111006311', vs: '1234509876'),
        );

        self::assertNotNull($mismatch);
        self::assertSame(OperationType::REMITTANCE_OTHER, $mismatch->operationType);
        self::assertSame(0.40, $mismatch->confidence);
        self::assertSame('remittance_unclassified', $mismatch->note);
        self::assertFalse($mismatch->autoAllowed);
    }

    public function testLegalPersonLegacyEmployerIdentifierIsManualMigrationFallback(): void
    {
        $this->db->pdo()->prepare(
            'DELETE FROM payroll_employer_settings WHERE supplier_id = ?'
        )->execute([$this->supplierId]);
        $this->db->pdo()->prepare(
            'DELETE FROM payroll_offices WHERE supplier_id = ?'
        )->execute([$this->supplierId]);
        $this->db->pdo()->prepare(
            'DELETE FROM payroll_institutions WHERE supplier_id = ?'
        )->execute([$this->supplierId]);
        $this->db->pdo()->prepare(
            "UPDATE supplier
                SET payroll_enabled = 1, taxpayer_type = 'po', cssz_vsdp = '87654321'
              WHERE id = ?"
        )->execute([$this->supplierId]);

        $legacy = $this->detector->detect(
            $this->supplierId,
            $this->tx(account: '21012-7928311', vs: '87654321'),
        );

        self::assertNotNull($legacy);
        self::assertSame(OperationType::REMITTANCE_SOCIAL_EMPLOYER, $legacy->operationType);
        self::assertSame(0.70, $legacy->confidence);
        self::assertSame('remittance_unclassified', $legacy->note);
        self::assertFalse($legacy->autoAllowed);
    }

    public function testScheduleHasPriorityAndAbsoluteHundredCrownTolerance(): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO tax_advance_schedules
                (supplier_id, taxpayer_type, advance_kind, period_year, seq_no, amount, due_date, variable_symbol)
             VALUES (?, "fo", "social", ?, 91, 1000, ?, "87654321")'
        )->execute([$this->supplierId, self::YEAR, self::YEAR . '-06-15']);

        $match = $this->detector->detect($this->supplierId, $this->tx(account: '77628031', vs: '87654321', amount: -1100));
        self::assertNotNull($match);
        self::assertSame('schedule', $match->source);
        self::assertSame(0.95, $match->confidence);
        self::assertNotNull($match->scheduleId);

        $different = $this->detector->detect($this->supplierId, $this->tx(account: '77628031', vs: '87654321', amount: -1100.01));
        self::assertNotNull($different);
        self::assertSame(0.70, $different->confidence);
        self::assertSame('schedule_amount_differs', $different->note);
        self::assertFalse($different->autoAllowed);
    }

    /** @return array<string,mixed> */
    private function tx(
        float $amount = -1000,
        string $bank = '0710',
        string $currency = 'CZK',
        string $account = '705-77628031',
        string $vs = '12345678',
    ): array {
        return [
            'id' => 987654,
            'amount' => $amount,
            'counterparty_bank' => $bank,
            'counterparty_account' => $account,
            'currency' => $currency,
            'variable_symbol' => $vs,
            'posted_at' => self::YEAR . '-06-15',
        ];
    }

    private function configurePayrollOffice(string $socialVariableSymbol): void
    {
        $this->db->pdo()->prepare(
            'UPDATE supplier
                SET payroll_enabled = 1, taxpayer_type = "fo"
              WHERE id = ?'
        )->execute([$this->supplierId]);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_offices
                (supplier_id, code, name, social_security_variable_symbol)
             VALUES (?, "SYNTH", "Syntetická mzdová účtárna", ?)
             ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                social_security_variable_symbol = VALUES(social_security_variable_symbol),
                is_active = 1'
        )->execute([$this->supplierId, $socialVariableSymbol]);
        $officeId = (int) $this->db->pdo()->lastInsertId();
        if ($officeId === 0) {
            $select = $this->db->pdo()->prepare(
                'SELECT id FROM payroll_offices WHERE supplier_id = ? AND code = "SYNTH"'
            );
            $select->execute([$this->supplierId]);
            $officeId = (int) $select->fetchColumn();
        }
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employer_settings (supplier_id, default_office_id)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE default_office_id = VALUES(default_office_id)'
        )->execute([$this->supplierId, $officeId]);
    }
}
