<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Bank;

use MyInvoice\Service\Bank\EmailNotice\BankEmailNoticeMessage;
use MyInvoice\Service\Bank\EmailNotice\Parser\AirBankBankEmailNoticeParser;
use MyInvoice\Service\Bank\EmailNotice\Parser\BankEmailNoticeProvider;
use PHPUnit\Framework\TestCase;

final class AirBankBankEmailNoticeParserTest extends TestCase
{
    public function testParsesIncomingNoticeWithVariableSymbol(): void
    {
        $body = <<<TEXT
Dobrý den,

zůstatek na účtu Podnikatelský účet CZK číslo 1000000005/3030 se zvýšil o částku 1,00 CZK. Dostupný zůstatek k 24.07.2026 v 15:20 je 2,00 CZK.

Pro úplnost uvádíme detaily této úhrady:

Příchozí úhrada z účtu Firma Demo s.r.o. číslo 2000145399/0300
Částka: 1,00 CZK
Datum zaúčtování: 24.07.2026
Variabilní symbol: 202600009
Kód transakce: 100000000001


Nechceme, aby Vás naše upozornění jakkoliv obtěžovala.
TEXT;

        $parser = new AirBankBankEmailNoticeParser();
        $message = $this->message($body, 'Zvýšení zůstatku na účtu Podnikatelský účet CZK');
        $provider = $this->provider($parser);

        self::assertTrue($parser->supports($message, $provider));

        $parsed = $parser->parse($message, $provider);

        self::assertSame('202600009', $parsed->variableSymbol);
        self::assertSame(1.0, $parsed->amount);
        self::assertSame('CZK', $parsed->currency);
        self::assertSame('2026-07-24', $parsed->postedAt);
        self::assertSame('1000000005/3030', $parsed->recipientAccount);
        self::assertSame('2000145399', $parsed->counterpartyAccount);
        self::assertSame('0300', $parsed->counterpartyBank);
        self::assertSame('Firma Demo s.r.o.', $parsed->counterpartyName);
        self::assertSame('100000000001', $parsed->bankRef);
        self::assertSame(2.0, $parsed->balance);
    }

    public function testParsesIncomingNoticeWithoutVariableSymbol(): void
    {
        $body = <<<TEXT
zůstatek na účtu Podnikatelský účet CZK číslo 1000000005/3030 se zvýšil o částku 1,00 CZK. Dostupný zůstatek k 24.07.2026 v 15:20 je 1,00 CZK.

Příchozí úhrada z účtu Jan Novák číslo 2000145399/0300
Částka: 1,00 CZK
Datum zaúčtování: 24.07.2026
Kód transakce: 100000000002
TEXT;

        $parser = new AirBankBankEmailNoticeParser();
        $parsed = $parser->parse(
            $this->message($body, 'Zvýšení zůstatku na účtu Podnikatelský účet CZK'),
            $this->provider($parser),
        );

        self::assertSame('', $parsed->variableSymbol);
        self::assertSame(1.0, $parsed->amount);
        self::assertSame('1000000005/3030', $parsed->recipientAccount);
        self::assertSame('100000000002', $parsed->bankRef);
    }

    public function testParsesEurIncomingNotice(): void
    {
        $body = <<<TEXT
zůstatek na účtu Podnikatelský účet EUR číslo 2000145399/3030 se zvýšil o částku 12,50 EUR. Dostupný zůstatek k 01.08.2026 v 10:00 je 100,00 EUR.

Příchozí úhrada z účtu Client Demo číslo 1111222233/0100
Částka: 12,50 EUR
Datum zaúčtování: 01.08.2026
Variabilní symbol: 2608001
Kód transakce: 100000000003
TEXT;

        $parser = new AirBankBankEmailNoticeParser();
        $parsed = $parser->parse(
            $this->message($body, 'Zvýšení zůstatku na účtu Podnikatelský účet EUR'),
            $this->provider($parser),
        );

        self::assertSame('2608001', $parsed->variableSymbol);
        self::assertSame(12.5, $parsed->amount);
        self::assertSame('EUR', $parsed->currency);
        self::assertSame('2000145399/3030', $parsed->recipientAccount);
    }

    public function testParsesOutgoingNoticeAsNegativeAmount(): void
    {
        $body = <<<TEXT
zůstatek na účtu Podnikatelský účet CZK číslo 1000000005/3030 se snížil o částku 500,00 CZK. Dostupný zůstatek k 02.08.2026 v 09:00 je 9 500,00 CZK.

Odchozí úhrada na účet Dodavatel Demo číslo 19-2000145399/0800
Částka: 500,00 CZK
Datum zaúčtování: 02.08.2026
Variabilní symbol: 2026001
Kód transakce: 100000000004
TEXT;

        $parser = new AirBankBankEmailNoticeParser();
        $message = $this->message($body, 'Snížení zůstatku na účtu Podnikatelský účet CZK');
        $provider = $this->provider($parser);

        self::assertTrue($parser->supports($message, $provider));

        $parsed = $parser->parse($message, $provider);

        self::assertSame('2026001', $parsed->variableSymbol);
        self::assertSame(-500.0, $parsed->amount);
        self::assertSame('CZK', $parsed->currency);
        self::assertSame('1000000005/3030', $parsed->recipientAccount);
        self::assertSame('19-2000145399', $parsed->counterpartyAccount);
        self::assertSame('0800', $parsed->counterpartyBank);
    }

    public function testSupportsDiacriticFoldedSubjectAndBody(): void
    {
        $body = <<<TEXT
zustatek na uctu Podnikatelsky ucet CZK cislo 1000000005/3030 se zvysil o castku 1,00 CZK.
Castka: 1,00 CZK
Datum zauctovani: 24.07.2026
Variabilni symbol: 1
TEXT;

        $parser = new AirBankBankEmailNoticeParser();
        $message = $this->message($body, 'Zvyseni zustatku na uctu Podnikatelsky ucet CZK');
        self::assertTrue($parser->supports($message, $this->provider($parser)));
    }

    public function testUsesSubjectDirectionWhenBodyQuotesOppositeNotice(): void
    {
        $body = <<<TEXT
zůstatek na účtu Podnikatelský účet CZK číslo 1000000005/3030 se zvýšil o částku 1,00 CZK.
Příchozí úhrada z účtu Firma Demo s.r.o. číslo 2000145399/0300
Částka: 1,00 CZK
Datum zaúčtování: 24.07.2026
Kód transakce: 100000000005

----- Původní zpráva -----
zůstatek na účtu Podnikatelský účet CZK číslo 1000000005/3030 se snížil o částku 50,00 CZK.
Odchozí úhrada na účet Dodavatel Demo číslo 19-2000145399/0800
Částka: 50,00 CZK
Datum zaúčtování: 23.07.2026
Kód transakce: 100000000000
TEXT;

        $parser = new AirBankBankEmailNoticeParser();
        $message = $this->message($body, 'FW: Zvýšení zůstatku na účtu Podnikatelský účet CZK');
        $provider = $this->provider($parser);

        self::assertTrue($parser->supports($message, $provider));
        self::assertSame(1.0, $parser->parse($message, $provider)->amount);
    }

    public function testRejectsSpoofedSenderDomain(): void
    {
        $parser = new AirBankBankEmailNoticeParser();
        $message = new BankEmailNoticeMessage(
            uid: 1,
            messageId: '<spoof@evil.com>',
            date: new \DateTimeImmutable('2026-07-24 15:21:00'),
            sender: 'Air Bank <attacker@airbank.cz.evil.com>',
            subject: 'Zvýšení zůstatku na účtu',
            text: "zůstatek na účtu číslo 1000000005/3030 se zvýšil o částku 1,00 CZK.\nČástka: 1,00 CZK",
            raw: '',
        );
        self::assertFalse($parser->supports($message, $this->provider($parser)));
    }

    public function testThrowsWithoutAmount(): void
    {
        $body = "zůstatek na účtu číslo 1000000005/3030 se zvýšil.\nDatum zaúčtování: 24.07.2026";
        $parser = new AirBankBankEmailNoticeParser();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('částku');
        $parser->parse(
            $this->message($body, 'Zvýšení zůstatku na účtu'),
            $this->provider($parser),
        );
    }

    private function message(string $body, string $subject): BankEmailNoticeMessage
    {
        return new BankEmailNoticeMessage(
            uid: 1,
            messageId: '<sanitized-sample@airbank.cz>',
            date: new \DateTimeImmutable('2026-07-24 15:21:00'),
            sender: 'info@airbank.cz',
            subject: $subject,
            text: $body,
            raw: $body,
        );
    }

    private function provider(AirBankBankEmailNoticeParser $parser): BankEmailNoticeProvider
    {
        $provider = $parser->defaultProvider();
        self::assertInstanceOf(BankEmailNoticeProvider::class, $provider);
        return $provider;
    }
}
