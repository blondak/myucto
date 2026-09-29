<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Payment;

use MyInvoice\Service\Payment\AboPaymentOrderWriter;
use MyInvoice\Service\Payment\PaymentOrderService;
use PHPUnit\Framework\TestCase;

/**
 * Vratka odběrateli s účtem naučeným z GPC výpisu (`0000002000145305`): příjemce
 * se nabízí v kanonickém tvaru a ABO z dávky jde stáhnout. Neplatný účet vrací
 * RuntimeException (akce z ní dělá 422), ne InvalidArgumentException, která
 * propadla až do Slim Application Error 500.
 *
 * Služba má finální závislosti, které nejde mockovat; mapovací metody čtou jen
 * vstup, proto je voláme reflexí na instanci bez konstruktoru.
 */
final class PaymentOrderServiceAccountFormatTest extends TestCase
{
    private \ReflectionClass $ref;
    private PaymentOrderService $service;

    protected function setUp(): void
    {
        $this->ref = new \ReflectionClass(PaymentOrderService::class);
        $this->service = $this->ref->newInstanceWithoutConstructor();
        $this->ref->getProperty('abo')->setValue($this->service, new AboPaymentOrderWriter());
    }

    public function testRefundPayeeFromGpcAccountIsCanonical(): void
    {
        $payee = $this->call('czechPayee', '0000002000145305', '0100', null);
        self::assertSame('2000145305', $payee['account_number']);
        self::assertSame('0100', $payee['bank_code']);

        $prefixed = $this->call('czechPayee', '0000192000145399/0800', null, null);
        self::assertSame('19-2000145399', $prefixed['account_number']);
        self::assertSame('0800', $prefixed['bank_code']);
    }

    public function testAboDownloadAcceptsGpcAccount(): void
    {
        $file = $this->call('render', $this->view('0000002000145305'), 'abo', 'prikaz');

        self::assertStringContainsString(
            "\r\n000000-2000145305 000000012300 2026001 01000000 0000000000 AV:2026001\r\n",
            $file['bytes'],
        );
    }

    public function testInvalidAccountIsRuntimeExceptionForHttp422(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('není platný český účet');
        $this->call('render', $this->view('12345678901234567'), 'abo', 'prikaz');
    }

    private function call(string $method, mixed ...$args): mixed
    {
        return $this->ref->getMethod($method)->invoke($this->service, ...$args);
    }

    /** @return array<string,mixed> */
    private function view(string $account): array
    {
        return [
            'id'           => 1,
            'currency'     => 'CZK',
            'payment_date' => '2026-07-15',
            'payer'        => ['account_number' => '1000000005', 'bank_code' => '0800', 'iban' => null, 'bic' => null],
            'supplier'     => ['company_name' => 'Testovaci s.r.o.', 'abo_client_number' => null],
            'items'        => [[
                'account_number'  => $account,
                'bank_code'       => '0100',
                'amount'          => 123.00,
                'variable_symbol' => '2026001',
                'constant_symbol' => null,
                'specific_symbol' => null,
                'message'         => null,
            ]],
        ];
    }
}
