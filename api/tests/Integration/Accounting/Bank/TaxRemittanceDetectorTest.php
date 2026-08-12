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
        self::assertSame('343.900', $exact->debitAccountCode, 'Úhrada DPH jde proti zúčtovací analytice 343.900.');
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

    /**
     * Daň v režimu OSS má vlastní analytiku (345.100, migrace 1295), ale úhrada se do
     * migrace 1301 účtovala jako obyčejná platba DPH — snížila 343 a 345.100 nechala
     * viset. Platí se navíc v EURECH, takže ji korunová podmínka detektoru vyřadila
     * dřív, než se dala poznat, a končila jako neurčený odvod.
     *
     * Účet 34534-177653621/0710 identifikuje příjemce sám: referenční číslo platby má
     * tvar „CZ/CZ<DIČ>/Qn.RRRR", tedy není číselný VS. Musí to platit v národním
     * i v nulami vycpaném GPC zápisu.
     */
    public function testOssRemittanceInEurosPostsToItsOwnAnalyticAccount(): void
    {
        foreach (['34534-177653621', '0345340177653621'] as $account) {
            $detected = $this->detector->detect($this->supplierId, $this->tx(
                currency: 'EUR',
                account: $account,
                vs: 'CZ/CZ12345678/Q3.2026',
            ));

            self::assertNotNull($detected, $account);
            self::assertSame(OperationType::REMITTANCE_OSS, $detected->operationType, $account);
            self::assertSame('345.100', $detected->debitAccountCode, $account);
            self::assertSame('221', $detected->creditAccountCode, $account);
            self::assertSame(0.90, $detected->confidence, $account);
            self::assertTrue($detected->autoAllowed, $account);
            self::assertNull($detected->note, $account);
        }
    }

    /**
     * Výjimka pro cizí měnu se váže na PŘÍJEMCE, ne na měnu: eurová platba na běžný účet
     * finančního úřadu odvod není a nesmí se z ní stát ani „jiný odvod" s ruční kontrolou.
     */
    public function testForeignCurrencyStaysRejectedOnNonOssTaxOfficeAccount(): void
    {
        self::assertNull($this->detector->detect(
            $this->supplierId,
            $this->tx(currency: 'EUR', account: '705-77628031'),
        ));
    }

    /** Korunová platba na OSS účet se nesmí stát platbou DPH jen proto, že je v CZK. */
    public function testCrownPaymentToOssAccountIsStillClassifiedAsOss(): void
    {
        $detected = $this->detector->detect($this->supplierId, $this->tx(
            account: '34534-177653621',
            vs: '12345678',
        ));

        self::assertNotNull($detected);
        self::assertSame(OperationType::REMITTANCE_OSS, $detected->operationType);
        self::assertSame('345.100', $detected->debitAccountCode);
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

    /**
     * Odvod na ČSSZ, jehož VS firma nikde evidovaný nemá (právnická osoba s vypnutými
     * Mzdami nemá kam VS zaměstnavatele uložit), zůstával navěky na jistotě 0,70 — a to
     * i po dvaadvaceti měsících, kdy tentýž odvod na tentýž účet se stejným VS pokaždé
     * ručně schválil týž člověk. Předčíslí 21012 říká DRUH odvodu, VS by řekl ČÍ je;
     * chybějící ověření VS proto nahradí opakované lidské potvrzení téže platby.
     *
     * Práh 0,90 se nesnižuje — přibývá druhý zdroj identifikace, a jen ten, který stojí
     * na lidských rozhodnutích (automaticky zaúčtované návrhy se nepočítají, jinak by si
     * automatika vyráběla důkaz sama pro sebe).
     */
    public function testRepeatedHumanConfirmationsReplaceUnverifiedVariableSymbol(): void
    {
        $this->db->pdo()->prepare(
            "UPDATE supplier SET taxpayer_type = 'po', payroll_enabled = 0,
                    cssz_vsdp = NULL, health_insurance_number = NULL WHERE id = ?"
        )->execute([$this->supplierId]);
        $account = '0210120007928311';
        $vs = '4442070407';

        $unknown = $this->detector->detect($this->supplierId, $this->tx(account: $account, vs: $vs));
        self::assertNotNull($unknown);
        self::assertSame(OperationType::REMITTANCE_SOCIAL_EMPLOYER, $unknown->operationType);
        self::assertSame(0.70, $unknown->confidence, 'Bez ověřeného VS je předčíslí samo jen střední jistota.');

        $statementId = $this->statement();
        foreach (['-05-06', '-04-06', '-03-06'] as $i => $day) {
            $txId = $this->transaction($statementId, -1436.00, [
                'posted_at' => self::YEAR . $day,
                'variable_symbol' => $vs,
                'counterparty_account' => $account,
                'counterparty_bank' => '0710',
            ]);
            $this->approvedRemittance($txId, OperationType::REMITTANCE_SOCIAL_EMPLOYER, 1436.00);
            $confirmed = $this->detector->detect($this->supplierId, $this->tx(account: $account, vs: $vs));
            self::assertNotNull($confirmed);
            self::assertSame(
                $i === 2 ? 0.90 : 0.70,
                $confirmed->confidence,
                'Jistota smí vyskočit až po třetím potvrzení, ne dřív.',
            );
        }

        // Jiný VS na témž účtu důkaz nedědí — potvrzení se váže na konkrétní platbu.
        $other = $this->detector->detect($this->supplierId, $this->tx(account: $account, vs: '9999999999'));
        self::assertNotNull($other);
        self::assertSame(0.70, $other->confidence);
    }

    /** Odvod, který kdysi ručně potvrdil člověk — důkaz pro opakování téže platby. */
    private function approvedRemittance(int $txId, string $operationType, float $amount): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO bank_posting_suggestions
                (supplier_id, bank_transaction_id, source, debit_account_code, credit_account_code,
                 amount, status, confidence, detector, operation_type, reviewed_by, reviewed_at)
             VALUES (?, ?, 'detector', '336', '221', ?, 'approved', 0.70, 'tax_remittance', ?, ?, NOW())"
        )->execute([$this->supplierId, $txId, $amount, $operationType, $this->userId]);
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

    /**
     * Opačný pól k {@see testLegalPersonPersonalIdentifierIsNotEmployerFallback()}: dokud
     * mzdový modul běží, je legacy pole na firmě zastaralá kopie a ignoruje se. S vypnutými
     * Mzdami ale žádný kanonický záznam neexistuje — pole v Nastavení firmy je jediná
     * evidence VS zaměstnavatele a odvod se bez něj nedá poznat (skončil by jako neurčený).
     */
    public function testDisabledPayrollFallsBackToCompanySettingsIdentifiers(): void
    {
        $this->db->pdo()->prepare(
            'DELETE FROM payroll_employer_settings WHERE supplier_id = ?'
        )->execute([$this->supplierId]);
        $this->db->pdo()->prepare(
            'DELETE FROM payroll_offices WHERE supplier_id = ?'
        )->execute([$this->supplierId]);
        $this->db->pdo()->prepare(
            "UPDATE supplier
                SET payroll_enabled = 0,
                    taxpayer_type = 'po',
                    cssz_vsdp = '87654321',
                    health_insurance_number = '555666777'
              WHERE id = ?"
        )->execute([$this->supplierId]);

        // Předčíslí 21012 sráží platbu na sociální odvod samo o sobě, takže druh operace
        // vyjde i bez znalosti VS — jenže jen s jistotou 0,70. Plná jistota (a s ní
        // automatické zaúčtování) vzniká teprve tím, že se VS pozná jako VS TÉTO firmy.
        $social = $this->detector->detect(
            $this->supplierId,
            $this->tx(account: '21012-7928311', vs: '87654321'),
        );
        self::assertNotNull($social);
        self::assertSame(OperationType::REMITTANCE_SOCIAL_EMPLOYER, $social->operationType);
        self::assertSame(0.90, $social->confidence);
        self::assertTrue($social->autoAllowed);

        $health = $this->detector->detect(
            $this->supplierId,
            $this->tx(account: '1111006311', vs: '555666777'),
        );
        self::assertNotNull($health);
        self::assertSame(OperationType::REMITTANCE_HEALTH_EMPLOYER, $health->operationType);
        self::assertSame(0.90, $health->confidence);
    }

    public function testLegalPersonPersonalIdentifierIsNotEmployerFallback(): void
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
                SET payroll_enabled = 1,
                    taxpayer_type = 'po',
                    cssz_vsdp = '87654321',
                    health_insurance_number = '555666777'
              WHERE id = ?"
        )->execute([$this->supplierId]);

        $resolver = $this->container->get(PayrollPaymentIdentifierResolver::class);
        $social = $resolver->matchEmployerRemittance(
            $this->supplierId,
            '87654321',
            self::YEAR . '-06-15',
            '21012-7928311',
            '0710',
        );
        $health = $resolver->matchEmployerRemittance(
            $this->supplierId,
            '555666777',
            self::YEAR . '-06-15',
            '1111006311',
            '0710',
        );

        self::assertNull(
            $social,
            'Osobní identifikátor z obecných údajů firmy nesmí být zdrojem mzdového párování.',
        );
        self::assertNull($health);
        self::assertNull($resolver->defaultForOperation(
            $this->supplierId,
            OperationType::REMITTANCE_SOCIAL_EMPLOYER,
        ));
        self::assertNull($resolver->defaultForOperation(
            $this->supplierId,
            OperationType::REMITTANCE_HEALTH_EMPLOYER,
            self::YEAR . '-06-15',
        ));
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
