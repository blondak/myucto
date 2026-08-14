<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Eshop;

use MyInvoice\Repository\StockCurrencyRepository;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Epic ESHOP — číselník prodejních měn (migrace 1371).
 *
 * Ceny na kartě zboží braly nabídku z `currencies`, tedy z měnových ÚČTŮ firmy.
 * Prodejní měna je ale prezentace ceny: zboží se dá nacenit v GBP i bez britského
 * účtu a zákazník zaplatí kartou třeba na eurový. Číselník proto stojí samostatně.
 */
#[Group('integration')]
final class EshopCurrencyCodebookTest extends StockTestCase
{
    private StockCurrencyRepository $currencies;

    protected function setUp(): void
    {
        parent::setUp();
        $this->currencies = $this->container->get(StockCurrencyRepository::class);
    }

    public function testCodebookIsScopedPerSupplier(): void
    {
        $a = $this->createSupplier();
        $b = $this->createSupplier();

        $this->currencies->insert($a, ['code' => 'GBP', 'name' => 'Britská libra']);

        self::assertSame(['GBP'], $this->currencies->codes($a));
        self::assertSame([], $this->currencies->codes($b), 'Měny firmy A nesmí prosáknout do firmy B.');
    }

    public function testSameCodeMayExistForTwoSuppliers(): void
    {
        $a = $this->createSupplier();
        $b = $this->createSupplier();

        $this->currencies->insert($a, ['code' => 'USD', 'name' => 'Americký dolar']);
        $this->currencies->insert($b, ['code' => 'USD', 'name' => 'Americký dolar']);

        self::assertNotNull($this->currencies->findByCode($a, 'USD'));
        self::assertNotNull($this->currencies->findByCode($b, 'USD'));
    }

    /** Jádro požadavku: nacenit lze i měnu, ve které firma nemá bankovní účet. */
    public function testCurrencyWithoutBankAccountIsAllowed(): void
    {
        $sid = $this->createSupplier();
        $this->currencies->insert($sid, ['code' => 'GBP', 'name' => 'Britská libra', 'symbol' => '£']);

        $accounts = $this->db->pdo()->prepare('SELECT COUNT(*) FROM currencies WHERE supplier_id = ? AND code = ?');
        $accounts->execute([$sid, 'GBP']);

        self::assertSame(0, (int) $accounts->fetchColumn(), 'Test dává smysl jen bez měnového účtu v GBP.');
        self::assertNotNull($this->currencies->findByCode($sid, 'GBP'));
    }

    /** Kód se normalizuje na velká písmena, ať `gbp` a `GBP` nejsou dvě měny. */
    public function testCodeIsStoredUppercase(): void
    {
        $sid = $this->createSupplier();
        $this->currencies->insert($sid, ['code' => 'pln', 'name' => 'Polský zlotý']);

        self::assertSame(['PLN'], $this->currencies->codes($sid));
        self::assertNotNull($this->currencies->findByCode($sid, 'pln'));
    }

    /** Prázdný symbol je null, ne prázdný řetězec — v tabulce by se zobrazil jako mezera. */
    public function testEmptySymbolIsStoredAsNull(): void
    {
        $sid = $this->createSupplier();
        $id = $this->currencies->insert($sid, ['code' => 'HUF', 'name' => 'Maďarský forint', 'symbol' => '  ']);

        self::assertNull($this->currencies->find($sid, $id)['symbol']);
    }

    /**
     * Archivace měnu jen stáhne z nabídky pro nové řádky — `codes()` ji vrací dál,
     * aby šlo uložit kartu, která v ní má cenu už zadanou.
     */
    public function testArchivedCurrencyStaysInWritableCodes(): void
    {
        $sid = $this->createSupplier();
        $this->currencies->insert($sid, ['code' => 'DKK', 'name' => 'Dánská koruna', 'archived' => true]);

        self::assertSame(['DKK'], $this->currencies->codes($sid));
        self::assertSame([], $this->currencies->listForSupplier($sid, true), 'Archivovaná měna nepatří do nabídky.');
        self::assertCount(1, $this->currencies->listForSupplier($sid, false));
    }

    public function testReferencedCurrencyIsDetected(): void
    {
        $sid = $this->createSupplier();
        $this->currencies->insert($sid, ['code' => 'EUR', 'name' => 'Euro']);
        $itemId = $this->item($sid, 'SKU-CUR-1');

        self::assertFalse($this->currencies->isReferenced($sid, 'EUR'));

        $this->db->pdo()->prepare(
            'INSERT INTO stock_item_prices (supplier_id, stock_item_id, currency_code, price_mode, fixed_price)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$sid, $itemId, 'EUR', 'fixed', 100.0]);

        self::assertTrue($this->currencies->isReferenced($sid, 'EUR'));
        self::assertTrue($this->currencies->isReferenced($sid, 'eur'), 'Kontrola nesmí být citlivá na velikost písmen.');
    }

    public function testClearDefaultExceptLeavesSingleDefault(): void
    {
        $sid = $this->createSupplier();
        $czk = $this->currencies->insert($sid, ['code' => 'CZK', 'name' => 'Česká koruna', 'is_default' => true]);
        $eur = $this->currencies->insert($sid, ['code' => 'EUR', 'name' => 'Euro', 'is_default' => true]);

        $this->currencies->clearDefaultExcept($sid, $eur);

        self::assertFalse($this->currencies->find($sid, $czk)['is_default']);
        self::assertTrue($this->currencies->find($sid, $eur)['is_default']);
    }
}
