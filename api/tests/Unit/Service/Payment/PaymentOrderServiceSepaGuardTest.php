<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Payment;

use MyInvoice\Service\Payment\PaymentOrderService;
use PHPUnit\Framework\TestCase;

/**
 * Regresní test na MEDIUM nález (adversariální review): `download()` neměl pro
 * SEPA formát žádnou měnovou pojistku (ABO větev `toAboInput()` CZK-guard MÁ).
 * Bez ní přímé API volání `GET .../download?format=sepa` na CZK dávce (mimo FE
 * gate, který SEPA tlačítko zobrazí jen pro EUR účet plátce) by vygenerovalo
 * XML s `Ccy="CZK"` + `SvcLvl/Cd=SEPA`, které banka odmítne nebo zpracuje špatně.
 *
 * `toSepaInput()` je privátní mapovací metoda bez závislosti na konstruktorových
 * službách (čte jen vstupní `$view` pole) — `PaymentOrderService` má finální
 * konstruktorové závislosti (Connection, CrpDphClient, repositories), které
 * PHPUnit nemůže mockovat (final třídy), proto testujeme guard přímo přes
 * reflexi na instanci bez zavolaného konstruktoru (žádná služba se nepoužije).
 */
final class PaymentOrderServiceSepaGuardTest extends TestCase
{
    private function callToSepaInput(array $view): array
    {
        $ref = new \ReflectionClass(PaymentOrderService::class);
        $service = $ref->newInstanceWithoutConstructor();
        $method = $ref->getMethod('toSepaInput');
        return $method->invoke($service, $view);
    }

    public function testRejectsCzkOrderForSepaExport(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SEPA export je možný jen pro EUR příkazy.');

        $this->callToSepaInput($this->viewWith('CZK'));
    }

    public function testAcceptsEurOrderForSepaExport(): void
    {
        $input = $this->callToSepaInput($this->viewWith('EUR'));

        self::assertSame('EUR', $input['currency']);
        self::assertSame('CZ1801000000001000000005', $input['payer_iban']);
    }

    public function testRejectsLowercaseCzkCurrencyToo(): void
    {
        // Case-insensitivita guardu (mirror AboPaymentOrderWriteru: strtoupper()).
        $this->expectException(\RuntimeException::class);
        $this->callToSepaInput($this->viewWith('czk'));
    }

    /**
     * @return array<string,mixed>
     */
    private function viewWith(string $currency): array
    {
        return [
            'id'           => 1,
            'currency'     => $currency,
            'payment_date' => '2026-07-15',
            'payer'        => ['iban' => 'CZ1801000000001000000005', 'bic' => null],
            'supplier'     => ['company_name' => 'Testovací s.r.o.'],
            'items'        => [
                [
                    'payee_name' => 'X', 'iban' => 'DE89370400440532013000', 'bic' => null,
                    'amount' => 100.0, 'variable_symbol' => null, 'message' => null,
                ],
            ],
        ];
    }
}
