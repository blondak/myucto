<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Action\Bank\CreditCardAction;
use MyInvoice\Repository\CreditCardAccountRepository;
use MyInvoice\Service\Accounting\Card\CardClearingAccounts;
use MyInvoice\Service\Accounting\Card\CardClearingRegime;
use MyInvoice\Service\Accounting\Card\CardClearingWriteOffService;
use MyInvoice\Service\Accounting\CreditCard\CreditCardAccounts;
use MyInvoice\Service\Accounting\CreditCard\CreditCardPostingService;
use MyInvoice\Service\Accounting\CreditCard\CreditCardSettingsService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Bank\Card\CardPaymentOverview;
use MyInvoice\Service\Bank\CreditCard\CreditCardOverview;
use PHPUnit\Framework\Attributes\Group;

/**
 * Nákupy kreditní kartou: režim mezičlenu (378.x/231.x, vypořádání 321/378.x, uzavření bez
 * dokladu daňově, nedaňově a k tíži držitele na nastavené účty), režim bez mezičlenu,
 * dohnání čekajících pohybů, počáteční dluh z prvního výpisu, souhrn „co zbývá dořešit",
 * izolace firem a oprávnění.
 *
 * Kreditní účet je karta sám: mezičlen se určuje úvěrovým účtem výpisu, koncovka karty
 * (výpis ČSOB ji vůbec nenese) nerozhoduje.
 *
 * Izolace: rok 2099, sdílená transakce BankPostingTestCase (rollback v tearDown).
 * Čísla účtů, obchodníci i částky jsou syntetické.
 */
#[Group('integration')]
final class CreditCardPurchaseModeTest extends BankPostingTestCase
{
    private const CC_ACCOUNT = '27-1000000005';
    private const CC_BANK = '0300';

    private CreditCardAccountRepository $cards;
    private CreditCardPostingService $cc;
    private int $ccId = 0;
    private string $ccCode = '';

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['bank.interest', 'bank.fee', 'bank.transfer.own', 'detector.own_transfer'] as $op) {
            $this->db->pdo()->prepare(
                "INSERT INTO auto_posting_policy (supplier_id, operation_type, level, updated_by)
                 VALUES (?, ?, 'auto', ?)
                 ON DUPLICATE KEY UPDATE level = 'auto'"
            )->execute([$this->supplierId, $op, $this->userId]);
        }
        // Nastavení kreditních karet a platebních karet firmy z klonu nesmí test ovlivnit.
        $this->db->pdo()->prepare('DELETE FROM credit_card_settings WHERE supplier_id = ?')->execute([$this->supplierId]);
        $this->db->pdo()->prepare('DELETE FROM card_clearing_settings WHERE supplier_id = ?')->execute([$this->supplierId]);
        $this->container->get(CardClearingRegime::class)->forget($this->supplierId);
        // Výchozí režim nákupů je napřímo; tyhle testy ověřují zapnutý mezičlen.
        $this->container->get(CreditCardSettingsService::class)->save($this->supplierId, ['purchase_mode' => 'clearing'], $this->userId);

        $this->cards = $this->container->get(CreditCardAccountRepository::class);
        $this->cc = $this->container->get(CreditCardPostingService::class);
        $this->ccId = $this->cards->create($this->supplierId, [
            'issuer'         => 'csob',
            'label'          => 'Firemní kreditka',
            'account_number' => self::CC_ACCOUNT,
            'bank_code'      => self::CC_BANK,
            'currency'       => 'CZK',
            'credit_limit'   => 80000.0,
            'is_verified'    => true,
        ], $this->userId);
        $code = $this->container->get(CreditCardAccounts::class)->ensureAnalytic($this->supplierId, (array) $this->cards->find($this->supplierId, $this->ccId));
        self::assertNotNull($code);
        $this->ccCode = $code;
    }

    // ── režim mezičlenu ─────────────────────────────────────────────────────────

    public function testDefaultModePostsPurchaseWithoutCardSuffixOnCreditCardClearing(): void
    {
        $tx = $this->ccTx(-640.00, 'Čerpání úvěru platební kartou | d.tran. 14.06.2099 | Praha');

        $res = $this->service->handleTransaction($tx, $this->userId);

        self::assertSame('posted', $res['action'], json_encode($res));
        $lines = $this->linesByAccountCode((int) $res['entry_id']);
        $clearing = $this->clearingCode();
        self::assertStringStartsWith('378.', $clearing, 'Úvěrový účet dostal vlastní analytiku mezičlenu.');
        self::assertEqualsWithDelta(640.00, $lines[$clearing]['debit'], 0.001);
        self::assertEqualsWithDelta(640.00, $lines[$this->ccCode]['credit'], 0.001, 'Nákup zvyšuje dluh na 231.x hned v den platby.');
        self::assertCount(2, $lines);
        self::assertSame([], array_filter(array_keys($lines), static fn ($c): bool => str_starts_with((string) $c, '221')));
    }

    /** Karta evidovaná mezi platebními kartami nemění analytiku: u kreditky rozhoduje úvěrový účet. */
    public function testPurchaseWithCardSuffixStillUsesCreditAccountClearing(): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO card_clearing_settings (supplier_id, enabled, effective_from, clearing_synthetic)
             VALUES (?, 1, '2099-01-01', '378')"
        )->execute([$this->supplierId]);
        $this->container->get(CardClearingRegime::class)->forget($this->supplierId);
        $tx = $this->ccTx(-90.00, 'Platba kartou | d.tran. 12.06.2099 | Plzen');
        $this->db->pdo()->prepare("UPDATE bank_transactions SET card_last4 = '4321' WHERE id = ?")->execute([$tx]);

        $res = $this->service->handleTransaction($tx, $this->userId);

        $lines = $this->linesByAccountCode((int) $res['entry_id']);
        self::assertEqualsWithDelta(90.00, $lines[$this->clearingCode()]['debit'], 0.001);
        self::assertSame(0, (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM payment_cards WHERE supplier_id = {$this->supplierId} AND last4 = '4321'"
        )->fetchColumn(), 'Kreditka nezakládá neověřenou platební kartu.');
    }

    public function testDocumentMatchedLaterSettlesClearing(): void
    {
        $tx = $this->ccTx(-500.00, 'Nákup na internetu | d.tran. 14.06.2099');
        $this->service->handleTransaction($tx, $this->userId);
        $pi = $this->postedPurchase(500.00);
        $this->paymentMatch($tx, $pi, 500.00);
        $this->db->pdo()->prepare("UPDATE bank_transactions SET match_status = 'manual' WHERE id = ?")->execute([$tx]);

        $res = $this->service->syncCardSettlement($this->supplierId, $tx, $this->userId);

        self::assertArrayHasKey('entry_id', $res, json_encode($res));
        $lines = $this->linesByAccountCode((int) $res['entry_id']);
        self::assertEqualsWithDelta(500.00, $lines['321']['debit'], 0.001);
        self::assertEqualsWithDelta(500.00, $lines[$this->clearingCode()]['credit'], 0.001);
        self::assertEqualsWithDelta(0.00, $this->balance($this->clearingCode()), 0.001, 'Doložený nákup na mezičlenu nic nenechá.');
        self::assertEqualsWithDelta(-500.00, $this->balance($this->ccCode), 0.001, 'Dluh vůči bance trvá do splátky.');
    }

    public function testMerchantRefundGoesBackThroughClearing(): void
    {
        $tx = $this->ccTx(150.00, 'Vratka platby kartou | d.tran. 20.06.2099');

        $res = $this->service->handleTransaction($tx, $this->userId);

        self::assertSame('posted', $res['action'], json_encode($res));
        $lines = $this->linesByAccountCode((int) $res['entry_id']);
        self::assertEqualsWithDelta(150.00, $lines[$this->ccCode]['debit'], 0.001);
        self::assertEqualsWithDelta(150.00, $lines[$this->clearingCode()]['credit'], 0.001);
    }

    public function testInterestAndRepaymentNeverGoThroughClearing(): void
    {
        $interest = $this->ccTx(-33.00, 'ÚROK Z ÚVĚRU');
        $repayment = $this->ccTx(2000.00, 'Splátka úvěru');

        $ri = $this->service->handleTransaction($interest, $this->userId);
        $rr = $this->service->handleTransaction($repayment, $this->userId);
        self::assertSame('posted', $ri['action'], json_encode($ri));
        self::assertSame('posted', $rr['action'], json_encode($rr));
        $i = $this->linesByAccountCode((int) $ri['entry_id']);
        $r = $this->linesByAccountCode((int) $rr['entry_id']);

        self::assertEqualsWithDelta(33.00, $i['562']['debit'], 0.001);
        self::assertEqualsWithDelta(2000.00, $r[$this->ccCode]['debit'], 0.001, 'Splátka snižuje dluh na 231.x.');
        self::assertSame([], array_filter(array_keys($i + $r), static fn ($c): bool => str_starts_with((string) $c, '378')));
    }

    // ── režim bez mezičlenu, přepnutí a dohnání ────────────────────────────────

    public function testDirectModeLeavesPurchaseWaitingForDocument(): void
    {
        $this->cc->setPurchaseMode($this->supplierId, $this->ccId, 'direct');
        $tx = $this->ccTx(-120.00, 'Nákup u obchodníka | d.tran. 14.06.2099');

        $res = $this->service->handleTransaction($tx, $this->userId);

        self::assertNotSame('posted', $res['action'], json_encode($res));
        self::assertSame(0, $this->entryCountForTx($tx));
    }

    public function testAccountModeOverridesCompanyDefault(): void
    {
        $this->container->get(CreditCardSettingsService::class)->save($this->supplierId, ['purchase_mode' => 'direct'], $this->userId);
        $this->cc->setPurchaseMode($this->supplierId, $this->ccId, 'clearing');
        $tx = $this->ccTx(-75.00, 'Nákup na internetu');

        $res = $this->service->handleTransaction($tx, $this->userId);

        self::assertSame('posted', $res['action'], json_encode($res));
        self::assertArrayHasKey($this->clearingCode(), $this->linesByAccountCode((int) $res['entry_id']));
    }

    public function testPostPendingCatchesUpWaitingPurchasesAfterSwitchToClearing(): void
    {
        $this->cc->setPurchaseMode($this->supplierId, $this->ccId, 'direct');
        $statement = $this->statement(self::CC_ACCOUNT, self::CC_BANK);
        $a = $this->transaction($statement, -300.00, ['description' => 'Nákup A', 'counterparty_name' => null]);
        $b = $this->transaction($statement, -200.00, ['description' => 'Nákup B', 'counterparty_name' => null]);
        $this->service->handleTransaction($a, $this->userId);
        $this->service->handleTransaction($b, $this->userId);
        self::assertSame(0, $this->entryCountForTx($a) + $this->entryCountForTx($b));

        $this->cc->setPurchaseMode($this->supplierId, $this->ccId, null);
        $result = $this->cc->postPending($this->supplierId, $this->ccId, $this->userId);

        self::assertSame(2, $result['total']);
        self::assertSame(2, $result['posted'], json_encode($result));
        self::assertEqualsWithDelta(-500.00, $this->balance($this->ccCode), 0.001);
        self::assertEqualsWithDelta(500.00, $this->balance($this->clearingCode()), 0.001);
        self::assertSame(0, $this->cc->postPending($this->supplierId, $this->ccId, $this->userId)['total'], 'Druhý běh nemá co dělat.');
    }

    /** Historie se nepřeúčtovává: pohyb zaúčtovaný bez mezičlenu zůstane i po přepnutí. */
    public function testPostedPurchaseStaysAfterModeSwitch(): void
    {
        $this->cc->setPurchaseMode($this->supplierId, $this->ccId, 'direct');
        $tx = $this->ccTx(-60.00, 'Nákup u obchodníka');
        $this->service->postManual($this->supplierId, $tx, ['debit_account_code' => '518', 'credit_account_code' => '221'], $this->meta());
        $entry = (int) $this->journal->findBySource($this->supplierId, 'bank', $tx)['id'];

        $this->cc->setPurchaseMode($this->supplierId, $this->ccId, 'clearing');
        $this->service->handleTransaction($tx, $this->userId);

        self::assertSame($entry, (int) $this->journal->findBySource($this->supplierId, 'bank', $tx)['id']);
        self::assertArrayHasKey('518', $this->linesByAccountCode($entry));
    }

    // ── uzavření bez dokladu ────────────────────────────────────────────────────

    public function testWriteOffNonTaxUsesCreditCardSettingsAccount(): void
    {
        $this->container->get(CreditCardSettingsService::class)->save($this->supplierId, [
            'writeoff_nontax_account_id' => $this->accountId('513'),
        ], $this->userId);
        $tx = $this->ccTx(-240.00, 'Nákup u obchodníka | d.tran. 14.06.2099');
        $this->service->handleTransaction($tx, $this->userId);

        $res = $this->container->get(CardClearingWriteOffService::class)->writeOff($this->supplierId, $tx, 'expense', null, $this->userId);

        self::assertSame('513', $res['account_code']);
        $lines = $this->linesByAccountCode($res['entry_id']);
        self::assertEqualsWithDelta(240.00, $lines['513']['debit'], 0.001);
        self::assertEqualsWithDelta(240.00, $lines[$this->clearingCode()]['credit'], 0.001);
        self::assertEqualsWithDelta(0.00, $this->balance($this->clearingCode()), 0.001);
    }

    public function testWriteOffTaxAndPrivateUseTheirDefaults(): void
    {
        $this->container->get(CreditCardSettingsService::class)->save($this->supplierId, [
            'private_account_id' => $this->accountId('355'),
        ], $this->userId);
        $tax = $this->ccTx(-100.00, 'Parkovné');
        $private = $this->ccTx(-80.00, 'Nákup potravin');
        $this->service->handleTransaction($tax, $this->userId);
        $this->service->handleTransaction($private, $this->userId);
        $writeOffs = $this->container->get(CardClearingWriteOffService::class);

        $t = $writeOffs->writeOff($this->supplierId, $tax, 'expense_tax', null, $this->userId);
        $p = $writeOffs->writeOff($this->supplierId, $private, 'holder', null, $this->userId);

        self::assertSame('518', $t['account_code'], 'Daňový náklad bez dokladu jde na 518, dokud ho nastavení nezmění.');
        self::assertSame('355', $p['account_code'], 'Soukromý nákup společníka: pohledávka 355 z nastavení kreditních karet.');
        self::assertEqualsWithDelta(80.00, $this->linesByAccountCode($p['entry_id'])['355']['debit'], 0.001);
    }

    public function testWriteOffRejectsClearingAnalyticAsTarget(): void
    {
        $tx = $this->ccTx(-50.00, 'Nákup');
        $this->service->handleTransaction($tx, $this->userId);

        $this->expectException(PostingException::class);
        $this->container->get(CardClearingWriteOffService::class)
            ->writeOff($this->supplierId, $tx, 'holder', $this->accountId($this->clearingCode()), $this->userId);
    }

    // ── počáteční dluh ──────────────────────────────────────────────────────────

    public function testOpeningDebtIsPostedOnceAgainstConfiguredAccount(): void
    {
        $statement = $this->ccStatement(-1000.00);
        $this->transaction($statement, -100.00, ['description' => 'ÚROK Z ÚVĚRU', 'counterparty_name' => null, 'posted_at' => '2099-06-05']);

        $preview = $this->cc->openingPreview($this->supplierId, $this->ccId);
        self::assertTrue($preview['needed']);
        self::assertEqualsWithDelta(-1000.00, $preview['amount'], 0.001);
        self::assertSame('379', $preview['contra_account_code']);
        self::assertSame('2099-06-05', $preview['entry_date']);

        $res = $this->cc->postOpening($this->supplierId, $this->ccId, null, null, $this->userId);

        $lines = $this->linesByAccountCode($res['entry_id']);
        self::assertEqualsWithDelta(1000.00, $lines[$this->ccCode]['credit'], 0.001, 'Dluh = D 231.x.');
        self::assertEqualsWithDelta(1000.00, $lines['379']['debit'], 0.001);
        self::assertFalse($this->cc->openingPreview($this->supplierId, $this->ccId)['needed']);
        try {
            $this->cc->postOpening($this->supplierId, $this->ccId, null, null, $this->userId);
            self::fail('Počáteční dluh se nesmí zaúčtovat dvakrát.');
        } catch (PostingException $e) {
            self::assertSame('already_posted', $e->errorCode);
        }
    }

    /** Dluh převzatý jinou cestou (ruční zápis na 231.x) se neúčtuje podruhé - jen rozdíl. */
    public function testOpeningCountsOnlyWhatIsNotInLedgerYet(): void
    {
        $this->ccStatement(-1000.00);
        $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '379', 'side' => 'debit', 'amount' => 400.00],
            ['account_code' => $this->ccCode, 'side' => 'credit', 'amount' => 400.00],
        ], ['entry_date' => '2099-01-01', 'description' => 'Převzatý zůstatek', 'posted' => true, 'user_id' => $this->userId]);

        $preview = $this->cc->openingPreview($this->supplierId, $this->ccId);
        $res = $this->cc->postOpening($this->supplierId, $this->ccId, '365', '2099-06-01', $this->userId);

        self::assertEqualsWithDelta(-600.00, $preview['amount'], 0.001);
        self::assertEqualsWithDelta(600.00, $this->linesByAccountCode($res['entry_id'])['365']['debit'], 0.001);
        self::assertEqualsWithDelta(-1000.00, $this->balance($this->ccCode), 0.001, '231.x sedí na počáteční zůstatek výpisu.');
    }

    public function testOpeningInClosedPeriodIsRefusedWithExplanation(): void
    {
        $this->ccStatement(-500.00);
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closed' WHERE id = ?")->execute([$this->periodId]);

        try {
            $this->cc->postOpening($this->supplierId, $this->ccId, null, null, $this->userId);
            self::fail('Do uzavřeného období se počáteční dluh zaúčtovat nesmí.');
        } catch (PostingException $e) {
            self::assertSame('period_closed', $e->errorCode);
            self::assertStringContainsString('uzavřené', $e->getMessage());
        }
    }

    public function testOpeningRejectsCreditCardOwnAnalyticAsContra(): void
    {
        $this->ccStatement(-500.00);

        $this->expectException(PostingException::class);
        $this->cc->postOpening($this->supplierId, $this->ccId, $this->ccCode, null, $this->userId);
    }

    // ── přehled „co zbývá dořešit" a platby kartou bez dokladu ─────────────────

    public function testDetailShowsStateOfEveryTransactionAndTodoSummary(): void
    {
        $statement = $this->ccStatement(0.0);
        $open = $this->transaction($statement, -300.00, ['description' => 'Nákup A', 'counterparty_name' => null]);
        $closed = $this->transaction($statement, -200.00, ['description' => 'Nákup B', 'counterparty_name' => null]);
        $ignored = $this->transaction($statement, -5.00, ['description' => 'Nákup C', 'counterparty_name' => null, 'match_status' => 'ignored']);
        $this->service->handleTransaction($open, $this->userId);
        $this->service->handleTransaction($closed, $this->userId);
        $this->container->get(CardClearingWriteOffService::class)->writeOff($this->supplierId, $closed, 'expense', null, $this->userId);

        $detail = $this->container->get(CreditCardOverview::class)->detail($this->supplierId, $this->ccId);

        $states = array_column($detail['transactions'], 'state', 'id');
        self::assertSame('clearing_open', $states[$open]);
        self::assertSame('settled', $states[$closed]);
        self::assertSame('ignored', $states[$ignored]);
        self::assertSame(1, $detail['todo']['clearing_open']['count']);
        self::assertEqualsWithDelta(300.00, $detail['todo']['clearing_open']['amount'], 0.001);
        self::assertSame($open, $detail['todo']['clearing_open']['first_tx_id']);
        self::assertEqualsWithDelta(300.00, $detail['clearing']['balance'], 0.001, 'Zůstatek mezičlenu = nevypořádané nákupy.');
        self::assertSame('clearing', $detail['clearing']['mode']);
    }

    /** Koncovka, kterou nesou jen výpisy kreditní karty, není platební karta: založit ji mezi platebními nejde. */
    public function testPaymentCardWithCreditCardSuffixIsRefused(): void
    {
        $tx = $this->ccTx(-90.00, 'Platba kartou | d.tran. 11.06.2099');
        $this->db->pdo()->prepare("UPDATE bank_transactions SET card_last4 = '3532' WHERE id = ?")->execute([$tx]);
        $action = $this->container->get(\MyInvoice\Action\Bank\PaymentCardAction::class);
        $create = function (string $last4) use ($action): array {
            $request = (new \Slim\Psr7\Factory\ServerRequestFactory())
                ->createServerRequest('POST', '/api/payment-cards')
                ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
                ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
                ->withParsedBody(['label' => 'Karta ' . $last4, 'last4' => $last4, 'card_type' => 'debit']);
            $response = $action->create($request, new \Slim\Psr7\Response());
            $response->getBody()->rewind();
            return ['status' => $response->getStatusCode(), 'body' => (array) json_decode((string) $response->getBody(), true)];
        };

        $refused = $create('3532');
        self::assertSame(422, $refused['status'], json_encode($refused['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('credit_card_last4', $refused['body']['error']['code'] ?? null);

        // Stejná koncovka viděná i na běžném účtu (platební karta téhož čísla) projde.
        $this->transaction($this->statement(), -20.00, ['description' => 'Platba kartou']);
        $this->db->pdo()->exec("UPDATE bank_transactions SET card_last4 = '3532' WHERE card_last4 IS NULL AND description = 'Platba kartou' ORDER BY id DESC LIMIT 1");
        self::assertSame(201, $create('3532')['status']);
        self::assertSame(201, $create('7777')['status'], 'Koncovka bez výpisu kreditky jde založit jako dřív.');
    }

    /** Měsíční kontrola plateb kartou bez dokladu hlídá platební karty; nákupy kreditkou řeší detail kreditky. */
    public function testMonthlyCardCheckDoesNotReportCreditCardPurchases(): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO card_clearing_settings (supplier_id, enabled, effective_from, clearing_synthetic, unmatched_alert_days)
             VALUES (?, 1, '2099-01-01', '378', 10)"
        )->execute([$this->supplierId]);
        $this->container->get(CardClearingRegime::class)->forget($this->supplierId);
        $tx = $this->ccTx(-75.00, 'Platba kartou | d.tran. 01.06.2099', ['posted_at' => '2099-06-01']);
        $this->db->pdo()->prepare("UPDATE bank_transactions SET card_last4 = '3532' WHERE id = ?")->execute([$tx]);

        $late = $this->container->get(\MyInvoice\Service\Accounting\Card\CardClearingOverview::class)
            ->unmatchedOlderThan($this->supplierId, '2099-06-30');
        self::assertNotContains($tx, array_column($late['items'], 'tx_id'));
    }

    public function testCreditCardPurchaseIsListedOnlyForItsCreditCardNotAmongPaymentCards(): void
    {
        $tx = $this->ccTx(-410.00, 'Nákup na internetu');
        $withSuffix = $this->ccTx(-90.00, 'Platba kartou | d.tran. 11.06.2099');
        $this->db->pdo()->prepare("UPDATE bank_transactions SET card_last4 = '3532' WHERE id = ?")->execute([$withSuffix]);
        $interest = $this->ccTx(-12.00, 'ÚROK Z ÚVĚRU');
        $this->service->handleTransaction($tx, $this->userId);

        // Platební karty: pohyb z výpisu kreditní karty sem nepatří, ani s koncovkou karty.
        $payment = $this->container->get(CardPaymentOverview::class)->unmatched($this->supplierId, '2099-01-01', '2099-12-31');
        $paymentIds = [];
        foreach ($payment['groups'] as $g) {
            self::assertNull($g['credit_card'], 'Platby bez dokladu platebních karet kreditku nemíchají.');
            array_push($paymentIds, ...array_column($g['transactions'], 'id'));
        }
        self::assertNotContains($tx, $paymentIds);
        self::assertNotContains($withSuffix, $paymentIds, 'Koncovka karty z výpisu kreditky z pohybu platbu platební kartou nedělá.');

        $list = $this->container->get(CardPaymentOverview::class)->unmatched($this->supplierId, '2099-01-01', '2099-12-31', $this->ccId);

        $group = null;
        foreach ($list['groups'] as $g) {
            if (($g['credit_card']['id'] ?? null) === $this->ccId) {
                $group = $g;
            }
        }
        self::assertNotNull($group, 'Nákup kreditkou čeká na doklad jako každá platba kartou.');
        $ids = array_column($group['transactions'], 'id');
        self::assertContains($tx, $ids);
        self::assertContains($withSuffix, $ids, 'Nákup s koncovkou karty patří ke kreditce, ne k platebním kartám.');
        self::assertNotContains($interest, $ids, 'Úrok doklad nečeká.');
        $row = array_values(array_filter($group['transactions'], static fn (array $t): bool => $t['id'] === $tx))[0];
        self::assertSame($this->clearingCode(), $row['clearing_account']);
    }

    // ── izolace a oprávnění ────────────────────────────────────────────────────

    public function testOtherSupplierCannotTouchCreditCard(): void
    {
        $other = $this->otherSupplierId();
        foreach ([
            fn () => $this->cc->setPurchaseMode($other, $this->ccId, 'direct'),
            fn () => $this->cc->postPending($other, $this->ccId, $this->userId),
            fn () => $this->cc->postOpening($other, $this->ccId, null, null, $this->userId),
            fn () => $this->cc->openingPreview($other, $this->ccId),
        ] as $i => $call) {
            try {
                $call();
                self::fail("Volání #{$i} cizí firmy muselo selhat.");
            } catch (PostingException $e) {
                self::assertContains($e->errorCode, ['not_found', 'not_double_entry'], "Volání #{$i}");
            }
        }
        self::assertNull($this->cards->find($this->supplierId, $this->ccId)['purchase_mode']);
    }

    public function testAccountingActionsRequireBankPostPermission(): void
    {
        $action = $this->container->get(CreditCardAction::class);
        $args = ['id' => (string) $this->ccId];

        foreach (['setPurchaseMode' => 'PUT', 'setClearingAnalytic' => 'PUT', 'postPending' => 'POST', 'postOpening' => 'POST'] as $method => $http) {
            $res = $this->callAction($action, $method, $http, 'readonly', ['purchase_mode' => 'direct'], $args);
            self::assertSame(403, $res['status'], $method);
        }
    }

    /** Analytika mezičlenu kreditky je vyhrazená: holé 378 z jiného zápisu na ni nepřesměruje. */
    public function testBare378IsNotRedirectedToCreditCardClearing(): void
    {
        $tx = $this->ccTx(-10.00, 'Nákup');
        $this->service->handleTransaction($tx, $this->userId);

        $entryId = $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '378', 'side' => 'debit', 'amount' => 70.00],
            ['account_code' => '211', 'side' => 'credit', 'amount' => 70.00],
        ], ['entry_date' => '2099-06-15', 'description' => 'Jiná pohledávka', 'posted' => true, 'user_id' => $this->userId]);

        $lines = $this->linesByAccountCode($entryId);
        self::assertArrayNotHasKey($this->clearingCode(), $lines);
        self::assertEqualsWithDelta(70.00, $lines['378']['debit'], 0.001);
    }

    /** Platební karta a úvěrový účet sdílejí řadu analytik mezičlenu - číslo nesmí dostat dvakrát. */
    public function testPaymentCardDoesNotReuseCreditCardClearingSuffix(): void
    {
        $this->service->handleTransaction($this->ccTx(-10.00, 'Nákup'), $this->userId);
        $this->db->pdo()->prepare(
            "INSERT INTO card_clearing_settings (supplier_id, enabled, effective_from, clearing_synthetic)
             VALUES (?, 1, '2099-01-01', '378')"
        )->execute([$this->supplierId]);
        $this->container->get(CardClearingRegime::class)->forget($this->supplierId);
        $debit = $this->transaction($this->statement(), -25.00, ['description' => 'Platba kartou']);
        $this->db->pdo()->prepare("UPDATE bank_transactions SET card_last4 = '9876' WHERE id = ?")->execute([$debit]);

        $res = $this->service->handleTransaction($debit, $this->userId);

        $codes = array_map('strval', array_keys($this->linesByAccountCode((int) $res['entry_id'])));
        $cardCode = array_values(array_filter($codes, static fn (string $c): bool => str_starts_with($c, '378.')))[0] ?? null;
        self::assertNotNull($cardCode, json_encode($res));
        self::assertNotSame($this->clearingCode(), $cardCode);
    }

    // ── helpers ─────────────────────────────────────────────────────────────────

    private function clearingCode(): string
    {
        $suffix = $this->cards->find($this->supplierId, $this->ccId)['clearing_suffix'];
        self::assertNotNull($suffix, 'Úvěrový účet ještě nemá analytiku mezičlenu.');
        return CardClearingAccounts::codeFor('378', (string) $suffix);
    }

    private function ccStatement(float $prevBalance): int
    {
        $id = $this->statement(self::CC_ACCOUNT, self::CC_BANK);
        $this->db->pdo()->prepare('UPDATE bank_statements SET prev_balance = ?, curr_balance = ?, statement_date = ? WHERE id = ?')
            ->execute([$prevBalance, $prevBalance, '2099-06-30', $id]);
        return $id;
    }

    /** @param array<string,mixed> $over */
    private function ccTx(float $amount, string $description, array $over = []): int
    {
        return $this->transaction($this->statement(self::CC_ACCOUNT, self::CC_BANK), $amount, $over + [
            'counterparty_name' => null,
            'description' => $description,
        ]);
    }

    private function postedPurchase(float $total): int
    {
        $vendor = $this->client('Dodavatel kreditka ' . uniqid());
        $pi = $this->purchaseInvoice('PF-CCM-' . uniqid(), $vendor, $total);
        $this->postPredpis('purchase_invoice', $pi, '518', '321', $total);
        return $pi;
    }

    private function accountId(string $code): int
    {
        $row = $this->accounts->findByCode($this->supplierId, $code);
        self::assertNotNull($row, "Účet {$code} v osnově chybí.");
        return (int) $row['id'];
    }

    /** Zůstatek účtu (MD − D) ze zápisů roku 2099. */
    private function balance(string $code): float
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END), 0)
               FROM journal_entry_lines l
               JOIN journal_entries e ON e.id = l.entry_id
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.supplier_id = ? AND e.entry_date BETWEEN '2099-01-01' AND '2099-12-31' AND a.account_code = ?"
        );
        $stmt->execute([$this->supplierId, $code]);
        return round((float) $stmt->fetchColumn(), 2);
    }
}
