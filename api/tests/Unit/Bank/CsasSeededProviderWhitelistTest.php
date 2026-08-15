<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Bank;

use MyInvoice\Service\Bank\EmailNotice\BankEmailNoticeMessage;
use MyInvoice\Service\Bank\EmailNotice\Parser\BankEmailNoticeProvider;
use MyInvoice\Service\Bank\EmailNotice\Parser\RegexBankEmailNoticeParser;
use PHPUnit\Framework\TestCase;

/**
 * Seedovaný globální provider České spořitelny (`0098`) měl prázdný
 * `sender_whitelist`. Když se parser stal fail-closed (`1289`, security report R3),
 * stal se z něj řádek, který NIKDY nic nenaparsuje — `supports()` skončí na prázdném
 * whitelistu dřív, než se podívá na tělo, a scan končí na `parse_failed`. Uživatel to
 * navíc neuměl opravit: definici globálního provideru `saveProvider()` needituje.
 *
 * Migrace `1373` whitelist doplňuje. Test hlídá obojí: že migrace doplňuje jen
 * PRÁZDNOU hodnotu (re-run safe a nepřepíše nastavení operátora) a že hodnota,
 * kterou doplňuje, providera skutečně odblokuje — tedy pustí avízo od ČS a dál
 * nepustí podvrh od kohokoli jiného.
 */
final class CsasSeededProviderWhitelistTest extends TestCase
{
    private const MIGRATION = __DIR__ . '/../../../../db/migrations/1373_bank_email_notice_csas_sender_whitelist.sql';

    /** Tělo avíza ČS podle seedovaných patternů z `0098`. */
    private const NOTICE_BODY = <<<TEXT
    Informace o transakci

    Směr platby: příchozí
    Číslo účtu: 6509175329/0800
    Číslo účtu protistrany: 2801836907/2010
    Částka v měně účtu: 10 000,00 Kč
    Variabilní symbol: 123456789
    Konstantní symbol: 0
    TEXT;

    public function testMigrationFillsOnlyEmptyWhitelistOfGlobalCsasProvider(): void
    {
        $sql = $this->migrationStatements();

        self::assertStringContainsString("supplier_id IS NULL", $sql);
        self::assertStringContainsString("code = 'ceska-sporitelna'", $sql);
        // Bez téhle podmínky by druhý běh (a hlavně upgrade instalace, kde si operátor
        // whitelist mezitím doplnil sám) přepsal cizí hodnotu naší.
        self::assertStringContainsString(
            "(sender_whitelist IS NULL OR TRIM(sender_whitelist) = '')",
            $sql,
            'Migrace musí doplňovat jen prázdný whitelist — jinak není re-run safe.',
        );
        // Zapnout zpět smí jen řádek, který 1289 sama vypnula a od té doby na něj
        // nikdo nesáhl; vědomě vypnutý provider zůstává vypnutý.
        self::assertStringContainsString('enabled = 1', $sql);
        self::assertStringContainsString('AND enabled = 0', $sql);
        self::assertStringContainsString(
            "(subject_pattern IS NULL OR TRIM(subject_pattern) = '')",
            $sql,
            'Podmínka musí odpovídat tvaru řádku, který vypnula 1289.',
        );
    }

    public function testSeededWhitelistUnblocksNoticesFromTheBankAndNothingElse(): void
    {
        $parser = new RegexBankEmailNoticeParser();
        $provider = $this->seededProvider($this->seededWhitelist());

        self::assertTrue(
            $parser->supports($this->message('Česká spořitelna <automat@csas.cz>'), $provider),
            'Se seedovaným whitelistem musí avízo ČS projít — jinak je provider dál mrtvý.',
        );
        self::assertTrue($parser->supports($this->message('avizo@mail.csas.cz'), $provider));

        // Fail-closed vlastnost z 1289 zůstává: podvrh od kohokoliv jiného neprojde,
        // ani když se odesílatel tváří jako subdoména banky.
        self::assertFalse($parser->supports($this->message('attacker@evil.example'), $provider));
        self::assertFalse($parser->supports($this->message('automat@csas.cz.evil.example'), $provider));

        // A skutečně se z toho vyparsuje platba, ne jen „prošlo to detekcí".
        $parsed = $parser->parse($this->message('automat@csas.cz'), $provider);
        self::assertSame('123456789', $parsed->variableSymbol);
        self::assertSame(10000.0, $parsed->amount);
        self::assertSame('6509175329/0800', $parsed->recipientAccount);
    }

    public function testEmptyWhitelistStillRejectsEverything(): void
    {
        // Kontrola k druhé variantě z hlášení („prázdný whitelist = bez omezení"):
        // ta se ZÁMĚRNĚ nedělá. Provider bez patternu předmětu by se pak pokoušel
        // parsovat každý e-mail ve schránce.
        $parser = new RegexBankEmailNoticeParser();

        foreach ([null, '', '   '] as $whitelist) {
            self::assertFalse($parser->supports(
                $this->message('Česká spořitelna <automat@csas.cz>'),
                $this->seededProvider($whitelist),
            ));
        }
    }

    private function seededWhitelist(): string
    {
        $sql = $this->migrationStatements();
        self::assertSame(
            1,
            preg_match("/SET\s+sender_whitelist\s*=\s*'([^']+)'/i", $sql, $m),
            'Migrace musí nastavovat konkrétní hodnotu sender_whitelist.',
        );

        return $m[1];
    }

    /** Migrace bez komentářů — asserty se nesmí trefovat do vysvětlujícího textu. */
    private function migrationStatements(): string
    {
        self::assertFileExists(self::MIGRATION);
        $sql = (string) file_get_contents(self::MIGRATION);

        return (string) preg_replace('/^\s*--.*$/m', '', $sql);
    }

    private function seededProvider(?string $whitelist): BankEmailNoticeProvider
    {
        return new BankEmailNoticeProvider(
            id: 2,
            supplierId: null,
            providerRef: 'db:2',
            code: 'ceska-sporitelna',
            name: 'Česká spořitelna - avízo o pohybu',
            parserType: 'regex',
            enabled: true,
            senderWhitelist: $whitelist,
            subjectPattern: null,
            bodyPattern: 'Směr\s+platby',
            fieldPatterns: [
                'recipient_account' => 'Číslo účtu:\s*(?<value>[0-9\-]+\/[0-9]{4})',
                'counterparty_account' => 'Číslo účtu protistrany:\s*(?<value>[0-9\-]+\/[0-9]{4})',
                'amount' => 'Částka v měně účtu:\s*(?<value>[0-9 .]+,[0-9]{2})',
                'currency' => 'Částka v měně účtu:\s*[0-9 .]+,[0-9]{2}\s*(?<value>Kč|CZK|EUR|USD|€)',
                'variable_symbol' => 'Variabilní symbol:\s*(?<value>[0-9]+)',
                'constant_symbol' => 'Konstantní symbol:\s*(?<value>[0-9]+)',
                'direction' => 'Směr platby:\s*(?<value>příchozí|odchozí)',
            ],
            normalizerConfig: [],
            system: false,
        );
    }

    private function message(string $sender): BankEmailNoticeMessage
    {
        return new BankEmailNoticeMessage(
            uid: 1,
            messageId: '<cs-notice@csas.cz>',
            date: new \DateTimeImmutable('2026-08-14 09:30:00'),
            sender: $sender,
            subject: 'Avízo o pohybu na účtu',
            text: self::NOTICE_BODY,
            raw: self::NOTICE_BODY,
        );
    }
}
