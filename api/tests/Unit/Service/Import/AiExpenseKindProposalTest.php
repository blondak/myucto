<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Accounting\Expense\ExpenseKind;
use MyInvoice\Service\Accounting\Expense\ExpenseKindClassifier;
use MyInvoice\Service\Accounting\Expense\ExpenseKindSuggestion;
use MyInvoice\Service\Import\AiExpenseKindProposal;
use PHPUnit\Framework\TestCase;

/**
 * §DM „AI import" — návrh druhu nákladu z AI extrakce.
 *
 * Testy jsou pure: nikde se nevolá poskytovatel AI, vstupem je hotové `items[]` tak, jak
 * ho model vrátí. Klasifikátor je reálný (je bez DB), aby se vrstvení testovalo doopravdy.
 */
final class AiExpenseKindProposalTest extends TestCase
{
    private const LIMIT = 80000.0;

    private ExpenseKindClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new ExpenseKindClassifier();
    }

    // ── normalizace + validace toho, co vrátil model ────────────────────────

    public function testValidKindIsAcceptedWithReasonAndAiSource(): void
    {
        $p = AiExpenseKindProposal::fromAiItem([
            'expense_kind' => 'small_asset',
            'expense_kind_confidence' => 0.9,
            'expense_kind_reasoning' => 'řádek uvádí kávovar DeLonghi',
        ], 12000.0, self::LIMIT);

        self::assertNotNull($p);
        self::assertSame(ExpenseKind::SmallAsset, $p->kind);
        self::assertSame('ai', $p->source);
        self::assertStringContainsString('kávovar DeLonghi', $p->reason);
    }

    /** Halucinovaná hodnota mimo enum se zahodí — enum nekontroluje jen JSON schema. */
    public function testUnknownKindIsRejected(): void
    {
        self::assertNull(AiExpenseKindProposal::fromAiItem([
            'expense_kind' => 'pojistne',
            'expense_kind_confidence' => 1.0,
        ], 1000.0, self::LIMIT));
    }

    public function testMissingOrNullKindIsRejected(): void
    {
        self::assertNull(AiExpenseKindProposal::fromAiItem([], 1000.0, self::LIMIT));
        self::assertNull(AiExpenseKindProposal::fromAiItem([
            'expense_kind' => null,
            'expense_kind_confidence' => 1.0,
        ], 1000.0, self::LIMIT));
    }

    /** Model sám říká „nevím" → nenabízíme nic. */
    public function testZeroConfidenceIsRejected(): void
    {
        self::assertNull(AiExpenseKindProposal::fromAiItem([
            'expense_kind' => 'small_asset',
            'expense_kind_confidence' => 0,
        ], 1000.0, self::LIMIT));
    }

    /**
     * Nejdůležitější vlastnost celého AI importu: návrh z AI se NIKDY nesmí použít sám.
     * Tlumení 0,40 drží jistotu pod AUTO_THRESHOLD i při modelem tvrzené jistotě 1,0.
     */
    public function testAiProposalIsNeverAutoApplicable(): void
    {
        $p = AiExpenseKindProposal::fromAiItem([
            'expense_kind' => 'small_asset',
            'expense_kind_confidence' => 1.0,
        ], 5000.0, self::LIMIT);

        self::assertNotNull($p);
        self::assertFalse($p->isAutoApplicable());
        self::assertSame(0.4, $p->confidence);
        self::assertLessThan(ExpenseKindClassifier::AUTO_THRESHOLD, $p->confidence);
        self::assertFalse($p->toArray()['auto']);
    }

    public function testConfidenceIsClampedAndDamped(): void
    {
        $over = AiExpenseKindProposal::fromAiItem(
            ['expense_kind' => 'service', 'expense_kind_confidence' => 42],
            100.0,
            self::LIMIT,
        );
        self::assertNotNull($over);
        self::assertSame(0.4, $over->confidence);

        $half = AiExpenseKindProposal::fromAiItem(
            ['expense_kind' => 'service', 'expense_kind_confidence' => 0.5],
            100.0,
            self::LIMIT,
        );
        self::assertNotNull($half);
        self::assertSame(0.2, $half->confidence);

        self::assertNull(AiExpenseKindProposal::fromAiItem(
            ['expense_kind' => 'service', 'expense_kind_confidence' => -1],
            100.0,
            self::LIMIT,
        ));
    }

    /** §26/2 ZDP vynucujeme sami — model zná leda limit ze svých trénovacích dat. */
    public function testSmallAssetOverLimitIsForcedToFixedAsset(): void
    {
        $p = AiExpenseKindProposal::fromAiItem([
            'expense_kind' => 'small_asset',
            'expense_kind_confidence' => 1.0,
            'expense_kind_reasoning' => 'notebook',
        ], 95000.0, self::LIMIT);

        self::assertNotNull($p);
        self::assertSame(ExpenseKind::FixedAsset, $p->kind);
        self::assertStringContainsString('§26/2 ZDP', $p->reason);
    }

    /** Dobropis má záporný řádek, věcně jde o tutéž věc → práh se počítá z absolutní ceny. */
    public function testThresholdUsesAbsolutePriceForCreditNotes(): void
    {
        $p = AiExpenseKindProposal::fromAiItem([
            'expense_kind' => 'small_asset',
            'expense_kind_confidence' => 1.0,
        ], -95000.0, self::LIMIT);

        self::assertNotNull($p);
        self::assertSame(ExpenseKind::FixedAsset, $p->kind);
    }

    // ── vrstvení: deterministický klasifikátor je první, AI až fallback ─────

    public function testDeterministicSuggestionWinsOverAi(): void
    {
        $deterministic = new ExpenseKindSuggestion(ExpenseKind::Material, 0.9, 'text obsahuje „toner"', 'keyword');
        $p = AiExpenseKindProposal::resolve(
            $deterministic,
            ['expense_kind' => 'small_asset', 'expense_kind_confidence' => 1.0],
            500.0,
            self::LIMIT,
        );

        self::assertSame($deterministic, $p);
    }

    public function testAiIsUsedOnlyWhenClassifierIsSilent(): void
    {
        $description = 'Konferenční stolek z masivu';
        self::assertNull(
            $this->classifier->classify($description, 'IKEA', 1, 3000.0, self::LIMIT),
            'předpoklad testu: klasifikátor tenhle text nezná',
        );

        $p = AiExpenseKindProposal::resolve(
            $this->classifier->classify($description, 'IKEA', 1, 3000.0, self::LIMIT),
            ['expense_kind' => 'small_asset', 'expense_kind_confidence' => 0.8],
            3000.0,
            self::LIMIT,
        );

        self::assertNotNull($p);
        self::assertSame('ai', $p->source);
        self::assertSame(ExpenseKind::SmallAsset, $p->kind);
    }

    /**
     * Reálná past (§DM): Alza prodá notebook i dopravu na jedné faktuře. Negativní klíčové
     * slovo je v klasifikátoru, takže halucinace „drobný majetek" se k uživateli nedostane —
     * vrstvení tu funguje jako veto zadarmo.
     */
    public function testNegativeKeywordVetoesHallucinatedSmallAsset(): void
    {
        $description = 'Prodloužená záruka k notebooku';
        $p = AiExpenseKindProposal::resolve(
            $this->classifier->classify($description, 'Alza.cz a.s.', 7, 3000.0, self::LIMIT),
            ['expense_kind' => 'small_asset', 'expense_kind_confidence' => 1.0],
            3000.0,
            self::LIMIT,
        );

        self::assertNotNull($p);
        self::assertNotSame(ExpenseKind::SmallAsset, $p->kind);
        self::assertSame(ExpenseKind::Service, $p->kind);
        self::assertSame('keyword', $p->source);
    }

    /** PHM je materiál, ne drobný majetek — a klasifikátor to ví jistěji než model. */
    public function testFuelStaysMaterialEvenIfAiSaysSmallAsset(): void
    {
        $p = AiExpenseKindProposal::resolve(
            $this->classifier->classify('PHM Natural 95', 'NC AUTO s.r.o.', 3, 1500.0, self::LIMIT),
            ['expense_kind' => 'small_asset', 'expense_kind_confidence' => 1.0],
            1500.0,
            self::LIMIT,
        );

        self::assertNotNull($p);
        self::assertSame(ExpenseKind::Material, $p->kind);
    }

    // ── sloučení řádků (collapse) ──────────────────────────────────────────

    public function testMergeKeepsKindWhenAllLinesAgree(): void
    {
        $merged = AiExpenseKindProposal::mergeForCollapsedLine([
            new ExpenseKindSuggestion(ExpenseKind::Material, 0.9, 'text obsahuje „phm"', 'keyword'),
            new ExpenseKindSuggestion(ExpenseKind::Material, 0.6, 'text obsahuje „phm"', 'keyword'),
        ]);

        self::assertNotNull($merged);
        self::assertSame(ExpenseKind::Material, $merged->kind);
        // Jistota sloučeného řádku = nejnižší z původních; shoda z nich silnější důkaz nedělá.
        self::assertSame(0.6, $merged->confidence);
    }

    /**
     * Reálná past: faktura z Alzy (notebook + doprava) sloučená na 1 řádek dle rekapitulace.
     * Přenést na součtový řádek druh prvního řádku by z CELÉ částky udělalo drobný majetek.
     */
    public function testMergeDropsKindWhenLinesDisagree(): void
    {
        self::assertNull(AiExpenseKindProposal::mergeForCollapsedLine([
            new ExpenseKindSuggestion(ExpenseKind::SmallAsset, 0.9, 'text obsahuje „notebook"', 'keyword'),
            new ExpenseKindSuggestion(ExpenseKind::Service, 0.9, 'text obsahuje „doprava"', 'keyword'),
        ]));
    }

    /** Byť jediný neurčený řádek → o součtu nevíme dost, takže nic netvrdíme. */
    public function testMergeDropsKindWhenAnyLineIsUnknown(): void
    {
        self::assertNull(AiExpenseKindProposal::mergeForCollapsedLine([
            new ExpenseKindSuggestion(ExpenseKind::SmallAsset, 0.9, 'text obsahuje „notebook"', 'keyword'),
            null,
        ]));
        self::assertNull(AiExpenseKindProposal::mergeForCollapsedLine([null, null]));
        self::assertNull(AiExpenseKindProposal::mergeForCollapsedLine([]));
    }

    public function testMergeOfSingleLineKeepsItVerbatim(): void
    {
        $only = new ExpenseKindSuggestion(ExpenseKind::SmallAsset, 0.9, 'text obsahuje „tablet"', 'keyword');
        self::assertSame($only, AiExpenseKindProposal::mergeForCollapsedLine([$only]));
    }

    // ── doručení návrhu uživateli ──────────────────────────────────────────

    public function testWarningTextListsLinesWithReasonAndConfidence(): void
    {
        $text = AiExpenseKindProposal::warningText(
            [1 => new ExpenseKindSuggestion(ExpenseKind::SmallAsset, 0.4, 'AI z dokladu ⇒ Drobný majetek: řádek uvádí tablet', 'ai')],
            [['description' => 'Doprava'], ['description' => 'Tablet Galaxy Tab S9']],
        );

        self::assertNotNull($text);
        self::assertStringContainsString('řádek 2', $text);
        self::assertStringContainsString('Tablet Galaxy Tab S9', $text);
        self::assertStringContainsString('Drobný majetek', $text);
        self::assertStringContainsString('40 %', $text);
        // Uživatel musí vědět, že se nic nenastavilo a má to potvrdit.
        self::assertStringContainsString('NENÍ nastaven', $text);
    }

    public function testWarningTextIsNullWithoutProposals(): void
    {
        self::assertNull(AiExpenseKindProposal::warningText([], [['description' => 'Cokoli']]));
    }

    /**
     * Popis položky ve varování jde přes strip PII, ne přes slovníkový whitelist
     * ({@see \MyInvoice\Service\Ai\AiPayloadSanitizer::sanitizeItemText()}) — značka a typ
     * musí zůstat čitelné, jinak návrh nedává smysl.
     */
    public function testWarningTextKeepsVendorFreeTextButStripsPii(): void
    {
        $text = AiExpenseKindProposal::warningText(
            [0 => new ExpenseKindSuggestion(ExpenseKind::Material, 0.4, 'AI z dokladu ⇒ Materiál', 'ai')],
            [['description' => 'Toner HP 26X černý objednal servis@example.com']],
        );

        self::assertNotNull($text);
        self::assertStringContainsString('Toner HP 26X černý', $text);
        self::assertStringNotContainsString('servis@example.com', $text);
    }

    /** Zdůvodnění od modelu je taky volný text — PII v něm nesmí projít do varování. */
    public function testReasoningIsStrippedOfPii(): void
    {
        $p = AiExpenseKindProposal::fromAiItem([
            'expense_kind' => 'small_asset',
            'expense_kind_confidence' => 0.8,
            'expense_kind_reasoning' => 'objednal jan.novak@example.com, tel. +420 777 123 456',
        ], 5000.0, self::LIMIT);

        self::assertNotNull($p);
        self::assertStringNotContainsString('jan.novak@example.com', $p->reason);
        self::assertStringNotContainsString('777 123 456', $p->reason);
    }

    /** Nestringové zdůvodnění (model vrátí objekt) nesmí shodit import. */
    public function testNonStringReasoningIsTolerated(): void
    {
        $p = AiExpenseKindProposal::fromAiItem([
            'expense_kind' => 'service',
            'expense_kind_confidence' => 0.8,
            'expense_kind_reasoning' => ['nesmysl'],
        ], 100.0, self::LIMIT);

        self::assertNotNull($p);
        self::assertStringContainsString('bez zdůvodnění', $p->reason);
    }
}
