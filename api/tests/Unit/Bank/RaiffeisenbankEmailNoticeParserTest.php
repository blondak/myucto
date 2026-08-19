<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Bank;

use MyInvoice\Service\Bank\EmailNotice\BankEmailNoticeMessage;
use MyInvoice\Service\Bank\EmailNotice\Parser\BankEmailNoticeProvider;
use MyInvoice\Service\Bank\EmailNotice\Parser\RaiffeisenbankEmailNoticeParser;
use PHPUnit\Framework\TestCase;

final class RaiffeisenbankEmailNoticeParserTest extends TestCase
{
    public function testParsesSanitizedNoticeSample(): void
    {
        $text = <<<TEXT
Vážená klientko, vážený kliente,
na účtě byla provedena následující příchozí platba:
Datum a čas
01. 06. 2026 10:15
Na účet
123456789/5500Firma Test s.r.o.
Částka v měně účtu
+1.234,56 CZK
Z účtu
987654321/5500Plátce Demo s.r.o.
Variabilní symbol
2606001
Konstantní symbol
308
Zpráva pro příjemce
Faktura 2606001
Disponibilní zůstatek po pohybu
+99.999,99 CZK
TEXT;

        $message = new BankEmailNoticeMessage(
            uid: 1,
            messageId: '<sanitized-sample@rb.cz>',
            date: new \DateTimeImmutable('2026-06-01 10:15:00'),
            sender: 'Raiffeisenbank <info@rb.cz>',
            subject: 'Pohyb na účtě',
            text: $text,
            raw: $text,
        );

        $parser = new RaiffeisenbankEmailNoticeParser();
        $provider = $parser->defaultProvider();
        self::assertInstanceOf(BankEmailNoticeProvider::class, $provider);
        self::assertTrue($parser->supports($message, $provider));

        $parsed = $parser->parse($message, $provider);

        self::assertSame('2606001', $parsed->variableSymbol);
        self::assertSame(1234.56, $parsed->amount);
        self::assertSame('CZK', $parsed->currency);
        self::assertSame('2026-06-01', $parsed->postedAt);
        self::assertSame('123456789/5500', $parsed->recipientAccount);
        self::assertSame('987654321', $parsed->counterpartyAccount);
        self::assertSame('5500', $parsed->counterpartyBank);
        self::assertSame('Plátce Demo s.r.o.', $parsed->counterpartyName);
        self::assertSame('308', $parsed->constantSymbol);
        self::assertSame('Faktura 2606001', $parsed->message);
        self::assertSame(99999.99, $parsed->balance);
    }

    public function testParsesOutgoingNoticeWithOwnAccountInFromField(): void
    {
        $text = <<<TEXT
Vážená klientko, vážený kliente,
na účtě byla provedena následující odchozí platba:
Datum a čas
02. 08. 2026 16:07
Z účtu
1000000005/5500Firma Test s.r.o.
Částka v měně účtu
-1.234,56 CZK
Typ pohybu
Odchozí úhrada
Na účet
2000000018/0800Příjemce Demo s.r.o.
Variabilní symbol
2608001
Konstantní symbol
308
Zpráva pro příjemce
Faktura 2608001
Disponibilní zůstatek po pohybu
+98.765,43 CZK
TEXT;

        $message = new BankEmailNoticeMessage(
            uid: 2,
            messageId: '<sanitized-outgoing@rb.cz>',
            date: new \DateTimeImmutable('2026-08-02 16:07:00'),
            sender: 'Raiffeisenbank <info@rb.cz>',
            subject: 'Pohyb na účtě',
            text: $text,
            raw: $text,
        );

        $parser = new RaiffeisenbankEmailNoticeParser();
        $provider = $parser->defaultProvider();
        self::assertInstanceOf(BankEmailNoticeProvider::class, $provider);

        $parsed = $parser->parse($message, $provider);

        self::assertSame('2608001', $parsed->variableSymbol);
        self::assertSame(-1234.56, $parsed->amount);
        self::assertSame('CZK', $parsed->currency);
        self::assertSame('2026-08-02', $parsed->postedAt);
        self::assertSame('1000000005/5500', $parsed->recipientAccount);
        self::assertSame('2000000018', $parsed->counterpartyAccount);
        self::assertSame('0800', $parsed->counterpartyBank);
        self::assertSame('Příjemce Demo s.r.o.', $parsed->counterpartyName);
        self::assertSame('308', $parsed->constantSymbol);
        self::assertSame('Faktura 2608001', $parsed->message);
        self::assertSame(98765.43, $parsed->balance);
    }

    public function testParsesOutgoingCardTransactionWithoutVariableSymbolOrCounterpartyAccount(): void
    {
        $text = <<<TEXT
Vážená klientko, vážený kliente,
na účtě byla provedena následující odchozí karetní transakce:
Datum a čas
19. 08. 2026 04:41
Účet
1000000005/5500Firma Test s.r.o.
Debetní karta
123456XXXXXX7890
Částka v měně účtu
-1.234,56 CZK
Původní částka a měna
-49,99 EUR
Kurz
24,70
Kategorie pohybu
Platba kartou
Typ pohybu
Platba kartou
Konstantní symbol
1178
Detaily
DEMO SOFTWARE; PRAHA; CZE
Disponibilní zůstatek po pohybu
+98.765,43 CZK
TEXT;

        $message = new BankEmailNoticeMessage(
            uid: 3,
            messageId: '<sanitized-card@rb.cz>',
            date: new \DateTimeImmutable('2026-08-19 04:41:00'),
            sender: 'Raiffeisenbank <info@rb.cz>',
            subject: 'Pohyb na účtě',
            text: $text,
            raw: $text,
        );

        $parser = new RaiffeisenbankEmailNoticeParser();
        $provider = $parser->defaultProvider();
        self::assertInstanceOf(BankEmailNoticeProvider::class, $provider);
        self::assertTrue($parser->supports($message, $provider));

        $parsed = $parser->parse($message, $provider);

        self::assertSame('', $parsed->variableSymbol);
        self::assertSame(-1234.56, $parsed->amount);
        self::assertSame('CZK', $parsed->currency);
        self::assertSame('2026-08-19', $parsed->postedAt);
        self::assertSame('1000000005/5500', $parsed->recipientAccount);
        self::assertNull($parsed->counterpartyAccount);
        self::assertNull($parsed->counterpartyBank);
        self::assertSame('DEMO SOFTWARE; PRAHA; CZE', $parsed->counterpartyName);
        self::assertSame('1178', $parsed->constantSymbol);
        self::assertNull($parsed->message);
        self::assertSame(98765.43, $parsed->balance);
    }
}
