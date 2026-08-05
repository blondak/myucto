<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\InvoiceImportService;
use MyInvoice\Service\Oss\OssDerivationReason;
use MyInvoice\Service\Oss\OssItemDecision;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * SOUDRŽNOST DOKLADU (§ H1) — smí týž doklad ležet ZÁROVEŇ v OSS podání a v tuzemském
 * přiznání, aniž by o tom někdo řekl?
 *
 * Naměřený stav: jeden Pohoda XML, JEDEN polský spotřebitel bez DIČ, rekapitulace sedící
 * na položky (takže ani `file_issues` nic nehlásí) — položka 23 % skončila v OSS sekci PL
 * a položka 12 % na ř. 1 českého přiznání. Status `created`, NULA varování, nula příznaků.
 * Obě rozhodnutí jsou přitom z pohledu ŘÁDKU správná: 23 % v ČR neplatí a 12 % v Polsku
 * neplatí. {@see \MyInvoice\Service\Oss\OssItemDeriver} o ostatních řádcích téhož dokladu
 * neví nic, takže rozpor NEMŮŽE vidět — je to vlastnost DOKLADU.
 *
 * Testuje se proto přímo pravidlo nad hotovým plánem položek, ne celý import: rozpor
 * vzniká i zaniká na jediném místě (konec `planItems()`), je čistě rozhodovací a nemá
 * žádný databázový vstup. Řetěz od XML po výkazy — tedy že doklad SKUTEČNĚ leží v obou
 * přiznáních a že se tím rozhodnutí nemění — drží
 * {@see \MyInvoice\Tests\Integration\Import\OssDocumentCoherenceTest}.
 *
 * Podoba plánu se schválně nepíše ručně: `oss` blok každé položky vyrábí
 * {@see OssItemDecision::toItemColumns()}, tedy TÝŽ kód, kterým ho plní import. Ručně
 * složené pole by se s ním rozešlo při první změně sloupců a test by pak zeleně tvrdil
 * něco o struktuře, která už nikde nevzniká.
 */
final class InvoiceImportDocumentCoherenceTest extends TestCase
{
    /**
     * Zavolá kontrolu soudržnosti nad plánem položek.
     *
     * Metoda je private a instanční, ale nesahá na žádný stav instance (pracuje jen nad
     * předaným plánem a nad statickými helpery), takže se dá zavolat nad objektem bez
     * konstruktoru — a test tím nezávisí na DI kontejneru ani na databázi.
     *
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    private function flag(array $plan, string $domestic = 'CZ'): array
    {
        $service = (new ReflectionClass(InvoiceImportService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($service, 'flagContradictoryDocument');
        $method->invokeArgs($service, [&$plan, $domestic]);

        return $plan;
    }

    /**
     * Plán tak, jak ho vrací `planItems()` — jen s poli, na kterých kontrola stojí.
     *
     * @param list<array{rate:float, oss:array<string,mixed>}> $rows
     * @return array<string,mixed>
     */
    private function plan(array $rows, int $manualReview = 0): array
    {
        return [
            'rows' => array_map(
                static fn (array $row): array => [
                    'vat_rate_snapshot' => $row['rate'],
                    'oss' => $row['oss'],
                ],
                $rows,
            ),
            'warnings' => [],
            'oss_manual_review' => $manualReview,
        ];
    }

    /**
     * OSS řádek tak, jak ho po derivaci zapíše import.
     *
     * @return array{rate:float, oss:array<string,mixed>}
     */
    private function ossRow(float $rate, string $country = 'PL'): array
    {
        return [
            'rate' => $rate,
            'oss' => OssItemDecision::oss($country, 'standard', 'goods')->toItemColumns(),
        ];
    }

    /**
     * Tuzemský řádek. `RateMatchesDomesticOnly` je přesně ten důvod, se kterým do tuzemské
     * větve chodí druhá půlka naměřeného dokladu: číselník sazbu v zemi dodavatele potvrdil
     * a ve státě spotřeby vyloučil.
     *
     * @return array{rate:float, oss:array<string,mixed>}
     */
    private function domesticRow(
        float $rate,
        OssDerivationReason $reason = OssDerivationReason::RateMatchesDomesticOnly,
    ): array {
        return [
            'rate' => $rate,
            'oss' => OssItemDecision::notApplicable($reason)->toItemColumns(),
        ];
    }

    /** @param array<string,mixed> $plan */
    private function flags(array $plan): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['oss']['oss_needs_manual_review'],
            $plan['rows'],
        );
    }

    // ── Případ A: doklad se rozpadl do dvou přiznání ─────────────────────────

    /**
     * NAMĚŘENÝ PŘÍPAD A, doslova: 23 % (OSS, stát spotřeby PL) + 12 % (tuzemsky zdaněné)
     * na jednom dokladu jednoho polského spotřebitele.
     *
     * Doklad se NEODMÍTÁ — plnění s místem plnění v tuzemsku a zásilka do jiného členského
     * státu se na jedné faktuře sejít můžou a uživatel nemá jak cizí export rozdělit. Tichý
     * ale zůstat nesmí, protože v drtivé většině případů je to špatná sazba na jednom řádku.
     */
    public function testDocumentSplitBetweenOssAndDomesticIsFlaggedOnBothSides(): void
    {
        $plan = $this->flag($this->plan([
            $this->ossRow(23.0),
            $this->domesticRow(12.0),
        ]));

        self::assertCount(1, $plan['warnings'], 'Rozpor je vlastnost dokladu → jedno varování za doklad.');
        $warning = $plan['warnings'][0];
        self::assertStringContainsString('Doklad si protiřečí', $warning);
        self::assertStringContainsString('stát spotřeby PL', $warning, 'Hláška musí pojmenovat obě strany rozporu.');
        self::assertStringContainsString('sazbou 12 %', $warning);
        self::assertStringContainsString('země dodavatele CZ', $warning);
        self::assertStringContainsString('K RUČNÍMU POSOUZENÍ', $warning);

        self::assertSame([1, 1], $this->flags($plan),
            'Označit se musí OBĚ strany: náhled OSS podání čte výhradně řádky s oss_applicable = 1, '
                . 'takže příznak jen na tuzemské straně by po zavření reportu nikde nesvítil.');
        self::assertSame(2, $plan['oss_manual_review'],
            'Čítač reportu musí sedět s tím, co se zapíše — jinak uživatel hledá v datech míň '
                . 'řádků, než kolik jich je označených.');
    }

    /**
     * TÁŽ KOMBINACE SE ZÁKLADNÍ TUZEMSKOU SAZBOU — 23 % + 21 %. Review ji naměřila zvlášť,
     * protože 21 % je sazba, kterou v tuzemské větvi čeká klasifikace '1' a s ní ř. 1
     * přiznání: doklad tedy vykazuje polskou daň v OSS a zároveň českou na ř. 1.
     *
     * Pro pravidlo je to týž případ, a právě to je tvrzení — rozpor nesmí být navázaný na
     * konkrétní procento (sníženou sazbu), jinak by nejnápadnější varianta zůstala tichá.
     */
    public function testSplitWithTheDomesticStandardRateIsFlaggedToo(): void
    {
        $plan = $this->flag($this->plan([
            $this->ossRow(23.0),
            $this->domesticRow(21.0),
        ]));

        self::assertCount(1, $plan['warnings']);
        self::assertStringContainsString('sazbou 21 %', $plan['warnings'][0]);
        self::assertSame([1, 1], $this->flags($plan));
        self::assertSame(2, $plan['oss_manual_review']);
    }

    /** Doklad do víc států spotřeby najednou — hláška je musí vyjmenovat, ne vzít první. */
    public function testWarningNamesEveryConsumptionCountryAndEveryDomesticRate(): void
    {
        $plan = $this->flag($this->plan([
            $this->ossRow(23.0, 'SK'),
            $this->ossRow(23.0, 'PL'),
            $this->domesticRow(21.0),
            $this->domesticRow(12.0),
        ]));

        self::assertStringContainsString('stát spotřeby PL, SK', $plan['warnings'][0]);
        self::assertStringContainsString('sazbou 12, 21 %', $plan['warnings'][0]);
        self::assertSame([1, 1, 1, 1], $this->flags($plan));
        self::assertSame(4, $plan['oss_manual_review']);
    }

    // ── Protipóly: kde se hlásit NESMÍ ───────────────────────────────────────

    /**
     * PROTIPÓL PŘÍPADU A: druhý řádek je OSVOBOZENÝ (0 % — poštovné, zaokrouhlení, sleva).
     *
     * Rozpor netvoří: osvobozené plnění se vykazuje BEZ DANĚ, takže tu není žádná „tuzemsky
     * odvedená daň" proti dani odvedené ve státě spotřeby. Kdyby se hlásil, dostal by
     * varování skoro každý OSS doklad — nulový řádek nese kdejaká faktura — a hláška by
     * u migrace 1 670 dokladů okamžitě zevšedněla.
     */
    public function testZeroRatedLineIsNotAContradiction(): void
    {
        $plan = $this->flag($this->plan([
            $this->ossRow(23.0),
            $this->domesticRow(0.0, OssDerivationReason::ZeroRate),
        ]));

        self::assertSame([], $plan['warnings'], 'Osvobozený řádek není druhé přiznání.');
        self::assertSame([0, 0], $this->flags($plan));
        self::assertSame(0, $plan['oss_manual_review']);
    }

    /**
     * REGRESE BĚŽNÉHO PROVOZU: čistě tuzemský doklad (dvě sazby, žádný OSS řádek) se
     * nesmí hnout. Falešný poplach je tu dražší než mlčení — uživatel, kterému kontrola
     * hlásí rozpor na každé druhé faktuře, ji přestane číst dřív, než dojde k té jedné,
     * kde o něco jde.
     */
    public function testPurelyDomesticDocumentIsUntouched(): void
    {
        $plan = $this->flag($this->plan([
            $this->domesticRow(21.0, OssDerivationReason::ClientDomestic),
            $this->domesticRow(12.0, OssDerivationReason::ClientDomestic),
            $this->domesticRow(0.0, OssDerivationReason::ZeroRate),
        ]));

        self::assertSame([], $plan['warnings']);
        self::assertSame([0, 0, 0], $this->flags($plan));
        self::assertSame(0, $plan['oss_manual_review']);
    }

    /** A druhá strana téhož: doklad celý v OSS je taky soudržný, i s víc sazbami. */
    public function testPurelyOssDocumentIsUntouched(): void
    {
        $plan = $this->flag($this->plan([
            $this->ossRow(23.0),
            $this->ossRow(8.0),
        ]));

        self::assertSame([], $plan['warnings']);
        self::assertSame([0, 0], $this->flags($plan));
        self::assertSame(0, $plan['oss_manual_review']);
    }

    /** Doklad o jediné položce nemá s čím být v rozporu — ani na jedné straně. */
    public function testSingleRowDocumentCannotContradictItself(): void
    {
        foreach ([$this->ossRow(23.0), $this->domesticRow(21.0)] as $row) {
            $plan = $this->flag($this->plan([$row]));
            self::assertSame([], $plan['warnings']);
            self::assertSame([0], $this->flags($plan));
        }
    }

    /** Prázdný plán (všechny položky odmítnuté) nesmí kontrolu shodit. */
    public function testEmptyPlanIsSafe(): void
    {
        $plan = $this->flag($this->plan([]));

        self::assertSame([], $plan['warnings']);
        self::assertSame(0, $plan['oss_manual_review']);
    }

    // ── Čítač reportu ────────────────────────────────────────────────────────

    /**
     * Řádek, který příznak nese UŽ Z DERIVACE (sazba platí v obou zemích → místo plnění
     * z ní neplyne), se do čítače nesmí započítat podruhé. Čítač reportu má odpovídat
     * počtu OZNAČENÝCH ŘÁDKŮ, ne počtu důvodů, proč jsou označené.
     */
    public function testAlreadyFlaggedRowIsNotCountedTwice(): void
    {
        $ambiguous = [
            'rate' => 21.0,
            'oss' => OssItemDecision::oss(
                'NL',
                'standard',
                'services',
                [],
                OssDerivationReason::RateAmbiguousDomesticOrConsumer,
            )->toItemColumns(),
        ];
        self::assertSame(1, $ambiguous['oss']['oss_needs_manual_review'],
            'Předpoklad testu: nejednoznačný řádek je označený už derivací.');

        // `planItems()` ho v té chvíli má započítaný — plán tedy do kontroly vstupuje s 1.
        $plan = $this->flag($this->plan([$ambiguous, $this->domesticRow(12.0)], manualReview: 1));

        self::assertSame([1, 1], $this->flags($plan));
        self::assertSame(2, $plan['oss_manual_review'], 'Dva označené řádky = dva, ne tři.');
    }
}
