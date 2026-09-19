<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Reports;

use MyInvoice\Service\Accounting\Reports\FinancialStatementService;
use MyInvoice\Service\Accounting\Reports\NetTurnoverSuggestionService;
use MyInvoice\Service\Report\EpoOkecCodebook;
use PHPUnit\Framework\TestCase;

/**
 * Tabulka návrhů výnosů obchodního modelu podle CZ-NACE (§ 1a odst. 2 ZoÚ,
 * § 35 vyhl. 500/2002 Sb.) — čistá statická tabulka, bez DB.
 *
 * Testuje se to, co u návrhu podle číselníku může tiše zestárnout: že pravidla
 * míří na kódy, které v číselníku opravdu jsou a jsou platné, že se nenavrhne
 * řádek, který vyhláška pro daný výkaz nezná, a že se nerozšíří na kódy, kam
 * návrh nepatří (banky, prodej nemovitostí jako zboží).
 */
final class NetTurnoverSuggestionTest extends TestCase
{
    /**
     * @return iterable<string,array{0:string,1:string,2:list<string>,3:list<string>}>
     */
    public static function naceRules(): iterable
    {
        yield 'holding' => ['642000', 'holding', ['IV.', 'V.'], ['III.', 'IV.']];
        yield 'svěřenský fond' => ['643000', 'holding', ['IV.', 'V.'], ['III.', 'IV.']];
        yield 'řízení podniků' => ['701000', 'holding', ['IV.', 'V.'], ['III.', 'IV.']];
        yield 'finanční leasing' => ['649100', 'lending', ['VI.'], ['V.']];
        yield 'ostatní úvěry' => ['649200', 'lending', ['VI.'], ['V.']];
        yield 'zastavárna' => ['649230', 'lending', ['VI.'], ['V.']];
        yield 'cenné papíry na vlastní účet' => ['649910', 'securities_own_account', ['VII.'], ['VI.']];
        yield 'ostatní finanční činnost' => ['649000', 'other_financial', ['VI.', 'VII.'], ['V.', 'VI.']];
        yield 'pronájem nemovitostí' => ['682000', 'rental', ['III.1.'], []];
        yield 'pronájem aut' => ['771100', 'operating_lease', ['III.1.'], []];
        yield 'pronájem strojů' => ['773900', 'operating_lease', ['III.1.'], []];
    }

    /**
     * @param list<string> $byNature
     * @param list<string> $byFunction
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('naceRules')]
    public function testSuggestsRowsForActivity(
        string $code,
        string $reason,
        array $byNature,
        array $byFunction,
    ): void {
        $suggestion = NetTurnoverSuggestionService::suggestFor($code);

        self::assertNotNull($suggestion, "CZ-NACE {$code} má mít návrh.");
        self::assertSame($reason, $suggestion['reason']);
        self::assertSame($byNature, $suggestion['rows']['income_statement']);
        self::assertSame(
            $byFunction,
            $suggestion['rows'][FinancialStatementService::TYPE_PURPOSE],
            'Účelové členění nemá podřádek pro tržby z prodaného majetku, takže se '
            . 'pronajímateli ani leasingu nenavrhuje nic — celé Ostatní provozní '
            . 'výnosy by obrat nadhodnotily.',
        );
    }

    /**
     * @return iterable<string,array{0:string}>
     */
    public static function codesWithoutSuggestion(): iterable
    {
        // Banka účtuje podle vyhlášky č. 501/2002 Sb., ne podle 500/2002 Sb.
        yield 'banka' => ['641900'];
        // Nákup a následný prodej nemovitostí: nemovitost je zboží, tržba je
        // už ve výchozím řádku I./II., přičítat ji podruhé by obrat zdvojilo.
        yield 'obchod s nemovitostmi' => ['681000'];
        yield 'developer' => ['681200'];
        yield 'realitní zprostředkování' => ['683100'];
        yield 'výroba' => ['251100'];
        yield 'maloobchod' => ['471100'];
        yield 'účetnictví' => ['692000'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('codesWithoutSuggestion')]
    public function testLeavesUnrelatedActivitiesAlone(string $code): void
    {
        self::assertNull(
            NetTurnoverSuggestionService::suggestFor($code),
            "CZ-NACE {$code} nemá dostat návrh — výnos jeho modelu je už ve výchozích řádcích I. + II.",
        );
    }

    /**
     * Návrh nesmí mířit na řádek, který vyhláška pro daný výkaz nenabízí:
     * uložit by se nedal a v UI by se nezaškrtl.
     */
    public function testEverySuggestedRowIsAnOfferedOption(): void
    {
        foreach (self::naceRules() as $case) {
            $suggestion = NetTurnoverSuggestionService::suggestFor($case[0]);
            self::assertNotNull($suggestion);
            foreach ($suggestion['rows'] as $type => $codes) {
                $allowed = FinancialStatementService::NET_TURNOVER_EXTRA_ROW_OPTIONS[$type] ?? [];
                self::assertNotSame([], $allowed, "Výkaz {$type} nemá seznam voleb.");
                foreach ($codes as $rowCode) {
                    self::assertContains($rowCode, $allowed, "Řádek {$rowCode} není volbou výkazu {$type}.");
                }
            }
        }
    }

    /**
     * Pravidla se vážou na číselník CZ-NACE, který se mění (poslední revize
     * k 1. 1. 2026). Kdyby kód z tabulky zmizel nebo mu skončila platnost,
     * návrh by se tiše přestal nabízet — a nikdo by se to nedozvěděl.
     */
    public function testRuleCodesStillExistInTheCodebook(): void
    {
        foreach (self::naceRules() as $name => $case) {
            $described = EpoOkecCodebook::describe($case[0]);
            self::assertNotNull($described, "CZ-NACE {$case[0]} ({$name}) v číselníku chybí.");
            self::assertSame('active', $described['status'], "CZ-NACE {$case[0]} ({$name}) už neplatí.");
        }
    }

    /**
     * Kód se páruje na kanonický šestimístný tvar. Sekce 01–09 jsou v číselníku
     * bez vodicí nuly („14800"), takže prefix „77" se nesmí trefit do „01.48".
     */
    public function testMatchesOnCanonicalSixDigitForm(): void
    {
        self::assertNull(
            NetTurnoverSuggestionService::suggestFor('14800'),
            'Kód 01.48.00 nesmí spadnout pod pravidlo pro pronájem (77).',
        );
        self::assertSame(
            'operating_lease',
            NetTurnoverSuggestionService::suggestFor('771100')['reason'] ?? null,
        );
    }
}
