<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Premier;

use MyInvoice\Service\Migration\Premier\PremierBackup;
use MyInvoice\Service\Migration\Premier\PremierSmallAssets;
use MyInvoice\Tests\Fixtures\Premier\SyntheticPremierBackup;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Druh evidence karty majetku podle typu řady (`DOKL_PU.TOK`) a odvození drobného majetku
 * z účtu položky přijatého dokladu, když PREMIER vlastní evidenci nevede.
 */
final class PremierSmallAssetsTest extends TestCase
{
    private string $tmp = '';

    protected function tearDown(): void
    {
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            foreach (scandir($this->tmp) ?: [] as $f) {
                if (is_file($this->tmp . DIRECTORY_SEPARATOR . $f)) {
                    unlink($this->tmp . DIRECTORY_SEPARATOR . $f);
                }
            }
            rmdir($this->tmp);
        }
    }

    /** @return iterable<string,array{0:string,1:array{0:string,1:?string}}> */
    public static function seriesProvider(): iterable
    {
        yield 'HM dlouhodobý hmotný' => ['HM', [PremierSmallAssets::REGISTER_LONG_TERM, 'tangible']];
        yield 'NM dlouhodobý nehmotný' => ['NM', [PremierSmallAssets::REGISTER_LONG_TERM, 'intangible']];
        yield 'DH drobný hmotný' => ['DH', [PremierSmallAssets::REGISTER_SMALL, 'tangible']];
        yield 'DN drobný nehmotný' => ['dn ', [PremierSmallAssets::REGISTER_SMALL, 'intangible']];
        yield 'FM finanční' => ['FM', [PremierSmallAssets::REGISTER_OTHER, null]];
        yield 'LEA leasing' => ['LEA', [PremierSmallAssets::REGISTER_OTHER, null]];
        yield 'OSM ostatní majetek' => ['OSM', [PremierSmallAssets::REGISTER_OTHER, null]];
        yield 'OST ostatní evidence' => ['OST', [PremierSmallAssets::REGISTER_OTHER, null]];
        yield 'REZ rezervy' => ['REZ', [PremierSmallAssets::REGISTER_OTHER, null]];
        yield 'prázdná řada' => ['', [PremierSmallAssets::REGISTER_LONG_TERM, 'tangible']];
        yield 'neznámá řada' => ['XYZ', [PremierSmallAssets::REGISTER_LONG_TERM, 'tangible']];
    }

    /** @param array{0:string,1:?string} $expected */
    #[DataProvider('seriesProvider')]
    public function testRegisterByDefaultSeries(string $series, array $expected): void
    {
        self::assertSame($expected, PremierSmallAssets::fromParts([], [], false)->register($series));
    }

    public function testOwnSeriesFromCatalogueOverridesDefaults(): void
    {
        // Vlastní řada drobného nehmotného majetku a přeznačená výchozí zkratka.
        $policy = PremierSmallAssets::fromParts(['SW' => 44, 'HM' => 43], [], false);
        self::assertSame([PremierSmallAssets::REGISTER_SMALL, 'intangible'], $policy->register('sw'));
        self::assertSame([PremierSmallAssets::REGISTER_SMALL, 'tangible'], $policy->register('HM'));
        self::assertSame([PremierSmallAssets::REGISTER_LONG_TERM, 'intangible'], $policy->register('NM'));
    }

    /** @return iterable<string,array{0:string,1:string,2:?string}> */
    public static function accountProvider(): iterable
    {
        yield 'dr. majetek' => ['501200', 'Spotřeba materiálu - dr. majetek', 'tangible'];
        yield 'drobný hmotný' => ['501300', 'Drobný hmotný majetek', 'tangible'];
        yield 'drobný nehmotný' => ['518300', 'Drobný nehmotný majetek', 'intangible'];
        yield 'dr. nehm. maj.' => ['518310', 'Dr. nehm. maj. - software', 'intangible'];
        yield 'DDHM zkratka' => ['501400', 'Spotřeba DDHM', 'tangible'];
        yield 'DDNM zkratka' => ['518400', 'Náklady DDNM', 'intangible'];
        yield 'obyčejný materiál' => ['501100', 'Spotřeba materiálu', null];
        yield 'služby' => ['518100', 'Ostatní služby', null];
        yield 'jiná třída než 5' => ['022100', 'Drobný majetek v evidenci', null];
        yield 'rozvahový 3xx' => ['314000', 'Zálohy na drobný majetek', null];
    }

    #[DataProvider('accountProvider')]
    public function testAccountKindByChartName(string $code, string $name, ?string $expected): void
    {
        self::assertSame($expected, PremierSmallAssets::accountKind($code, $name));
    }

    public function testExpenseKindThresholdPerUnit(): void
    {
        $policy = PremierSmallAssets::fromParts([], ['501200' => 'tangible', '518300' => 'intangible'], false);
        self::assertSame('small_asset', $policy->expenseKind('501200', 15000.0));
        self::assertSame('small_asset', $policy->expenseKind('501200', PremierSmallAssets::THRESHOLD));
        self::assertSame('material', $policy->expenseKind('501200', 999.99));
        self::assertSame('material', $policy->expenseKind('501200', 500.0));
        self::assertSame('small_intangible', $policy->expenseKind('518300', 3000.0));
        self::assertSame('material', $policy->expenseKind('518300', 800.0));
        // Vrácení (dobropis) má zápornou cenu - rozhoduje velikost.
        self::assertSame('small_asset', $policy->expenseKind('501200', -15000.0));
        self::assertSame('material', $policy->expenseKind('501200', -500.0));
        self::assertNull($policy->expenseKind('518100', 15000.0), 'Jiný účet než drobný majetek.');
        self::assertNull($policy->expenseKind('', 15000.0), 'Položka bez účtu (odpočet zálohy 314).');
    }

    public function testExpenseKindIsNullWhenPremierKeepsOwnEvidence(): void
    {
        $policy = PremierSmallAssets::fromParts([], ['501200' => 'tangible'], true);
        self::assertTrue($policy->hasEvidence);
        self::assertNull($policy->expenseKind('501200', 15000.0));
        self::assertNull($policy->expenseKind('501200', 500.0));
    }

    public function testFromBackupWithoutEvidenceDerivesFromAccounts(): void
    {
        $policy = PremierSmallAssets::fromBackup($this->backup(['small_assets' => true]));
        self::assertFalse($policy->hasEvidence);
        self::assertSame(['501200' => 'tangible'], $policy->accounts());
        self::assertSame('small_asset', $policy->expenseKind('501200', 15000.0));
    }

    public function testFromBackupDetectsEvidenceFromSmallRegisterCard(): void
    {
        $policy = PremierSmallAssets::fromBackup($this->backup(['small_assets' => true, 'small_asset_evidence' => true]));
        self::assertTrue($policy->hasEvidence);
        self::assertNull($policy->expenseKind('501200', 15000.0));
        self::assertSame([PremierSmallAssets::REGISTER_OTHER, null], $policy->register('LEA'));
    }

    public function testFromBackupWithOnlyLongTermAndOtherCardsHasNoEvidence(): void
    {
        $policy = PremierSmallAssets::fromBackup($this->backup(['other_register' => true, 'maj_h' => true]));
        self::assertFalse($policy->hasEvidence, 'Karty řad HM a LEA nejsou evidence drobného majetku.');
    }

    /** @param array<string,bool> $flags */
    private function backup(array $flags): PremierBackup
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'premier_sa_' . bin2hex(random_bytes(5));
        SyntheticPremierBackup::writeDir($this->tmp, false, $flags);
        return PremierBackup::open($this->tmp);
    }
}
