<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\EmailNotice\Parser;

use MyInvoice\Service\Bank\EmailNotice\BankEmailNoticeMessage;
use MyInvoice\Service\Bank\EmailNotice\ParsedBankEmailNotice;

final class RaiffeisenbankEmailNoticeParser extends AbstractBankEmailNoticeParser
{
    public function key(): string
    {
        return 'raiffeisenbank';
    }

    protected function parserLabel(): string
    {
        return 'Raiffeisenbank';
    }

    public function defaultProvider(): ?BankEmailNoticeProvider
    {
        return new BankEmailNoticeProvider(
            id: null,
            supplierId: null,
            providerRef: 'system:' . $this->key(),
            code: $this->key(),
            name: 'Raiffeisenbank - Pohyb na účtě',
            parserType: $this->key(),
            enabled: true,
            senderWhitelist: 'info@rb.cz',
            subjectPattern: 'Pohyb\\s+na\\s+účtě|Pohyb\\s+na\\s+ucte',
            bodyPattern: 'Variabilní\\s+symbol',
            fieldPatterns: [],
            normalizerConfig: [],
            system: true,
        );
    }

    public function supports(BankEmailNoticeMessage $message, BankEmailNoticeProvider $provider): bool
    {
        if (!$this->senderMatchesDomain($message, 'rb.cz')) {
            return false;
        }
        $subject = mb_strtolower($message->subject);
        if (!str_contains($subject, 'pohyb na účtě') && !str_contains($subject, 'pohyb na ucte')) {
            return false;
        }
        $text = mb_strtolower($this->normalizeText($message->text));
        return str_contains($text, 'variabilní symbol')
            && str_contains($text, 'částka v měně účtu')
            && str_contains($text, 'na účet');
    }

    public function parse(BankEmailNoticeMessage $message, BankEmailNoticeProvider $provider): ParsedBankEmailNotice
    {
        $text = $this->normalizeText($message->text);

        $postedAt = $this->required($text, '/Datum\s+a\s+čas\s*(?<value>\d{1,2}\.\s*\d{1,2}\.\s*\d{4}\s+\d{1,2}:\d{2})/iu', 'datum');
        $amountCurrency = $this->match($text, '/Částka\s+v\s+měně\s+účtu\s*(?<amount>[+\-]?[0-9 .]+,[0-9]{2})\s*(?<currency>[A-Z]{3})/iu');
        if ($amountCurrency === null) {
            throw new \RuntimeException('Raiffeisenbank parser nenašel částku a měnu.');
        }
        $amount = $this->parseAmount((string) $amountCurrency['amount']);
        $to = $this->match(
            $text,
            '/Na\s+účet\s*(?<account>[0-9\-]+\/[0-9]{4})(?<name>.*?)(?=Částka\s+v\s+měně\s+účtu|Variabilní\s+symbol)/isu',
        );
        if ($to === null) {
            throw new \RuntimeException('Raiffeisenbank parser nenašel účet příjemce.');
        }
        $from = $this->match(
            $text,
            '/Z\s+účtu\s*(?<account>[0-9\-]+\/[0-9]{4})(?<name>.*?)(?=Částka\s+v\s+měně\s+účtu|Variabilní\s+symbol)/isu',
        );
        $variableSymbol = $this->required($text, '/Variabilní\s+symbol\s*(?<value>[0-9]+)/iu', 'variabilní symbol');
        $constantSymbol = $this->optional($text, '/Konstantní\s+symbol\s*(?<value>[0-9]+)/iu');
        $note = $this->optional($text, '/Zpráva\s+pro\s+příjemce\s*(?<value>.*?)Disponibilní\s+zůstatek/isu');
        $balance = $this->optional($text, '/Disponibilní\s+zůstatek(?:\s+po\s+pohybu)?\s*(?<value>[+\-]?[0-9][0-9 .]*,[0-9]{2})/iu');

        $isOutgoing = $this->isOutgoingTransfer($text, $amount);
        if ($isOutgoing && $from === null) {
            throw new \RuntimeException('Raiffeisenbank parser nenašel vlastní účet plátce.');
        }
        $ownAccount = (string) ($isOutgoing ? $from['account'] : $to['account']);
        $counterparty = $isOutgoing ? $to : $from;
        [$counterpartyAccount, $counterpartyBank] = $this->splitAccount((string) ($counterparty['account'] ?? ''));

        return new ParsedBankEmailNotice(
            variableSymbol: $variableSymbol,
            amount: $amount,
            currency: strtoupper((string) $amountCurrency['currency']),
            postedAt: $this->parseDate($postedAt),
            recipientAccount: $ownAccount,
            counterpartyAccount: $counterpartyAccount,
            counterpartyBank: $counterpartyBank,
            counterpartyName: $this->cleanNullable((string) ($counterparty['name'] ?? '')),
            constantSymbol: $constantSymbol,
            message: $note,
            balance: $balance !== null ? $this->parseAmount($balance) : null,
        );
    }

    private function isOutgoingTransfer(string $text, float $amount): bool
    {
        $folded = mb_strtolower($this->foldDiacritics($text));
        if (preg_match(
            '/na\s+ucte\s+byla\s+provedena\s+nasledujici\s+(?<direction>prichozi|odchozi)\s+platba/u',
            $folded,
            $match,
        ) === 1) {
            return $match['direction'] === 'odchozi';
        }

        return $amount < 0;
    }

}
