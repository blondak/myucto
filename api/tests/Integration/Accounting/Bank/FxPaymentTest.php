<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Repository\AccountingSupplierSettingsRepository;
use MyInvoice\Repository\FixedExchangeRateRepository;
use PHPUnit\Framework\Attributes\Group;

/**
 * Cizoměnové úhrady faktur/PF z banky s kurzovým rozdílem 563/663 (Fáze B, B6).
 *
 * Saldokonto (311/321) se odúčtuje v CZK hodnotě PŘEDPISU (cizí částka × kurz předpisu
 * ze stopy řádku deníku), banka (221) v CZK hodnotě SKUTEČNÉ úhrady (cizí částka × kurz
 * ČNB dne banky ze seedu exchange_rates), rozdíl → kurzová ztráta 563 MD / kurzový zisk
 * 663 D. Ověřuje: plnou úhradu se ziskem i ztrátou, částečnou úhradu, FX PF (odchozí) a
 * no-op při shodě kurzu úhrady a předpisu (žádný řádek 563/663).
 *
 * Sdílí bankovní fixtury a rollback-per-test z {@see BankPostingTestCase}.
 */
#[Group('integration')]
final class FxPaymentTest extends BankPostingTestCase
{
    private int $eurId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->eurId = $this->ensureEur();
    }

    // (a) — plná úhrada FX faktury s kurzovým ZISKem (banka > předpis).
    public function testIncomingFullPaymentBooksExchangeGain663(): void
    {
        $client = $this->client('EUR Odběratel');
        $inv = $this->fxSaleInvoice('FV-EUR-GAIN', $client, 1000.00, 25.00); // předpis 25 000 CZK
        $this->seedRate('EUR', self::YEAR . '-06-15', 26.00);                 // banka 26 000 CZK

        $tx = $this->fxIncoming($inv, 1000.00);
        $byAcc = $this->linesByAccountCode($this->entryIdForBankTx($tx));

        self::assertEqualsWithDelta(26000.00, $byAcc['221']['debit'], 0.001, '221 MD = skutečná úhrada v CZK (kurz banky).');
        self::assertEqualsWithDelta(25000.00, $byAcc['311']['credit'], 0.001, '311 D = odúčtování pohledávky v kurzu předpisu.');
        self::assertEqualsWithDelta(1000.00, $byAcc['663']['credit'], 0.001, '663 D = kurzový zisk.');
        self::assertArrayNotHasKey('563', $byAcc, 'Zisk nemá kurzovou ztrátu.');
        $this->assertBalanced($this->entryIdForBankTx($tx));
    }

    // (b) — plná úhrada FX faktury s kurzovou ZTRÁTOU (banka < předpis).
    public function testIncomingFullPaymentBooksExchangeLoss563(): void
    {
        $client = $this->client('EUR Odběratel');
        $inv = $this->fxSaleInvoice('FV-EUR-LOSS', $client, 1000.00, 26.00); // předpis 26 000 CZK
        $this->seedRate('EUR', self::YEAR . '-06-15', 25.00);                 // banka 25 000 CZK

        $tx = $this->fxIncoming($inv, 1000.00);
        $byAcc = $this->linesByAccountCode($this->entryIdForBankTx($tx));

        self::assertEqualsWithDelta(25000.00, $byAcc['221']['debit'], 0.001, '221 MD = skutečná úhrada v CZK.');
        self::assertEqualsWithDelta(26000.00, $byAcc['311']['credit'], 0.001, '311 D = odúčtování pohledávky v kurzu předpisu.');
        self::assertEqualsWithDelta(1000.00, $byAcc['563']['debit'], 0.001, '563 MD = kurzová ztráta.');
        self::assertArrayNotHasKey('663', $byAcc, 'Ztráta nemá kurzový zisk.');
        $this->assertBalanced($this->entryIdForBankTx($tx));
    }

    // (c) — částečná úhrada FX faktury: poměrná část salda i kurzového rozdílu.
    public function testIncomingPartialPaymentBooksProportionalDifference(): void
    {
        $client = $this->client('EUR Odběratel');
        $inv = $this->fxSaleInvoice('FV-EUR-PART', $client, 1000.00, 25.00); // celý předpis 25 000 CZK
        $this->seedRate('EUR', self::YEAR . '-06-15', 26.00);

        // Zaplaceno jen 400 EUR: saldo 400×25=10 000, banka 400×26=10 400, zisk 400.
        $tx = $this->fxIncoming($inv, 400.00);
        $byAcc = $this->linesByAccountCode($this->entryIdForBankTx($tx));

        self::assertEqualsWithDelta(10400.00, $byAcc['221']['debit'], 0.001, '221 MD = 400 EUR × 26.');
        self::assertEqualsWithDelta(10000.00, $byAcc['311']['credit'], 0.001, '311 D = 400 EUR × 25 (kurz předpisu).');
        self::assertEqualsWithDelta(400.00, $byAcc['663']['credit'], 0.001, '663 D = poměrný kurzový zisk.');
        $this->assertBalanced($this->entryIdForBankTx($tx));
    }

    // (d) — odchozí úhrada přijaté FX faktury (PF): 321 v kurzu předpisu, banka v kurzu dne.
    public function testOutgoingPurchasePaymentBooksExchangeDifference(): void
    {
        $vendor = $this->client('EUR Dodavatel');
        $pf = $this->fxPurchaseInvoice('PF-EUR', $vendor, 500.00, 25.00); // závazek 12 500 CZK
        $this->seedRate('EUR', self::YEAR . '-06-15', 24.00);             // banka 12 000 CZK

        $tx = $this->fxOutgoing($pf, 500.00);
        $byAcc = $this->linesByAccountCode($this->entryIdForBankTx($tx));

        self::assertEqualsWithDelta(12500.00, $byAcc['321']['debit'], 0.001, '321 MD = odúčtování závazku v kurzu předpisu.');
        self::assertEqualsWithDelta(12000.00, $byAcc['221']['credit'], 0.001, '221 D = skutečná úhrada v CZK.');
        // Zaplaceno v CZK méně, než činil závazek → kurzový zisk.
        self::assertEqualsWithDelta(500.00, $byAcc['663']['credit'], 0.001, '663 D = kurzový zisk (zaplaceno méně CZK).');
        $this->assertBalanced($this->entryIdForBankTx($tx));
    }

    // (e) — kurz úhrady == kurz předpisu → žádný řádek 563/663.
    public function testNoDifferenceWhenBankRateEqualsPredpisRate(): void
    {
        $client = $this->client('EUR Odběratel');
        $inv = $this->fxSaleInvoice('FV-EUR-NOOP', $client, 1000.00, 25.00);
        $this->seedRate('EUR', self::YEAR . '-06-15', 25.00); // shoda kurzů

        $tx = $this->fxIncoming($inv, 1000.00);
        $byAcc = $this->linesByAccountCode($this->entryIdForBankTx($tx));

        self::assertEqualsWithDelta(25000.00, $byAcc['221']['debit'], 0.001);
        self::assertEqualsWithDelta(25000.00, $byAcc['311']['credit'], 0.001);
        self::assertArrayNotHasKey('563', $byAcc, 'Shoda kurzů → žádná kurzová ztráta.');
        self::assertArrayNotHasKey('663', $byAcc, 'Shoda kurzů → žádný kurzový zisk.');
        $this->assertBalanced($this->entryIdForBankTx($tx));
    }

    // (f) — nealokovaný zbytek nad toleranci (1 jednotka měny) = přeplatek/nedoplatek,
    //       ne kurzový rozdíl → allocation_mismatch (skip, ruční zaúčtování), žádný zápis.
    public function testIncomingOverpaymentBeyondToleranceIsNotPosted(): void
    {
        $client = $this->client('EUR Odběratel');
        $inv = $this->fxSaleInvoice('FV-EUR-OVER', $client, 1000.00, 25.00);
        $this->seedRate('EUR', self::YEAR . '-06-15', 26.00);

        // tx 1010 EUR, ale alokováno jen 1000 EUR → zbytek 10 EUR ≫ tolerance → skip.
        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 1010.00, ['match_status' => 'manual', 'currency' => 'EUR']);
        $this->fxInvoicePayment($inv, $tx, 1000.00);

        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('skipped', $res['action'], 'Přeplatek nad toleranci se nezaúčtuje.');
        self::assertSame('allocation_mismatch', $res['reason'] ?? '');
        self::assertSame(0, $this->entryCountForTx($tx), 'Zbytek cizí měny se nesmí zavléct do 563/663.');
    }

    public function testIncomingFxRemainderWithinToleranceUses648(): void
    {
        $client = $this->client('EUR Odběratel EP8');
        $inv = $this->fxSaleInvoice('FV-EUR-EP8-REM', $client, 1000.00, 25.00);
        $this->seedRate('EUR', self::YEAR . '-06-15', 26.00);

        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 1000.50, ['match_status' => 'manual', 'currency' => 'EUR']);
        $this->fxInvoicePayment($inv, $tx, 1000.00);
        $res = $this->service->handleTransaction($tx, $this->userId);

        self::assertSame('posted', $res['action']);
        $byAcc = $this->linesByAccountCode($this->entryIdForBankTx($tx));
        self::assertEqualsWithDelta(26013.00, $byAcc['221']['debit'], 0.001);
        self::assertEqualsWithDelta(25000.00, $byAcc['311']['credit'], 0.001);
        self::assertEqualsWithDelta(1000.00, $byAcc['663']['credit'], 0.001, '563/663 obsahuje jen kurzovou část alokace.');
        self::assertEqualsWithDelta(13.00, $byAcc['648']['credit'], 0.001, '0,50 EUR přeplatku patří na 648.');
        $this->assertBalanced($this->entryIdForBankTx($tx));
    }

    public function testFixedMonthlyRateIsUsedForBankLeg(): void
    {
        $settings = $this->container->get(AccountingSupplierSettingsRepository::class);
        $rates = $this->container->get(FixedExchangeRateRepository::class);
        $rates->upsert($this->supplierId, 'EUR', self::YEAR, 6, 24.00, 'test');
        $settings->setFxRateMode($this->supplierId, 'fixed_monthly');

        $client = $this->client('EUR Odběratel fixed');
        $inv = $this->fxSaleInvoice('FV-EUR-EP8-FIX', $client, 1000.00, 25.00);
        $this->seedRate('EUR', self::YEAR . '-06-15', 26.00);

        $tx = $this->fxIncoming($inv, 1000.00);
        $byAcc = $this->linesByAccountCode($this->entryIdForBankTx($tx));
        self::assertEqualsWithDelta(24000.00, $byAcc['221']['debit'], 0.001, 'Banka respektuje pevný kurz firmy, ne denní ČNB 26.');
        self::assertEqualsWithDelta(1000.00, $byAcc['563']['debit'], 0.001);
        self::assertArrayNotHasKey('663', $byAcc);
    }

    // (g) — fallback matched_invoice_id (bez invoice_payments řádku) zaúčtuje FX korektně.
    public function testIncomingFallbackMatchedInvoiceBooksFx(): void
    {
        $client = $this->client('EUR Odběratel');
        $inv = $this->fxSaleInvoice('FV-EUR-FB', $client, 1000.00, 25.00);
        $this->seedRate('EUR', self::YEAR . '-06-15', 26.00);

        $stmt = $this->statement();
        // Žádný invoice_payments řádek → fallback na matched_invoice_id.
        $tx = $this->transaction($stmt, 1000.00, ['match_status' => 'manual', 'currency' => 'EUR', 'matched_invoice_id' => $inv]);

        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('posted', $res['action'], 'Fallback matched_invoice_id se má zaúčtovat: ' . ($res['reason'] ?? ''));
        $byAcc = $this->linesByAccountCode($this->entryIdForBankTx($tx));
        self::assertEqualsWithDelta(26000.00, $byAcc['221']['debit'], 0.001);
        self::assertEqualsWithDelta(25000.00, $byAcc['311']['credit'], 0.001);
        self::assertEqualsWithDelta(1000.00, $byAcc['663']['credit'], 0.001);
        $this->assertBalanced($this->entryIdForBankTx($tx));
    }

    // (h) — fallback na PAID fakturu bez evidované platby → ověření člověkem (parita s CZK
    //       cestou), NE dvojité odúčtování 311 automatikou.
    public function testIncomingFallbackOnPaidInvoiceRoutesToVerify(): void
    {
        $client = $this->client('EUR Odběratel');
        $inv = $this->fxSaleInvoice('FV-EUR-PAID', $client, 1000.00, 25.00);
        $this->db->pdo()->prepare('UPDATE invoices SET status = "paid" WHERE id = ?')->execute([$inv]);
        $this->seedRate('EUR', self::YEAR . '-06-15', 26.00);

        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 1000.00, ['match_status' => 'manual', 'currency' => 'EUR', 'matched_invoice_id' => $inv]);

        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('suggested', $res['action'], 'Paid faktura bez platby → návrh k ověření, ne auto-zaúčtování.');
        self::assertSame('already_paid_verify', $res['reason'] ?? '');
        self::assertSame(0, $this->entryCountForTx($tx), 'Nesmí vzniknout dvojité odúčtování 311.');
    }

    // (i) — CROSS-CURRENCY: korunová faktura uhrazená přes cizoměnový (EUR) účet. Reálný případ
    //       AVYX (bt 999, FV 2409012): 311 D v NOMINÁLNÍ CZK hodnotě faktury, 221 MD v přepočtu
    //       EUR úhrady kurzem ČNB dne, rozdíl → 563. Dřív fx_not_supported (ručně), teď auto (B6+).
    //       Výsledek musí přesně sednout na ručně zaúčtované hodnoty (221 111 904,96 / 311 114 345
    //       / 563 2 440,04).
    public function testCrossCurrencyIncomingCzkInvoiceBooksExchangeLoss563(): void
    {
        $client = $this->client('AVYX DISTRIBUTION');
        $inv = $this->saleInvoice('FV-AVYX-CC', $client, 114345.00); // korunová FV
        $this->postPredpis('invoice', $inv, '311', '602', 114345.00);
        $this->seedRate('EUR', self::YEAR . '-06-15', 25.315);

        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 4420.50, ['match_status' => 'manual', 'currency' => 'EUR']);
        $this->invoicePayment($inv, $tx, 114345.00); // evidovaná úhrada v měně faktury (CZK)

        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('posted', $res['action'], 'Křížová měna CZK faktury se má zaúčtovat: ' . ($res['reason'] ?? ''));

        $entryId = $this->entryIdForBankTx($tx);
        $byAcc = $this->linesByAccountCode($entryId);
        self::assertEqualsWithDelta(111904.96, $byAcc['221']['debit'], 0.001, '221 MD = 4420,50 EUR × 25,315 (kurz ČNB dne).');
        self::assertEqualsWithDelta(114345.00, $byAcc['311']['credit'], 0.001, '311 D = nominální CZK hodnota faktury.');
        self::assertEqualsWithDelta(2440.04, $byAcc['563']['debit'], 0.001, '563 MD = realizovaná kurzová ztráta (sedí na ruční zápis bt 999).');
        self::assertArrayNotHasKey('663', $byAcc);
        $this->assertBalanced($entryId);

        // §4/12 — cizoměnová stopa patří na bankovní nohu (jediná reálně v EUR); saldokonto je nativně CZK.
        $bank = $this->lineForAccount($entryId, '221');
        self::assertSame('EUR', $bank['currency_code']);
        self::assertEqualsWithDelta(25.315, (float) $bank['fx_rate'], 0.0001);
        self::assertEqualsWithDelta(4420.50, (float) $bank['amount_foreign'], 0.001);
        $saldo = $this->lineForAccount($entryId, '311');
        self::assertNull($saldo['currency_code'], 'Korunový předpis cizoměnovou stopu nemá.');
    }

    // (i2) — křížová měna se ziskem (banka přinesla víc CZK, než činil nominál faktury) → 663.
    public function testCrossCurrencyIncomingCzkInvoiceBooksExchangeGain663(): void
    {
        $client = $this->client('EUR na CZK fakturu');
        $inv = $this->saleInvoice('FV-CC-GAIN', $client, 100000.00);
        $this->postPredpis('invoice', $inv, '311', '602', 100000.00);
        $this->seedRate('EUR', self::YEAR . '-06-15', 25.00);

        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 4100.00, ['match_status' => 'manual', 'currency' => 'EUR']);
        $this->invoicePayment($inv, $tx, 100000.00);

        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('posted', $res['action'], $res['reason'] ?? '');
        $byAcc = $this->linesByAccountCode($this->entryIdForBankTx($tx));
        self::assertEqualsWithDelta(102500.00, $byAcc['221']['debit'], 0.001, '221 MD = 4100 EUR × 25.');
        self::assertEqualsWithDelta(100000.00, $byAcc['311']['credit'], 0.001);
        self::assertEqualsWithDelta(2500.00, $byAcc['663']['credit'], 0.001, '663 D = kurzový zisk.');
        self::assertArrayNotHasKey('563', $byAcc);
        $this->assertBalanced($this->entryIdForBankTx($tx));
    }

    // (i3) — částečná křížová úhrada: saldo se odúčtuje evidovanou CZK částkou platby, banka
    //        poměrnou EUR částkou; rozdíl je kurzový.
    public function testCrossCurrencyPartialCzkInvoiceBooksProportionalDifference(): void
    {
        $client = $this->client('AVYX partial');
        $inv = $this->saleInvoice('FV-CC-PART', $client, 114345.00);
        $this->postPredpis('invoice', $inv, '311', '602', 114345.00);
        $this->seedRate('EUR', self::YEAR . '-06-15', 25.315);

        $stmt = $this->statement();
        // 2000 EUR × 25,315 = 50 630 CZK, evidováno 51 000 CZK ze salda → ztráta 370.
        $tx = $this->transaction($stmt, 2000.00, ['match_status' => 'manual', 'currency' => 'EUR']);
        $this->invoicePayment($inv, $tx, 51000.00);

        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('posted', $res['action'], $res['reason'] ?? '');
        $byAcc = $this->linesByAccountCode($this->entryIdForBankTx($tx));
        self::assertEqualsWithDelta(50630.00, $byAcc['221']['debit'], 0.001, '221 MD = 2000 EUR × 25,315.');
        self::assertEqualsWithDelta(51000.00, $byAcc['311']['credit'], 0.001, '311 D = evidovaná CZK část salda.');
        self::assertEqualsWithDelta(370.00, $byAcc['563']['debit'], 0.001, '563 MD = kurzová ztráta částečné úhrady.');
        $this->assertBalanced($this->entryIdForBankTx($tx));
    }

    // (i4) — fallback bez invoice_payments (jen matched_invoice_id): plná úhrada korunové faktury
    //        cizí měnou → 311 D = zbytek dluhu faktury. Reprodukuje reálnou vazbu AVYX bt 999.
    public function testCrossCurrencyFallbackMatchedInvoiceBooksFullSettlement(): void
    {
        $client = $this->client('AVYX fallback');
        $inv = $this->saleInvoice('FV-CC-FB', $client, 114345.00);
        $this->postPredpis('invoice', $inv, '311', '602', 114345.00);
        $this->seedRate('EUR', self::YEAR . '-06-15', 25.315);

        $stmt = $this->statement();
        // Žádný invoice_payments řádek → fallback na matched_invoice_id (plná úhrada).
        $tx = $this->transaction($stmt, 4420.50, ['match_status' => 'manual', 'currency' => 'EUR', 'matched_invoice_id' => $inv]);

        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('posted', $res['action'], $res['reason'] ?? '');
        $byAcc = $this->linesByAccountCode($this->entryIdForBankTx($tx));
        self::assertEqualsWithDelta(111904.96, $byAcc['221']['debit'], 0.001);
        self::assertEqualsWithDelta(114345.00, $byAcc['311']['credit'], 0.001);
        self::assertEqualsWithDelta(2440.04, $byAcc['563']['debit'], 0.001);
        $this->assertBalanced($this->entryIdForBankTx($tx));
    }

    // (i5) — idempotence + rozpárování: opakovaný běh nevytvoří druhý živý zápis; unpost stornuje.
    public function testCrossCurrencyIsIdempotentAndReversible(): void
    {
        $client = $this->client('AVYX idem');
        $inv = $this->saleInvoice('FV-CC-IDEM', $client, 114345.00);
        $this->postPredpis('invoice', $inv, '311', '602', 114345.00);
        $this->seedRate('EUR', self::YEAR . '-06-15', 25.315);

        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 4420.50, ['match_status' => 'manual', 'currency' => 'EUR']);
        $this->invoicePayment($inv, $tx, 114345.00);

        self::assertSame('posted', $this->service->handleTransaction($tx, $this->userId)['action']);
        self::assertSame('posted', $this->service->handleTransaction($tx, $this->userId)['action']);
        self::assertSame(1, $this->entryCountForTx($tx), 'Idempotence: na pohyb je právě jeden zápis (in-place přepis).');

        $this->service->unpost($this->supplierId, $tx, $this->meta());
        self::assertSame(0, $this->entryIdForBankTx($tx), 'Po rozpárování není živý zápis.');
    }

    // (i6) — nejistá křížová měna (hrubá odchylka kurzu = nejspíš špatně spárovaný doklad) →
    //        blokovaný návrh k ručnímu ověření, NE automatické zaúčtování.
    public function testCrossCurrencyGrossRateMismatchRoutesToReview(): void
    {
        $client = $this->client('AVYX mismatch');
        $inv = $this->saleInvoice('FV-CC-BAD', $client, 114345.00);
        $this->postPredpis('invoice', $inv, '311', '602', 114345.00);
        $this->seedRate('EUR', self::YEAR . '-06-15', 25.315);

        $stmt = $this->statement();
        // 2000 EUR (≈50 630 CZK) proti evidované úhradě 114 345 CZK → odchylka ~56 % → review.
        $tx = $this->transaction($stmt, 2000.00, ['match_status' => 'manual', 'currency' => 'EUR']);
        $this->invoicePayment($inv, $tx, 114345.00);

        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('suggested', $res['action']);
        self::assertSame('cross_currency', $res['reason'] ?? '');
        self::assertSame(0, $this->entryCountForTx($tx), 'Nejistá křížová měna se nezaúčtuje automaticky.');
        $sug = $this->suggestionRow((int) $res['suggestion_id']);
        self::assertSame('blocked', (string) $sug['status'], 'Návrh je blokovaný k ručnímu ověření.');
    }

    // (i7) — dvojí měnová konverze (EUR faktura hrazená USD) → ruční ověření (blokovaný návrh),
    //        ne tichý skip. payment/predpis jsou v různých cizích měnách — auto by dvakrát převáděl.
    public function testDoubleForeignCurrencyRoutesToReview(): void
    {
        $client = $this->client('EUR Odběratel USD');
        $inv = $this->fxSaleInvoice('FV-EUR-USD', $client, 1000.00, 25.00); // EUR faktura
        $this->seedRate('EUR', self::YEAR . '-06-15', 26.00);

        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 1100.00, ['match_status' => 'manual', 'currency' => 'USD', 'matched_invoice_id' => $inv]);

        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('suggested', $res['action']);
        self::assertSame('cross_currency', $res['reason'] ?? '');
        self::assertSame(0, $this->entryCountForTx($tx), 'Dvojí konverze se nezaúčtuje automaticky.');
    }

    // Ruční rozúčtování cizoměnové úhrady korunové faktury (postManual) zůstává funkční jako
    // override i po zavedení automatiky — invariant „221 = CZK ekvivalent výpisu" platí dál.
    public function testManualLinesPostForeignPaymentOfCzkInvoiceStillWorks(): void
    {
        $client = $this->client('AVYX manual');
        $inv = $this->saleInvoice('FV-AVYX-MAN', $client, 114345.00);
        $this->postPredpis('invoice', $inv, '311', '602', 114345.00);
        $this->seedRate('EUR', self::YEAR . '-06-15', 25.315);

        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 4420.50, ['match_status' => 'unmatched', 'currency' => 'EUR']);

        $bankCzk = round(4420.50 * 25.315, 2); // 111 904,96
        $res = $this->service->postManual($this->supplierId, $tx, [
            'lines' => [
                ['account_code' => '221', 'side' => 'debit',  'amount' => $bankCzk],
                ['account_code' => '563', 'side' => 'debit',  'amount' => round(114345.00 - $bankCzk, 2)],
                ['account_code' => '311', 'side' => 'credit', 'amount' => 114345.00],
            ],
        ], $this->meta());

        $byAcc = $this->linesByAccountCode((int) $res['entry_id']);
        self::assertEqualsWithDelta(111904.96, $byAcc['221']['debit'], 0.001);
        self::assertEqualsWithDelta(114345.00, $byAcc['311']['credit'], 0.001);
        self::assertEqualsWithDelta(2440.04, $byAcc['563']['debit'], 0.001);
        $this->assertBalanced((int) $res['entry_id']);
    }

    // Invariant multi-line u cizí měny: 221 se poměřuje s CZK ekvivalentem výpisu,
    // ne s cizoměnovým nominálem (jinak by šlo zaúčtovat 4 420,50 Kč místo 111 904,96 Kč).
    public function testManualLinesForeignBankLegMustMatchCzkEquivalent(): void
    {
        $client = $this->client('AVYX DISTRIBUTION');
        $inv = $this->saleInvoice('FV-AVYX-2', $client, 114345.00);
        $this->postPredpis('invoice', $inv, '311', '602', 114345.00);
        $this->seedRate('EUR', self::YEAR . '-06-15', 25.315);

        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 4420.50, [
            'match_status' => 'manual', 'currency' => 'EUR', 'matched_invoice_id' => $inv,
        ]);

        $this->expectException(\MyInvoice\Service\Accounting\PostingException::class);
        $this->service->postManual($this->supplierId, $tx, [
            'lines' => [
                ['account_code' => '221', 'side' => 'debit',  'amount' => 4420.50], // nominál, ne CZK
                ['account_code' => '311', 'side' => 'credit', 'amount' => 4420.50],
            ],
        ], $this->meta());
    }

    // Blacklist saldokont drží tam, kam H2 míří — na pravidla. Z rozúčtování (které
    // saldokonta smí) nesmí jít pravidlo založit, jinak by šel blacklist obejít.
    public function testManualLinesCannotCreateRule(): void
    {
        $this->seedRate('EUR', self::YEAR . '-06-15', 25.315);
        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 100.00, ['match_status' => 'unmatched', 'currency' => 'EUR']);

        $this->expectException(\MyInvoice\Service\Accounting\PostingException::class);
        $this->service->postManual($this->supplierId, $tx, [
            'lines' => [
                ['account_code' => '221', 'side' => 'debit',  'amount' => 2531.50],
                ['account_code' => '311', 'side' => 'credit', 'amount' => 2531.50],
            ],
            'create_rule' => [
                'direction' => 'incoming', 'variable_symbol' => '2409012',
                'debit_account_code' => '221', 'credit_account_code' => '311',
            ],
        ], $this->meta());
    }

    // Kurz pro UI musí být týž, jakým se pohyb zaúčtuje — jinak by fronta předvyplnila
    // jinou částku, než jakou zápis dostane.
    public function testCzkRateForMatchesPostingRate(): void
    {
        $this->seedRate('EUR', self::YEAR . '-06-15', 25.315);
        self::assertSame(1.0, $this->service->czkRateFor($this->supplierId, 'CZK', self::YEAR . '-06-15'));
        self::assertEqualsWithDelta(
            25.315,
            (float) $this->service->czkRateFor($this->supplierId, 'EUR', self::YEAR . '-06-15'),
            0.0001,
        );
    }

    // ── fixtury ──────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function lineForAccount(int $entryId, string $code): array
    {
        foreach ($this->journal->find($entryId, $this->supplierId)['lines'] as $l) {
            if ($this->accountCode((int) $l['account_id']) === $code) {
                return $l;
            }
        }
        self::fail('Řádek s účtem ' . $code . ' v zápisu není.');
    }

    private function fxIncoming(int $invoiceId, float $foreignAmount): int
    {
        $stmt = $this->statement();
        $tx = $this->transaction($stmt, $foreignAmount, ['match_status' => 'manual', 'currency' => 'EUR']);
        $this->fxInvoicePayment($invoiceId, $tx, $foreignAmount);
        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('posted', $res['action'], 'FX úhrada FV se má zaúčtovat: ' . ($res['reason'] ?? ''));
        return $tx;
    }

    private function fxOutgoing(int $pfId, float $foreignAmount): int
    {
        $stmt = $this->statement();
        $tx = $this->transaction($stmt, -$foreignAmount, ['match_status' => 'manual', 'currency' => 'EUR']);
        $this->paymentMatch($tx, $pfId, $foreignAmount);
        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('posted', $res['action'], 'FX úhrada PF se má zaúčtovat: ' . ($res['reason'] ?? ''));
        return $tx;
    }

    /** Vydaná faktura v EUR + zaúčtovaný předpis 311/602 s FX stopou na saldokontu (guard H1). */
    private function fxSaleInvoice(string $vs, int $clientId, float $foreignTotal, float $rate): int
    {
        $issue = self::YEAR . '-06-10';
        $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, exchange_rate, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 amount_to_pay, paid_total, status, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, ?, 0, ?, 0, ?, ?, 0, "issued", "1", ?)'
        )->execute([
            $this->supplierId, $vs, $clientId, $issue, $issue, $issue, $this->eurId, $rate,
            $foreignTotal, $foreignTotal, $foreignTotal, $this->userId,
        ]);
        $invId = (int) $this->db->pdo()->lastInsertId();

        $czk = round($foreignTotal * $rate, 2);
        $map = $this->accounts->codeToIdMap($this->supplierId);
        $this->journal->insert([
            'supplier_id' => $this->supplierId,
            'period_id'   => $this->periodId,
            'entry_date'  => $issue,
            'document_no' => 'PREDPIS-' . $vs,
            'description' => 'Předpis FV EUR',
            'source_type' => 'invoice',
            'source_id'   => $invId,
            'posted_at'   => date('Y-m-d H:i:s'),
            'posted_by'   => $this->userId,
        ], [
            ['account_id' => $map['311']['id'], 'side' => 'debit', 'amount' => $czk,
             'currency_code' => 'EUR', 'fx_rate' => $rate, 'amount_foreign' => $foreignTotal],
            ['account_id' => $map['602']['id'], 'side' => 'credit', 'amount' => $czk],
        ]);
        return $invId;
    }

    /** Přijatá faktura v EUR + zaúčtovaný předpis 518/321 s FX stopou na saldokontu (guard H1). */
    private function fxPurchaseInvoice(string $number, int $vendorId, float $foreignTotal, float $rate): int
    {
        $issue = self::YEAR . '-06-10';
        $snapshot = json_encode(['company_name' => 'EUR Dodavatel'], JSON_UNESCAPED_UNICODE);
        $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, vendor_snapshot, document_kind, vat_deduction,
                 issue_date, tax_date, due_date, currency_id, exchange_rate, reverse_charge, is_fixed_asset,
                 total_without_vat, total_vat, total_with_vat, status, created_by)
             VALUES (?, ?, ?, ?, "invoice", "full", ?, ?, ?, ?, ?, 0, 0, ?, 0, ?, "booked", ?)'
        )->execute([
            $this->supplierId, $vendorId, $number, $snapshot, $issue, $issue, $issue, $this->eurId, $rate,
            $foreignTotal, $foreignTotal, $this->userId,
        ]);
        $pfId = (int) $this->db->pdo()->lastInsertId();

        $czk = round($foreignTotal * $rate, 2);
        $map = $this->accounts->codeToIdMap($this->supplierId);
        $this->journal->insert([
            'supplier_id' => $this->supplierId,
            'period_id'   => $this->periodId,
            'entry_date'  => $issue,
            'document_no' => 'PREDPIS-' . $number,
            'description' => 'Předpis PF EUR',
            'source_type' => 'purchase_invoice',
            'source_id'   => $pfId,
            'posted_at'   => date('Y-m-d H:i:s'),
            'posted_by'   => $this->userId,
        ], [
            ['account_id' => $map['518']['id'], 'side' => 'debit', 'amount' => $czk],
            ['account_id' => $map['321']['id'], 'side' => 'credit', 'amount' => $czk,
             'currency_code' => 'EUR', 'fx_rate' => $rate, 'amount_foreign' => $foreignTotal],
        ]);
        return $pfId;
    }

    private function fxInvoicePayment(int $invoiceId, int $txId, float $foreignAmount): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, source, bank_transaction_id)
             VALUES (?, ?, ?, ?, "EUR", "bank", ?)'
        )->execute([$this->supplierId, $invoiceId, self::YEAR . '-06-15', $foreignAmount, $txId]);
    }

    private function seedRate(string $currency, string $date, float $rate): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO exchange_rates (rate_date, currency_code, rate) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE rate = VALUES(rate)'
        )->execute([$date, $currency, $rate]);
    }

    private function ensureEur(): int
    {
        $id = (int) ($this->db->pdo()->query("SELECT id FROM currencies WHERE code='EUR' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        return $id !== 0 ? $id : $this->currencyRow($this->supplierId, 'EUR');
    }

    private function entryIdForBankTx(int $txId): int
    {
        return (int) $this->db->pdo()->query(
            "SELECT id FROM journal_entries WHERE supplier_id={$this->supplierId}
              AND source_type='bank' AND source_id={$txId} AND reversed_by IS NULL LIMIT 1"
        )->fetchColumn();
    }

    private function assertBalanced(int $entryId): void
    {
        $entry = $this->journal->find($entryId, $this->supplierId);
        $debit = 0;
        $credit = 0;
        foreach ($entry['lines'] as $l) {
            $cents = (int) round((float) $l['amount'] * 100);
            $l['side'] === 'debit' ? $debit += $cents : $credit += $cents;
        }
        self::assertSame($debit, $credit, 'Σ MD == Σ D (v haléřích).');
    }
}
