<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\StereoNx;

use MyInvoice\Service\Invoice\InvoiceMath;
use MyInvoice\Service\Migration\StereoNx\StereoNxException;
use MyInvoice\Service\Migration\StereoNx\StereoNxPurchaseRecap;
use MyInvoice\Service\Migration\StereoNx\StereoNxVat;
use PHPUnit\Framework\TestCase;

final class StereoNxPurchaseRecapTest extends TestCase
{
    private function vatRow(bool $reverse = false): array
    {
        $row = ['TypDPH' => 'SYNTHETIC', 'Plneni' => 'P', 'Kraceni' => false];
        foreach (['Z', 'S', 'T', '0'] as $slot) {
            foreach (['Zaklad', 'Dan'] as $kind) {
                $row['E19Radek' . $kind . $slot] = $slot === '0' ? 'NE' : ($reverse ? ($slot === 'Z' ? '12' : '13') : ($slot === 'Z' ? '40' : '41'));
                $row['E19Radek' . $kind . $slot . 'x'] = $slot === '0' || !$reverse ? '' : ($slot === 'Z' ? '43' : '44');
            }
        }
        return $row;
    }

    private function header(array $overrides = []): array
    {
        return $overrides + ['Agenda' => 'PF', 'TypDokladu' => 'F', 'Stornovano' => false,
            'Mena' => 'Kč', 'Kurz' => 1, 'KurzMn' => 1, 'Zalohy' => 0,
            'CenySDPH' => false, 'ZpracovatDPH' => true, 'TypDPH' => 'SYNTHETIC',
            'Text' => 'Syntetický popis dodavatele', 'ZaklDPHz' => 100.0, 'DPHz' => 21.0, 'SazbaDPHz' => 21.0,
            'ZaklDPHs' => 0.0, 'DPHs' => 0.0, 'SazbaDPHs' => 12.0,
            'ZaklDPHt' => 0.0, 'DPHt' => 0.0, 'SazbaDPHt' => 0.0, 'BezDane' => 0.0,
            'Zaokrouhleni' => 0.0, 'Celkem' => 121.0];
    }

    private function mapper(bool $reverse = false): StereoNxPurchaseRecap
    {
        return new StereoNxPurchaseRecap(new StereoNxVat([$this->vatRow($reverse)]));
    }

    public function testDomesticRecapPreservesDescriptionAndEveryRate(): void
    {
        $plan = $this->mapper()->plan($this->header(['ZaklDPHs' => 200.0, 'DPHs' => 24.0, 'Celkem' => 345.0]));
        self::assertCount(2, $plan['items']);
        self::assertSame(['40', '41'], array_column($plan['items'], 'vat_classification_code'));
        self::assertSame('Syntetický popis dodavatele', $plan['items'][0]['description']);
        self::assertSame(45.0, $plan['total_vat']);
        self::assertSame(345.0, $plan['source_total_with_vat']);
        self::assertSame([], $plan['review_codes']);
    }

    public function testGrossPricingSurvivesTheApplicationCalculator(): void
    {
        $plan = $this->mapper()->plan($this->header(['CenySDPH' => true]));
        self::assertTrue($plan['prices_include_vat']);
        self::assertSame(121.0, $plan['items'][0]['unit_price_without_vat']);
        $computed = InvoiceMath::compute($plan['items'], $plan['reverse_charge'], $plan['prices_include_vat']);
        self::assertSame(100.0, $computed['items'][0]['base']);
        self::assertSame(21.0, $computed['items'][0]['vat']);
        self::assertSame(121.0, $computed['items'][0]['with']);
    }

    public function testSelfAssessmentDoesNotIncreasePayableOrChargeVatAgain(): void
    {
        $plan = $this->mapper(true)->plan($this->header(['CenySDPH' => true, 'Celkem' => 100.0]));
        self::assertTrue($plan['reverse_charge']);
        self::assertTrue($plan['prices_include_vat']);
        self::assertSame('24', $plan['items'][0]['vat_classification_code']);
        self::assertSame([12, 43], $plan['items'][0]['source_return_lines']);
        self::assertSame(21.0, $plan['items'][0]['source_self_assessed_vat']);
        self::assertSame(0.0, $plan['total_vat']);
        self::assertSame(100.0, $plan['source_total_with_vat']);
        $computed = InvoiceMath::compute($plan['items'], true, true);
        self::assertSame(100.0, $computed['items'][0]['with']);
        self::assertSame(21.0, $computed['items'][0]['rate']);
    }

    /**
     * Přihrádka bez daně mimo přiznání na dokladu se samovyměřením: bez kódu by ji evidence
     * DPH podle příznaku `reverse_charge` na hlavičce zdanila jako samovyměření.
     */
    public function testZeroRateSlotOnSelfAssessedDocumentIsOutsideScope(): void
    {
        $plan = $this->mapper(true)->plan($this->header(['BezDane' => 50.0, 'Celkem' => 150.0]));
        self::assertTrue($plan['reverse_charge']);
        self::assertSame(['24', 'mimo'], array_column($plan['items'], 'vat_classification_code'));

        $domestic = $this->mapper()->plan($this->header(['BezDane' => 50.0, 'Celkem' => 171.0]));
        self::assertSame(['40', null], array_column($domestic['items'], 'vat_classification_code'), 'Tuzemský doklad bez samovyměření zůstává beze změny.');
    }

    public function testUnassignedFlagsRemainExplicitAndRequireReview(): void
    {
        $plan = $this->mapper(true)->plan($this->header(['CenySDPH' => null, 'ZpracovatDPH' => null, 'Celkem' => 100.0]));
        self::assertNull($plan['prices_include_vat']);
        self::assertNull($plan['source_vat_participation']);
        self::assertTrue($plan['requires_draft']);
        self::assertSame(['price_mode_unassigned', 'vat_participation_unassigned'], $plan['review_codes']);
    }

    public function testUnassignedVatParticipationRequiresDraftEvenWithKnownNetPrices(): void
    {
        $plan = $this->mapper()->plan($this->header(['ZpracovatDPH' => null]));
        self::assertFalse($plan['prices_include_vat']);
        self::assertNull($plan['source_vat_participation']);
        self::assertTrue($plan['requires_draft']);
        self::assertSame(['vat_participation_unassigned'], $plan['review_codes']);
        self::assertSame(121.0, $plan['source_total_with_vat']);
    }

    public function testExplicitVatExclusionRequiresReviewBeforeAnyVatPosting(): void
    {
        $plan = $this->mapper()->plan($this->header(['ZpracovatDPH' => false]));
        self::assertFalse($plan['source_vat_participation']);
        self::assertTrue($plan['requires_draft']);
        self::assertSame(['vat_participation_disabled'], $plan['review_codes']);
    }

    public function testRoundingIsKeptSeparateFromTaxRows(): void
    {
        $plan = $this->mapper()->plan($this->header(['Zaokrouhleni' => -0.1, 'Celkem' => 120.9]));
        self::assertSame(121.0, $plan['items'][0]['total_with_vat']);
        self::assertSame(-0.1, $plan['rounding']);
        self::assertSame(120.9, $plan['source_total_with_vat']);
    }

    public function testUnexplainedDifferenceIsNotSilentlyTreatedAsRounding(): void
    {
        $this->expectException(StereoNxException::class);
        $this->expectExceptionMessage('nesouhlasí');
        $this->mapper()->plan($this->header(['Celkem' => 122.0]));
    }

    public function testUnexpectedSelfAssessedTaxRequiresDraftInsteadOfSilentRecalculation(): void
    {
        $plan = $this->mapper(true)->plan($this->header(['DPHz' => 31.0, 'Celkem' => 100.0]));
        self::assertTrue($plan['requires_draft']);
        self::assertContains('self_assessment_amount_mismatch', $plan['review_codes']);
        self::assertSame(31.0, $plan['items'][0]['source_self_assessed_vat']);
        self::assertSame(100.0, $plan['source_total_with_vat']);
    }

    public function testOneCentSelfAssessedTaxDifferenceRequiresDraft(): void
    {
        $plan = $this->mapper(true)->plan($this->header(['DPHz' => 21.01, 'Celkem' => 100.0]));
        self::assertTrue($plan['requires_draft']);
        self::assertContains('self_assessment_amount_mismatch', $plan['review_codes']);
    }

    public function testMissingAmountDoesNotBecomeZero(): void
    {
        $this->expectException(StereoNxException::class);
        $this->mapper()->plan($this->header(['DPHz' => null]));
    }

    public function testUnknownPriceModeWithChargedVatIsRejected(): void
    {
        $this->expectException(StereoNxException::class);
        $this->mapper()->plan($this->header(['CenySDPH' => null]));
    }

    public function testAdvancesAreNotConvertedToOrdinaryItems(): void
    {
        $this->expectException(StereoNxException::class);
        $this->mapper()->plan($this->header(['Zalohy' => 10.0]));
    }

    public function testCurrencyCannotBeInferredFromAnAmount(): void
    {
        $this->expectException(StereoNxException::class);
        $this->mapper()->plan($this->header(['Mena' => 'EUR']));
    }

    public function testVatCodeNameDoesNotDetermineClassification(): void
    {
        $row = $this->vatRow(true);
        $row['TypDPH'] = 'P'; // běžně tuzemský název, ale rozhodují řádky
        $vat = new StereoNxVat([$row]);
        self::assertSame('24', $vat->purchase('P', 'z')['code']);
    }

    public function testUnknownVatLineIsNotCastToZero(): void
    {
        $row = $this->vatRow();
        $row['E19RadekZakladZ'] = 'unknown';
        $this->expectException(StereoNxException::class);
        (new StereoNxVat([$row]))->purchase('SYNTHETIC', 'z');
    }

    public function testReducedDeductionUsesExistingCodeAndSeparateDeductionFlag(): void
    {
        $row = $this->vatRow();
        $row['Kraceni'] = true;
        $result = (new StereoNxVat([$row]))->purchase('SYNTHETIC', 'z');
        self::assertSame('40', $result['code']);
        self::assertSame('reduced', $result['deduction']);
    }
}
