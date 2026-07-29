<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Return;

use MyInvoice\Service\Tax\Return\DppoReturnCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Paušální výdaj na dopravu (§ 24 odst. 2 písm. zt) — explicitní druh položky místo
 * rozpoznávání podle TEXTU.
 *
 * Matice daní z příjmů to vedla jako riziko: „paušál dopravy se rozpoznává heuristikou
 * nad textem položky — přehlédnutá položka = chybný základ, systém mlčí." Základ daně
 * to ve skutečnosti nemění (rozpad je jen prezentační), ale mění to ŘÁDKY přiznání:
 * paušál patří na ř. 112/170 a add-back PHM na ř. 40, ne na obecné ř. 62/162.
 *
 * Heuristika je nespolehlivá v obou směrech — „krácený výdaj na automobil dle zt"
 * neprojde, kdežto libovolný text s oběma slovy projde. Proto má přednost `kind`
 * a heuristika zůstává jen jako fallback pro dřív zadané položky.
 */
final class DppoFlatRateTravelKindTest extends TestCase
{
    private DppoReturnCalculator $calc;

    /** @var array<string,mixed> */
    private array $c = [
        'corporate_tax_rate' => 0.21,
        'donation_cap_po_pct' => 0.30,
        'disabled_employee_credit' => 18000,
        'disabled_employee_credit_severe' => 60000,
        'advance_threshold_low' => 30000,
        'advance_threshold_high' => 150000,
        'rounding_base_po' => 1000,
    ];

    protected function setUp(): void
    {
        $this->calc = new DppoReturnCalculator();
    }

    /** @param array<string,mixed> $inputs @return array<string,mixed> */
    private function calc(array $inputs): array
    {
        return $this->calc->compute(['vh' => 1000000.0], $inputs, $this->c);
    }

    /** @param list<array<string,mixed>> $lines */
    private static function line(array $lines, int $n): float
    {
        foreach ($lines as $l) {
            if ((int) $l['line'] === $n) {
                return (float) $l['value'];
            }
        }
        self::fail('Řádek ' . $n . ' chybí.');
    }

    /**
     * Explicitní `kind` zafunguje i u textu, který by heuristika NEPOZNALA — právě kvůli
     * takovým položkám vznikl.
     */
    public function testExplicitKindWorksWithoutKeywords(): void
    {
        $r = $this->calc([
            'manual_decrease_items' => [
                ['text' => 'Krácený výdaj na automobil dle zt', 'amount' => 45000,
                 'kind' => DppoReturnCalculator::KIND_FLAT_RATE_TRAVEL],
            ],
        ]);

        self::assertSame(45000.0, self::line($r['lines'], 112), 'Paušál patří na ř. 112.');
        self::assertSame(0.0, self::line($r['lines'], 162), 'Ne na obecný ř. 162.');
    }

    /** Add-back PHM s explicitním druhem míří na ř. 40, ne na ř. 62. */
    public function testExplicitKindOnIncreaseGoesToLine40(): void
    {
        $r = $this->calc([
            'manual_increase_items' => [
                ['text' => 'Vyloučení nákladů na benzín', 'amount' => 15000,
                 'kind' => DppoReturnCalculator::KIND_FLAT_RATE_TRAVEL],
            ],
        ]);

        self::assertSame(15000.0, self::line($r['lines'], 40));
        self::assertSame(0.0, self::line($r['lines'], 62));
    }

    /** Dřív zadané položky bez `kind` musí dál fungovat podle textu — zpětná kompatibilita. */
    public function testLegacyTextHeuristicStillWorks(): void
    {
        $r = $this->calc([
            'manual_decrease_items' => [['text' => 'Paušál na dopravu 9× 5000', 'amount' => 45000]],
        ]);

        self::assertSame(45000.0, self::line($r['lines'], 112));
    }

    /**
     * Explicitně JINÝ druh heuristiku vypíná. Bez toho by text „paušál na dopravu"
     * přebil vědomé zařazení účetní — automatika by přehlasovala člověka.
     */
    public function testExplicitOtherKindDisablesTextHeuristic(): void
    {
        $r = $this->calc([
            'manual_decrease_items' => [
                ['text' => 'Paušál na dopravu — POZOR, jiný titul', 'amount' => 45000, 'kind' => 'other'],
            ],
        ]);

        self::assertSame(0.0, self::line($r['lines'], 112));
        self::assertSame(45000.0, self::line($r['lines'], 162), 'Zůstane na obecném řádku.');
    }

    /**
     * Položka, která o dopravě mluví, ale za paušál označená není, se HLÁSÍ. Tiše ji
     * vykázat na obecném řádku by dalo špatně vyplněné přiznání, o kterém se nikdo
     * nedozví — a to je přesně ta chyba, kterou matice popisovala.
     */
    public function testAmbiguousTravelItemIsReported(): void
    {
        $r = $this->calc([
            'manual_decrease_items' => [['text' => 'Náklady na vozidlo dle zt', 'amount' => 45000]],
        ]);

        $w = implode("\n", $r['warnings']);
        self::assertStringContainsString('zmiňují dopravu', $w);
        self::assertStringContainsString('Náklady na vozidlo dle zt', $w);
    }

    /** Položka bez vztahu k dopravě se nehlásí — jinak by varování zšedla. */
    public function testUnrelatedItemIsNotReported(): void
    {
        $r = $this->calc([
            'manual_decrease_items' => [['text' => 'Oprava výnosů minulých let', 'amount' => 45000]],
        ]);

        self::assertStringNotContainsString('zmiňují dopravu', implode("\n", $r['warnings']));
    }

    /** Správně označená položka se jako nejednoznačná NEHLÁSÍ. */
    public function testTaggedItemIsNotReportedAsAmbiguous(): void
    {
        $r = $this->calc([
            'manual_decrease_items' => [
                ['text' => 'Paušál na dopravu', 'amount' => 45000,
                 'kind' => DppoReturnCalculator::KIND_FLAT_RATE_TRAVEL],
            ],
        ]);

        self::assertStringNotContainsString('zmiňují dopravu', implode("\n", $r['warnings']));
    }
}
