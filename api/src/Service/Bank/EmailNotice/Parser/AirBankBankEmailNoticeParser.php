<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\EmailNotice\Parser;

use MyInvoice\Service\Bank\EmailNotice\BankEmailNoticeMessage;
use MyInvoice\Service\Bank\EmailNotice\ParsedBankEmailNotice;

/**
 * Air Bank — avízo „Zvýšení/Snížení zůstatku na účtu …" (info@airbank.cz).
 *
 * Plain-text šablona (ověřeno 2026-07; čísla anonymizována):
 *   zůstatek na účtu Podnikatelský účet CZK číslo 1234567890/3030 se zvýšil o částku 1,00 CZK.
 *   Dostupný zůstatek k 24.07.2026 v 15:20 je 1,00 CZK.
 *
 *   Příchozí úhrada z účtu Jméno Protistrany číslo 9876543210/0300
 *   Částka: 1,00 CZK
 *   Datum zaúčtování: 24.07.2026
 *   Variabilní symbol: 202600009   (řádek chybí, pokud plátce VS nevyplnil)
 *   Kód transakce: 100000000001
 *
 * Směr nese předmět/tělo (zvýšil = příchozí, snížil = odchozí/záporná částka).
 * VS je volitelný — avízo bez VS se uloží s prázdným symbolem (párování pak
 * podle částky + protiúčtu / ručně).
 */
final class AirBankBankEmailNoticeParser extends AbstractBankEmailNoticeParser
{
    public function key(): string
    {
        return 'airbank';
    }

    protected function parserLabel(): string
    {
        return 'Air Bank';
    }

    public function defaultProvider(): ?BankEmailNoticeProvider
    {
        return new BankEmailNoticeProvider(
            id: null,
            supplierId: null,
            providerRef: 'system:' . $this->key(),
            code: $this->key(),
            name: 'Air Bank - Zvýšení/Snížení zůstatku',
            parserType: $this->key(),
            enabled: true,
            senderWhitelist: 'info@airbank.cz',
            subjectPattern: 'Zvýšení\\s+zůstatku|Snížení\\s+zůstatku|Zvyseni\\s+zustatku|Snizeni\\s+zustatku',
            bodyPattern: 'se\\s+zvýšil\\s+o\\s+částku|se\\s+snížil\\s+o\\s+částku|se\\s+zvysil\\s+o\\s+castku|se\\s+snizil\\s+o\\s+castku',
            fieldPatterns: [],
            normalizerConfig: [],
            system: true,
        );
    }

    public function supports(BankEmailNoticeMessage $message, BankEmailNoticeProvider $provider): bool
    {
        if (!$this->senderMatchesDomain($message, 'airbank.cz')) {
            return false;
        }

        $subject = $this->compact(mb_strtolower($this->foldDiacritics($message->subject), 'UTF-8'));
        if (
            !str_contains($subject, 'zvyseni zustatku')
            && !str_contains($subject, 'snizeni zustatku')
        ) {
            return false;
        }

        $text = $this->compact(mb_strtolower($this->foldDiacritics($this->normalizeText($message->text)), 'UTF-8'));
        return (str_contains($text, 'se zvysil o castku') || str_contains($text, 'se snizil o castku'))
            && (str_contains($text, 'castka:') || str_contains($text, 'cislo '));
    }

    public function parse(BankEmailNoticeMessage $message, BankEmailNoticeProvider $provider): ParsedBankEmailNotice
    {
        $text = $this->normalizeText($message->text);
        $folded = $this->foldDiacritics($text);

        $recipient = $this->required(
            $folded,
            '/zustatek\s+na\s+uctu.*?cislo\s+(?<value>[0-9]+\/[0-9]{4})/iu',
            'cílový účet',
        );

        $amountCurrency = $this->match(
            $folded,
            '/(?:^|\R)\s*Castka:\s*(?<amount>[+\-]?[0-9][0-9 .]*,[0-9]{2})\s*(?<currency>[A-Za-z]{3}|Kc|€)/iu',
        );
        if ($amountCurrency === null) {
            // Fallback: úvodní věta „se zvýšil/snížil o částku X CZK"
            $amountCurrency = $this->match(
                $folded,
                '/se\s+(?:zvysil|snizil)\s+o\s+castku\s+(?<amount>[+\-]?[0-9][0-9 .]*,[0-9]{2})\s*(?<currency>[A-Za-z]{3}|Kc|€)/iu',
            );
        }
        if ($amountCurrency === null) {
            throw new \RuntimeException($this->parserLabel() . ' parser nenašel částku a měnu.');
        }

        $postedAt = $this->optional(
            $folded,
            '/Datum\s+zauctovani:\s*(?<value>\d{1,2}\.\d{1,2}\.\d{4})/iu',
        );
        if ($postedAt === null) {
            if (!$message->date instanceof \DateTimeImmutable) {
                throw new \RuntimeException($this->parserLabel() . ' parser nenašel datum.');
            }
            $postedAt = $message->date->format('d.m.Y');
        }

        $variableSymbol = $this->optional($folded, '/Variabilni\s+symbol:\s*(?<value>[0-9]+)/iu');
        $bankRef = $this->optional($folded, '/Kod\s+transakce:\s*(?<value>[0-9]+)/iu');
        $balance = $this->optional(
            $folded,
            '/Dostupny\s+zustatek.*?je\s+(?<value>[+\-]?[0-9][0-9 .]*,[0-9]{2})/iu',
        );

        // Protistrana: příchozí „z účtu Jméno číslo A/BBBB", odchozí „na účet … číslo …"
        $counterparty = $this->match(
            $text,
            '/Příchozí\s+úhrada\s+z\s+účtu\s+(?<name>.+?)\s+číslo\s+(?<account>[0-9\-]+\/[0-9]{4})/iu',
        );
        if ($counterparty === null) {
            $counterparty = $this->match(
                $folded,
                '/Prichozi\s+uhrada\s+z\s+uctu\s+(?<name>.+?)\s+cislo\s+(?<account>[0-9\-]+\/[0-9]{4})/iu',
            );
        }
        if ($counterparty === null) {
            $counterparty = $this->match(
                $folded,
                '/Odchozi\s+uhrada\s+na\s+ucet\s+(?<name>.+?)\s+cislo\s+(?<account>[0-9\-]+\/[0-9]{4})/iu',
            );
        }

        [$cpAccount, $cpBank] = $this->splitAccount((string) ($counterparty['account'] ?? ''));
        $cpName = isset($counterparty['name']) ? $this->cleanNullable((string) $counterparty['name']) : null;

        $outgoing = preg_match('/se\s+snizil\s+o\s+castku|snizeni\s+zustatku/iu', $folded) === 1;
        $direction = $outgoing ? 'odchozí' : 'příchozí';

        return new ParsedBankEmailNotice(
            variableSymbol: $this->normalizeSymbol((string) $variableSymbol),
            amount: $this->applyDirection($this->parseAmount((string) $amountCurrency['amount']), $direction),
            currency: $this->normalizeCurrency((string) ($amountCurrency['currency'] ?? 'CZK')),
            postedAt: $this->parseDate($postedAt),
            recipientAccount: $this->normalizeAccount($recipient),
            counterpartyAccount: $cpAccount,
            counterpartyBank: $cpBank,
            counterpartyName: $cpName,
            bankRef: $bankRef,
            balance: $balance !== null ? $this->parseAmount($balance) : null,
        );
    }
}
