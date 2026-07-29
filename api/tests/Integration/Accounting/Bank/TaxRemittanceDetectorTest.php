<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Service\Accounting\Bank\Detect\TaxRemittanceDetector;
use MyInvoice\Service\Accounting\OperationType;
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
}
