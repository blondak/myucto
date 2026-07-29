<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Accounting;

use MyInvoice\Service\Accounting\Expense\ExpenseKind;
use MyInvoice\Service\Accounting\Expense\ExpenseKindClassifier;
use PHPUnit\Framework\TestCase;

/**
 * §DM — kontrakt mezi tabulkou `expense_classification_rules` (1093) a pure klasifikátorem.
 *
 * PROČ zvlášť od ExpenseKindClassifierTest: ten testuje ALGORITMUS na ručně psaných polích.
 * Tenhle testuje TVAR — pravidlo se do klasifikátoru dostává jako řádek z repozitáře, takže
 * přejmenování sloupce v migraci nebo v ExpenseClassificationRuleRepository::COLS je tichá
 * regrese: klasifikátor klíč nenajde, kritérium prostě přeskočí a pravidlo začne matchovat
 * ŠÍŘ, než uživatel zadal. Nespadne nic, jen se firma přeúčtuje. Proto fixtury níž kopírují
 * přesné názvy sloupců z 1093.
 *
 * Pure — cenové rozpětí (amount_min/amount_max) tady schválně není: to vyhodnocuje
 * ExpenseClassificationService, ne klasifikátor (viz komentář v 1093).
 */
final class ExpenseRuleRowContractTest extends TestCase
{
    private const LIMIT = 80000.0;

    private ExpenseKindClassifier $c;

    protected function setUp(): void
    {
        $this->c = new ExpenseKindClassifier();
    }

    /** Řádek z repozitáře (cast() → bool/int/float) musí klasifikátor přečíst beze změny tvaru. */
    public function testRepositoryRowShapeMatchesOnVendorName(): void
    {
        $s = $this->c->classify('položka bez klíčového slova', 'Mironet.cz a.s.', null, 3000.0, self::LIMIT, [
            $this->row(['name' => 'Mironet = zboží', 'vendor_name_contains' => 'Mironet', 'expense_kind' => 'small_asset']),
        ]);

        self::assertNotNull($s);
        self::assertSame(ExpenseKind::SmallAsset, $s->kind);
        self::assertSame('rule', $s->source);
        self::assertStringContainsString('Mironet = zboží', $s->reason, 'Důvod nese jméno pravidla — UI ho zobrazuje.');
        self::assertTrue($s->isAutoApplicable(), 'Shoda pravidlem má plnou jistotu.');
    }

    public function testMatchesOnVendorClientIdFromRepositoryRow(): void
    {
        $s = $this->c->classify('nespecifikovaná položka', null, 42, 3000.0, self::LIMIT, [
            $this->row(['name' => 'Klient 42', 'vendor_client_id' => 42, 'expense_kind' => 'material']),
        ]);

        self::assertNotNull($s);
        self::assertSame(ExpenseKind::Material, $s->kind);
    }

    /** Kritéria platí AND: pravidlo s dodavatelem i textem nesmí chytit jen podle dodavatele. */
    public function testCriteriaAreAndedAcrossColumns(): void
    {
        $rule = $this->row([
            'name' => 'Alza — notebooky',
            'vendor_name_contains' => 'Alza',
            'description_contains' => 'notebook',
            'expense_kind' => 'small_asset',
        ]);

        $miss = $this->c->classify('reklamní předmět', 'Alza.cz a.s.', null, 300.0, self::LIMIT, [$rule]);
        self::assertNull($miss, 'Sedí dodavatel, ale ne text ⇒ pravidlo nematchuje.');

        $hit = $this->c->classify('notebook Dell', 'Alza.cz a.s.', null, 30000.0, self::LIMIT, [$rule]);
        self::assertNotNull($hit);
        self::assertSame('rule', $hit->source, 'Sedí obojí ⇒ rozhoduje pravidlo, ne klíčová slova.');
    }

    /**
     * Pořadí z `activeFor()` (priority ASC, hit_count DESC, id ASC) je jediné, co určuje
     * vítěze — klasifikátor bere PRVNÍ shodu a nic si nepřerovnává. Konkrétní pravidlo tedy
     * musí přijít dřív než obecné, jinak obecné „Alza = drobný majetek" sebere všechno.
     */
    public function testFirstMatchWinsInRepositoryOrder(): void
    {
        $ordered = [
            $this->row(['name' => 'Alza — kabely', 'priority' => 10, 'vendor_name_contains' => 'Alza',
                'description_contains' => 'kabel', 'expense_kind' => 'material']),
            $this->row(['name' => 'Alza — vše ostatní', 'priority' => 100, 'vendor_name_contains' => 'Alza',
                'expense_kind' => 'small_asset']),
        ];

        $specific = $this->c->classify('kabel HDMI', 'Alza.cz a.s.', null, 300.0, self::LIMIT, $ordered);
        self::assertNotNull($specific);
        self::assertSame(ExpenseKind::Material, $specific->kind, 'Konkrétní pravidlo (priority 10) vyhrává.');
        self::assertStringContainsString('Alza — kabely', $specific->reason);

        $general = $this->c->classify('monitor', 'Alza.cz a.s.', null, 6000.0, self::LIMIT, $ordered);
        self::assertNotNull($general);
        self::assertSame(ExpenseKind::SmallAsset, $general->kind, 'Na co konkrétní nesedí, bere obecné.');
    }

    /** is_active = false z repozitáře (bool po cast()) musí pravidlo vyřadit. */
    public function testInactiveRuleFromRepositoryIsSkipped(): void
    {
        $s = $this->c->classify('položka bez klíčového slova', 'Ukázka', null, 500.0, self::LIMIT, [
            $this->row(['name' => 'Vypnuté', 'vendor_name_contains' => 'Ukázka',
                'expense_kind' => 'small_asset', 'is_active' => false]),
        ]);

        self::assertNull($s, 'Deaktivované pravidlo se nesmí uplatnit.');
    }

    /**
     * Past z §DM: dodavatel sedí, ale řádek je doprava. Negativní slova přebijí i pravidlo —
     * Alza prodá notebook i dopravu na jedné faktuře.
     */
    public function testNegativeKeywordOverridesTenantRule(): void
    {
        $s = $this->c->classify('doprava zásilky', 'Alza.cz a.s.', null, 150.0, self::LIMIT, [
            $this->row(['name' => 'Alza = drobný majetek', 'vendor_name_contains' => 'Alza', 'expense_kind' => 'small_asset']),
        ]);

        self::assertNotNull($s);
        self::assertSame(ExpenseKind::Service, $s->kind, 'Doprava není drobný majetek ani u Alzy.');
    }

    /** Práh §26/2 ZDP se prosadí i nad pravidlem tenanta — limit je zákon, ne preference. */
    public function testAssetLimitOverridesTenantRule(): void
    {
        $s = $this->c->classify('server', 'Mironet.cz a.s.', null, 120000.0, self::LIMIT, [
            $this->row(['name' => 'Mironet = zboží', 'vendor_name_contains' => 'Mironet', 'expense_kind' => 'small_asset']),
        ]);

        self::assertNotNull($s);
        self::assertSame(ExpenseKind::FixedAsset, $s->kind, 'Nad 80 000 je to DHM, ne drobný majetek.');
        self::assertSame('threshold', $s->source);
    }

    /**
     * Řádek přesně tak, jak ho vrací ExpenseClassificationRuleRepository::cast() —
     * včetně sloupců, které klasifikátor nečte (musí je ignorovat, ne o ně zakopnout).
     *
     * @param array<string,mixed> $over
     * @return array<string,mixed>
     */
    private function row(array $over = []): array
    {
        return array_merge([
            'id' => 1,
            'supplier_id' => 1,
            'name' => 'Pravidlo',
            'vendor_client_id' => null,
            'vendor_name_contains' => null,
            'description_contains' => null,
            'amount_min' => null,
            'amount_max' => null,
            'expense_kind' => 'small_asset',
            'priority' => 100,
            'is_active' => true,
            'hit_count' => 0,
            'last_hit_at' => null,
            'created_by' => null,
            'created_at' => '2026-07-16 00:00:00',
            'updated_at' => '2026-07-16 00:00:00',
        ], $over);
    }
}
