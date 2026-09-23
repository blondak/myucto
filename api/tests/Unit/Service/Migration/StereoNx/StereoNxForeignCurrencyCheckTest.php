<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\StereoNx;

use MyInvoice\Service\Migration\StereoNx\StereoNxForeignCurrencyCheck;
use MyInvoice\Service\Migration\StereoNx\StereoNxVat;
use PHPUnit\Framework\TestCase;

final class StereoNxForeignCurrencyCheckTest extends TestCase
{
    private function vat(): StereoNxVat
    {
        $sale = ['TypDPH' => 'SALE', 'Plneni' => 'U', 'Kraceni' => false];
        $purchase = ['TypDPH' => 'PURCHASE', 'Plneni' => 'P', 'Kraceni' => false];
        foreach (['Z', 'S', 'T', '0'] as $slot) {
            foreach (['Zaklad', 'Dan'] as $kind) {
                foreach (['', 'x'] as $suffix) {
                    $key = 'E19Radek' . $kind . $slot . $suffix;
                    $sale[$key] = $slot === '0' && $kind === 'Zaklad' && $suffix === '' ? '20' : '';
                    $purchase[$key] = $slot === 'Z' ? ($suffix === '' ? '12' : '43') : '';
                }
            }
        }
        return new StereoNxVat([$sale, $purchase]);
    }

    private function issued(): array
    {
        return [
            ['Agenda' => 'VF', 'TypDokladu' => 'F', 'TypDPH' => 'SALE', 'Stornovano' => false,
                'ZpracovatDPH' => true, 'CenySDPH' => false, 'Zalohy' => 0,
                'Celkem' => 93.0, 'CelkemVlastni' => 2987.16, 'Kurz' => 25.1, 'KurzMn' => 1,
                'BezDane' => 2987.16, 'ZaklDPHz' => 0, 'ZaklDPHs' => 0, 'ZaklDPHt' => 0,
                'DPHz' => 0, 'DPHs' => 0, 'DPHt' => 0],
            [['TypSazby' => '0', 'Mnozstvi' => 6, 'JednCenaC' => 15.5,
                'JednCena' => 497.86, 'ZakladDPH' => 2987.16, 'CelkemDPH' => 0,
                'Stornovano' => false, 'Zaloha' => false, 'ZalohaProforma' => false, 'ProcSlevy' => 0]],
        ];
    }

    private function purchase(): array
    {
        return [
            ['Agenda' => 'PF', 'TypDokladu' => 'F', 'TypDPH' => 'PURCHASE', 'Stornovano' => false,
                'ZpracovatDPH' => true, 'CenySDPH' => false, 'Zalohy' => 0,
                'Celkem' => 80.0, 'CelkemVlastni' => 2008.0, 'Kurz' => 25.1, 'KurzMn' => 1,
                'BezDane' => 0, 'ZaklDPHz' => 2569.60, 'ZaklDPHs' => 0, 'ZaklDPHt' => 0,
                'DPHz' => 539.62, 'DPHs' => 0, 'DPHt' => 0, 'SazbaDPHz' => 21],
            [['TypSazby' => 'Z', 'Mnozstvi' => 5, 'JednCenaC' => 16.0,
                'JednCena' => 513.92, 'ZakladDPH' => 2569.60, 'CelkemDPH' => 539.62,
                'Stornovano' => false, 'Zaloha' => false, 'ZalohaProforma' => false, 'ProcSlevy' => 0]],
        ];
    }

    public function testVerifiedZeroTaxForeignSaleExplainsExchangeAmountMismatch(): void
    {
        [$header, $items] = $this->issued();
        self::assertSame(['foreign_currency_rate_mismatch'], StereoNxForeignCurrencyCheck::reviewCodes($header, $items, $this->vat()));
    }

    public function testVerifiedReverseChargePurchaseExplainsDifferentDomesticTaxBase(): void
    {
        [$header, $items] = $this->purchase();
        self::assertSame(['foreign_currency_vat_base_mismatch'], StereoNxForeignCurrencyCheck::reviewCodes($header, $items, $this->vat()));
    }

    public function testMatchingBaseNeedsNoExtraReason(): void
    {
        [$header, $items] = $this->issued();
        $header['CelkemVlastni'] = 2334.30;
        $header['BezDane'] = 2334.30;
        $items[0]['JednCena'] = 389.05;
        $items[0]['ZakladDPH'] = 2334.30;
        self::assertSame([], StereoNxForeignCurrencyCheck::reviewCodes($header, $items, $this->vat()));
    }

    public function testUnknownVatParticipationOrUnrelatedBaseCannotAssertCause(): void
    {
        [$header, $items] = $this->purchase();
        $header['ZpracovatDPH'] = null;
        self::assertSame([], StereoNxForeignCurrencyCheck::reviewCodes($header, $items, $this->vat()));
        $header['ZpracovatDPH'] = true;
        $header['CelkemVlastni'] = 2000.0;
        self::assertSame([], StereoNxForeignCurrencyCheck::reviewCodes($header, $items, $this->vat()));
    }
}
