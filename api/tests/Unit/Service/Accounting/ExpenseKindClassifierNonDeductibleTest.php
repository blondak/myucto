<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Accounting;

use MyInvoice\Service\Accounting\Expense\ExpenseKind;
use MyInvoice\Service\Accounting\Expense\ExpenseKindClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Automatizace 2026 — detekce osobních / daňově NEUZNATELNÝCH výdajů (§25 ZDP) v klasifikátoru.
 * Vzory vycházejí z praxe (např. optika/brýle → 528). Konzervativně: jen jasné vzory a VŽDY jen
 * návrh ke kontrole — účet se nemění, směrování na 528/513 je věcí seed pravidel.
 */
final class ExpenseKindClassifierNonDeductibleTest extends TestCase
{
    private const LIMIT = 80000.0;

    private ExpenseKindClassifier $c;

    protected function setUp(): void
    {
        $this->c = new ExpenseKindClassifier();
    }

    #[DataProvider('personalTexts')]
    public function testOpticsAndGlassesFlaggedNonDeductibleButNeverAutoApplied(string $text): void
    {
        $s = $this->c->classify($text, 'Oční optika s.r.o.', null, 8500.0, self::LIMIT);

        self::assertNotNull($s);
        self::assertTrue($s->nonDeductible, "„{$text}" . '" má nést příznak nedaňového (§25 ZDP).');
        self::assertSame(ExpenseKind::Service, $s->kind, 'Osobní výdaj není majetek ani materiál.');
        self::assertFalse($s->isAutoApplicable(), 'Nedaňový vzor je jen návrh — nikdy neúčtuje sám.');
        self::assertNull($s->accountCode, 'Pure klasifikátor konkrétní nedaňový účet neurčuje.');
        self::assertArrayHasKey('non_deductible', $s->toArray());
        self::assertTrue($s->toArray()['non_deductible']);
    }

    /** @return iterable<array{string}> */
    public static function personalTexts(): iterable
    {
        yield ['dioptrické brýle'];
        yield ['Optika - brýlové obruby'];
        yield ['Optika faktura'];
    }

    public function testSmartwatchIsWeakNonDeductibleSuggestion(): void
    {
        $s = $this->c->classify('Garmin Fenix 7', 'Prodejce hodinek s.r.o.', null, 19900.0, self::LIMIT);

        self::assertNotNull($s);
        self::assertTrue($s->nonDeductible);
        self::assertFalse($s->isAutoApplicable(), 'Chytré hodinky můžou být i firemní — jen ke kontrole.');
    }

    /**
     * Konzervativní hranice: masáž/wellness ZÁMĚRNĚ NENÍ tvrdý nedaňový vzor — reálný nález 2024
     * vede masážní křeslo na 501 (drobný majetek). Nedaňový příznak se tu nesmí objevit.
     */
    public function testMassageWellnessIsNotANonDeductibleTrigger(): void
    {
        // Masáž/wellness ZÁMĚRNĚ mimo nedaňový seznam — reálný nález 2024 vede masážní křeslo na
        // 501 (drobný majetek). Klasifikátor „křeslo" nezná, takže nechá řádek nezařazený (§DM
        // „Nehádej") — a hlavně ho NEoznačí falešně za nedaňový osobní výdaj.
        $s = $this->c->classify('masážní křeslo', 'Elektro e-shop a.s.', null, 15000.0, self::LIMIT);

        if ($s !== null) {
            self::assertFalse($s->nonDeductible, 'Masáž/wellness nesmí spustit nedaňový příznak.');
        } else {
            self::assertNull($s);
        }
    }

    /** Běžná služba nesmí být falešně označena za nedaňovou. */
    public function testOrdinaryServiceIsNotFlagged(): void
    {
        $s = $this->c->classify('Vyúčtování služeb - telefonní tarif', 'Mobilní operátor a.s.', null, 1475.0, self::LIMIT);

        self::assertNotNull($s);
        self::assertFalse($s->nonDeductible);
    }
}
