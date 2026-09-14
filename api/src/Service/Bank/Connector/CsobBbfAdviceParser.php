<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

final class CsobBbfAdviceParser
{
    private const MAX_BYTES = 10 * 1024 * 1024;
    private const MAX_LINES = 20_000;
    private const MAX_ADVICES = 500;

    /**
     * @return list<array<string,?string>>
     */
    public function parse(#[\SensitiveParameter] string $content): array
    {
        $records = [];
        foreach ($this->envelopes($content) as $envelope) {
            array_push($records, ...$envelope['records']);
        }
        return $records;
    }

    /**
     * @return list<array{content:string,parsed:array{header:array<string,?string>,transactions:list<array<string,?string>>}}>
     */
    public function split(#[\SensitiveParameter] string $content): array
    {
        $result = [];
        foreach ($this->envelopes($content) as $envelope) {
            $transactions = [];
            $creditTotal = '0.00';
            $debitTotal = '0.00';
            $currentBalance = null;
            foreach ($envelope['records'] as $record) {
                $amount = (string) $record['amount'];
                if (str_starts_with($amount, '-')) {
                    $debitTotal = $this->decimalAdd($debitTotal, substr($amount, 1));
                } else {
                    $creditTotal = $this->decimalAdd($creditTotal, $amount);
                }
                if ($record['balance'] !== null) {
                    $currentBalance = $record['balance'];
                } elseif ($currentBalance !== null) {
                    $currentBalance = $this->decimalAdd($currentBalance, $amount);
                }
                $transactions[] = [
                    'posted_at' => $record['booked_on'],
                    'amount' => $record['amount'],
                    'currency' => $record['currency'],
                    'variable_symbol' => $record['variable_symbol'],
                    'constant_symbol' => $record['constant_symbol'],
                    'specific_symbol' => $record['specific_symbol'],
                    'counterparty_account' => $record['counterparty_account'],
                    'counterparty_bank' => $record['counterparty_bank'],
                    'counterparty_name' => $record['counterparty_name'],
                    'description' => $record['description'],
                    'bank_ref' => $record['bank_ref'],
                    'balance' => $record['balance'],
                    'advice_ref' => $record['advice_ref'],
                ];
            }
            $first = $envelope['records'][0];
            $result[] = [
                'content' => $envelope['content'],
                'parsed' => [
                    'header' => [
                        'account_number' => $first['account_number'],
                        'bank_code' => $first['bank_code'],
                        'currency' => $first['currency'],
                        'statement_date' => $first['booked_on'],
                        'statement_number' => $first['advice_ref'],
                        'prev_balance' => null,
                        'curr_balance' => $currentBalance,
                        'debit_total' => $debitTotal,
                        'credit_total' => $creditTotal,
                    ],
                    'transactions' => $transactions,
                ],
            ];
        }
        return $result;
    }

    /**
     * @return list<array{content:string,records:list<array<string,?string>>}>
     */
    private function envelopes(#[\SensitiveParameter] string $content): array
    {
        if ($content === '' || strlen($content) > self::MAX_BYTES
            || preg_match('/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/', $content) === 1
            || preg_match('/\r(?!\n)/', $content) === 1) {
            throw $this->invalid();
        }
        $withoutCrlf = str_replace("\r\n", '', $content);
        $hasCrlf = str_contains($content, "\r\n");
        $hasLf = str_contains($withoutCrlf, "\n");
        if (($hasCrlf && $hasLf) || (!$hasCrlf && !$hasLf)) {
            throw $this->invalid();
        }
        $eol = $hasCrlf ? "\r\n" : "\n";
        $raw = $this->toSingleByte($content);
        $lines = explode($eol, $raw);
        $originalLines = explode($eol, $content);
        $endsWithEol = end($lines) === '';
        if ($endsWithEol) {
            array_pop($lines);
            array_pop($originalLines);
        }
        if ($lines === [] || count($lines) > self::MAX_LINES || count($lines) !== count($originalLines)) {
            throw $this->invalid();
        }
        foreach ($lines as $line) {
            if ($line === '') {
                throw $this->invalid();
            }
        }

        $envelopes = [];
        $adviceRefs = [];
        $bankRefs = [];
        $lineCount = count($lines);
        $index = 0;
        while ($index < $lineCount) {
            if (count($envelopes) >= self::MAX_ADVICES) {
                throw $this->invalid();
            }
            $start = $index;
            $this->header($lines[$index]);
            $index++;
            if ($index >= $lineCount) {
                throw $this->invalid();
            }
            $adviceRef = $this->adviceHeader($lines[$index]);
            if (isset($adviceRefs[$adviceRef])) {
                throw $this->invalid();
            }
            $adviceRefs[$adviceRef] = true;
            $index++;

            $records = [];
            while ($index < $lineCount && !$this->isLock($lines[$index])) {
                $type = strlen($lines[$index]) >= 15 ? substr($lines[$index], 9, 6) : '';
                $record = match ($type) {
                    'ADVMUL' => $this->domestic($lines[$index], $adviceRef),
                    'ADVMUZ' => $this->foreign($lines[$index], $adviceRef),
                    default => throw $this->invalid(),
                };
                if ($records !== [] && (
                    $record['account_number'] !== $records[0]['account_number']
                    || $record['bank_code'] !== $records[0]['bank_code']
                    || $record['currency'] !== $records[0]['currency']
                    || $record['booked_on'] !== $records[0]['booked_on']
                )) {
                    throw $this->invalid();
                }
                $referenceKey = $record['account_number'] . "\x1f" . $record['currency'] . "\x1f"
                    . $record['booked_on'] . "\x1f" . $record['bank_ref'];
                if (isset($bankRefs[$referenceKey])) {
                    throw $this->invalid();
                }
                $bankRefs[$referenceKey] = true;
                $records[] = $record;
                if (count($records) > self::MAX_LINES) {
                    throw $this->invalid();
                }
                $index++;
            }
            if ($records === [] || $index >= $lineCount) {
                throw $this->invalid();
            }
            $this->lock($lines[$index]);
            $end = $index;
            $index++;

            $fragment = implode($eol, array_slice($originalLines, $start, $end - $start + 1));
            if ($end < $lineCount - 1 || $endsWithEol) {
                $fragment .= $eol;
            }
            $envelopes[] = ['content' => $fragment, 'records' => $records];
        }
        return $envelopes;
    }

    private function header(string $line): void
    {
        if (strlen($line) < 31 || strlen($line) > 32 || !$this->service($line)
            || substr($line, 9, 6) !== 'HEADER' || $line[15] !== ' '
            || substr($line, 16, 2) !== '00' || substr($line, 18, 13) !== '01.0000BBCSOB'
            || trim(substr($line, 31)) !== '') {
            throw $this->invalid();
        }
    }

    private function adviceHeader(string $line): string
    {
        if (strlen($line) < 32 || strlen($line) > 33 || !$this->service($line)
            || substr($line, 9, 6) !== 'ADVMUL' || $line[15] !== ' '
            || substr($line, 16, 2) !== '01' || trim(substr($line, 32)) !== '') {
            throw $this->invalid();
        }
        $reference = substr($line, 18, 14);
        if (preg_match('/^\d{14}$/D', $reference) !== 1) {
            throw $this->invalid();
        }
        $this->date(substr($reference, 0, 8));
        return $reference;
    }

    /** @return array<string,?string> */
    private function domestic(string $line, string $adviceRef): array
    {
        $length = strlen($line);
        if ($length < 226 || $length > 548) {
            throw $this->invalid();
        }
        $line = str_pad($line, 548);
        if (!$this->service($line) || substr($line, 9, 6) !== 'ADVMUL' || $line[15] !== ' '
            || substr($line, 16, 2) !== '02' || !in_array(substr($line, 18, 2), ['  ', '01', '02', '11', '12'], true)
            || trim(substr($line, 20, 22)) === '' || substr($line, 42, 3) !== '100'
            || substr($line, 45, 4) !== '0300' || trim(substr($line, 525, 23)) !== '') {
            throw $this->invalid();
        }
        $bookedOn = $this->date(substr($line, 172, 8));
        $this->optionalDate(substr($line, 164, 8));
        $this->optionalDate(substr($line, 180, 8));
        $direction = trim(substr($line, 188, 2));
        if (!in_array($direction, ['C', 'D', 'RC', 'RD'], true)) {
            throw $this->invalid();
        }
        $amount = $this->amount(substr($line, 190, 16), true);
        $amount = $this->signed($amount, in_array($direction, ['D', 'RC'], true));
        $currency = $this->currency(substr($line, 206, 3));
        $balanceRaw = substr($line, 209, 16);
        $balanceDirection = trim(substr($line, 225, 1));
        $balance = null;
        if (trim($balanceRaw) !== '' || $balanceDirection !== '') {
            if (!in_array($balanceDirection, ['C', 'D'], true)) {
                throw $this->invalid();
            }
            $balance = $this->signed($this->amount($balanceRaw, true, true), $balanceDirection === 'D');
        }
        $bankReference = $this->reference(substr($line, 148, 16));
        $transactionNumber = $this->reference(substr($line, 20, 22));
        $bankRef = $bankReference ?? $transactionNumber;
        if ($bankRef === null) {
            throw $this->invalid();
        }
        $counterpartyBank = trim(substr($line, 226, 11));
        if ($counterpartyBank !== '' && preg_match('/^\d{4}$/D', $counterpartyBank) !== 1) {
            throw $this->invalid();
        }
        $message = $this->joinText([
            substr($line, 350, 35), substr($line, 385, 35), substr($line, 420, 35), substr($line, 455, 35),
        ], ' ');
        $note = $this->text(substr($line, 490, 35));
        $description = implode(' | ', array_values(array_filter([$message, $note], static fn (?string $value): bool => $value !== null)));
        $description = $description !== '' ? $description : null;
        return [
            'account_number' => $this->nationalAccount(substr($line, 98, 34), false),
            'bank_code' => '0300',
            'currency' => $currency,
            'booked_on' => $bookedOn,
            'amount' => $amount,
            'variable_symbol' => $this->symbol(substr($line, 310, 10)),
            'constant_symbol' => $this->symbol(substr($line, 306, 4)),
            'specific_symbol' => $this->symbol(substr($line, 320, 10)),
            'counterparty_account' => $this->nationalAccount(substr($line, 237, 34), true),
            'counterparty_bank' => $counterpartyBank !== '' ? $counterpartyBank : null,
            'counterparty_name' => $this->text(substr($line, 271, 35)),
            'description' => $description,
            'bank_ref' => $bankRef,
            'balance' => $balance,
            'advice_ref' => $adviceRef,
        ];
    }

    /** @return array<string,?string> */
    private function foreign(string $line, string $adviceRef): array
    {
        $length = strlen($line);
        if ($length < 694 || $length > 928) {
            throw $this->invalid();
        }
        $line = str_pad($line, 928);
        $direction = substr($line, 18, 3);
        if (!$this->service($line) || substr($line, 9, 6) !== 'ADVMUZ' || $line[15] !== ' '
            || substr($line, 16, 2) !== '02' || !in_array($direction, ['CRE', 'DBE'], true)
            || substr($line, 79, 3) !== '090' || trim(substr($line, 702, 175)) !== ''
            || trim(substr($line, 891, 3)) !== '' || trim(substr($line, 894, 34)) !== '') {
            throw $this->invalid();
        }
        $this->optionalAmountCurrency(substr($line, 571, 16), substr($line, 587, 3), true);
        $amount = $this->amount(substr($line, 590, 16), false, false, false);
        $amount = $this->signed($amount, $direction === 'DBE');
        $currency = $this->currency(substr($line, 606, 3));
        $this->optionalDecimal(substr($line, 609, 12), 7);
        $this->optionalAmountCurrency(substr($line, 621, 16), substr($line, 637, 3), false);
        $this->optionalAmountCurrency(substr($line, 640, 16), substr($line, 656, 3), false);
        $this->optionalAmountCurrency(substr($line, 659, 16), substr($line, 675, 3), false);
        $this->optionalDate(substr($line, 678, 8));
        $bookedOn = $this->date(substr($line, 686, 8));
        $this->optionalDate(substr($line, 694, 8));
        $feeCode = trim(substr($line, 877, 3));
        if ($feeCode !== '' && !in_array($feeCode, ['SHA', 'OUR', 'BEN'], true)) {
            throw $this->invalid();
        }
        $swift = strtoupper(trim(substr($line, 880, 11)));
        if ($swift !== '' && preg_match('/^[A-Z]{6}[A-Z0-9]{2}(?:[A-Z0-9]{3})?$/D', $swift) !== 1) {
            throw $this->invalid();
        }
        $bankRef = $this->reference(substr($line, 51, 28));
        if ($bankRef === null) {
            throw $this->invalid();
        }
        $counterpartyAccount = $this->foreignAccount(substr($line, 256, 35));
        return [
            'account_number' => $this->nationalAccount(substr($line, 82, 34), false),
            'bank_code' => '0300',
            'currency' => $currency,
            'booked_on' => $bookedOn,
            'amount' => $amount,
            'variable_symbol' => null,
            'constant_symbol' => null,
            'specific_symbol' => null,
            'counterparty_account' => $counterpartyAccount,
            'counterparty_bank' => $this->ibanBankCode($counterpartyAccount),
            'counterparty_name' => $this->joinText([
                substr($line, 116, 35), substr($line, 151, 35), substr($line, 186, 35), substr($line, 221, 35),
            ], ' '),
            'description' => $this->joinText([
                substr($line, 431, 35), substr($line, 466, 35), substr($line, 501, 35), substr($line, 536, 35),
            ], ' '),
            'bank_ref' => $bankRef,
            'balance' => null,
            'advice_ref' => $adviceRef,
        ];
    }

    private function lock(string $line): void
    {
        if (strlen($line) !== 52 || !$this->service($line) || substr($line, 9, 4) !== 'LOCK'
            || substr($line, 13, 3) !== '   ' || substr($line, 16, 2) !== '99'
            || substr($line, 18, 12) !== str_repeat(' ', 12)
            || preg_match('/^\d{13}$/D', substr($line, 30, 13)) !== 1
            || substr($line, 43, 8) !== str_repeat(' ', 8) || $line[51] !== '1') {
            throw $this->invalid();
        }
    }

    private function isLock(string $line): bool
    {
        return strlen($line) >= 13 && substr($line, 9, 4) === 'LOCK';
    }

    private function service(string $line): bool
    {
        return strlen($line) >= 9
            && in_array(substr($line, 0, 7), ['T777777', 'T77777 '], true)
            && substr($line, 7, 2) === '  ';
    }

    private function toSingleByte(string $content): string
    {
        if (mb_check_encoding($content, 'UTF-8')) {
            if (preg_match('/[\x80-\xFF]/', $content) !== 1) {
                return $content;
            }
            $converted = @iconv('UTF-8', 'Windows-1250', $content);
            if ($converted === false) {
                throw $this->invalid();
            }
            return $converted;
        }
        $utf8 = @iconv('Windows-1250', 'UTF-8', $content);
        $roundTrip = $utf8 !== false ? @iconv('UTF-8', 'Windows-1250', $utf8) : false;
        if ($utf8 === false || $roundTrip !== $content) {
            throw $this->invalid();
        }
        return $content;
    }

    private function date(string $raw): string
    {
        if (preg_match('/^\d{8}$/D', $raw) !== 1) {
            throw $this->invalid();
        }
        $date = \DateTimeImmutable::createFromFormat('!Ymd', $raw);
        if ($date === false || $date->format('Ymd') !== $raw) {
            throw $this->invalid();
        }
        return $date->format('Y-m-d');
    }

    private function optionalDate(string $raw): void
    {
        $raw = trim($raw);
        if ($raw !== '') {
            $this->date($raw);
        }
    }

    private function currency(string $raw): string
    {
        $currency = strtoupper(trim($raw));
        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw $this->invalid();
        }
        return $currency;
    }

    private function amount(string $raw, bool $commaAllowed, bool $allowZero = false, bool $allowNegative = true): string
    {
        $raw = trim($raw);
        $separator = $commaAllowed ? '[,.]' : '\\.';
        $sign = $allowNegative ? '(-?)' : '()';
        if (preg_match('/^' . $sign . '(\d{1,13})' . $separator . '(\d{2})$/D', $raw, $match) !== 1) {
            throw $this->invalid();
        }
        $whole = ltrim($match[2], '0');
        $whole = $whole !== '' ? $whole : '0';
        if (strlen($whole) > 12) {
            throw $this->invalid();
        }
        if (!$allowZero && $whole === '0' && $match[3] === '00') {
            throw $this->invalid();
        }
        return ($match[1] === '-' ? '-' : '') . $whole . '.' . $match[3];
    }

    private function signed(string $amount, bool $negative): string
    {
        $absolute = ltrim($amount, '-');
        return $negative ? '-' . $absolute : $absolute;
    }

    private function decimalAdd(string $left, string $right): string
    {
        if (!is_numeric($left) || !is_numeric($right)) {
            throw $this->invalid();
        }
        return bcadd($left, $right, 2);
    }

    private function optionalDecimal(string $raw, int $maxScale): void
    {
        $raw = trim($raw);
        if ($raw !== '' && preg_match('/^\d{1,12}(?:\.\d{1,' . $maxScale . '})?$/D', $raw) !== 1) {
            throw $this->invalid();
        }
    }

    private function optionalAmountCurrency(string $amount, string $currency, bool $commaAllowed): void
    {
        $hasAmount = trim($amount) !== '';
        $hasCurrency = trim($currency) !== '';
        if (!$hasAmount && !$hasCurrency) {
            return;
        }
        if (!$hasAmount) {
            throw $this->invalid();
        }
        $parsed = $this->amount($amount, $commaAllowed, true, false);
        if ($hasCurrency) {
            $this->currency($currency);
        } elseif ($parsed !== '0.00') {
            throw $this->invalid();
        }
    }

    private function reference(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^[A-Za-z0-9._\/-]+$/D', $raw) !== 1 || strlen($raw) > 40) {
            throw $this->invalid();
        }
        if (ctype_digit($raw)) {
            $raw = ltrim($raw, '0');
            return $raw !== '' ? $raw : null;
        }
        return $raw;
    }

    private function symbol(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^\d+$/D', $raw) !== 1) {
            throw $this->invalid();
        }
        $raw = ltrim($raw, '0');
        return $raw !== '' ? $raw : null;
    }

    private function nationalAccount(string $raw, bool $optional): ?string
    {
        $account = trim($raw);
        if ($account === '') {
            if ($optional) {
                return null;
            }
            throw $this->invalid();
        }
        if (preg_match('/^\d{1,16}$/D', $account) === 1) {
            if (trim($account, '0') === '') {
                if ($optional) {
                    return null;
                }
                throw $this->invalid();
            }
            return str_pad($account, 16, '0', STR_PAD_LEFT);
        }
        if (preg_match('/^(\d{1,6})-(\d{1,10})$/D', $account, $match) === 1) {
            return str_pad($match[1], 6, '0', STR_PAD_LEFT) . str_pad($match[2], 10, '0', STR_PAD_LEFT);
        }
        if (preg_match('/^999999\d{2}IBIS[A-Z0-9]{1,22}$/D', $account) === 1) {
            return $account;
        }
        throw $this->invalid();
    }

    private function foreignAccount(string $raw): ?string
    {
        $account = trim($raw);
        if ($account === '') {
            return null;
        }
        $compact = strtoupper(str_replace(' ', '', $account));
        if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/D', $compact) === 1) {
            return $compact;
        }
        if (preg_match('/^[A-Za-z0-9.\/-]{1,35}$/D', $account) !== 1) {
            throw $this->invalid();
        }
        return $account;
    }

    private function ibanBankCode(?string $account): ?string
    {
        if ($account === null || preg_match('/^(?:CZ|SK)\d{22}$/D', $account) !== 1) {
            return null;
        }
        return (new \MyInvoice\Service\Payment\IbanValidator())->isValid($account) ? substr($account, 4, 4) : null;
    }

    private function text(string $raw): ?string
    {
        $text = @iconv('Windows-1250', 'UTF-8', rtrim($raw));
        if ($text === false) {
            throw $this->invalid();
        }
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        return $text !== '' ? $text : null;
    }

    /** @param list<string> $parts */
    private function joinText(array $parts, string $separator): ?string
    {
        $texts = [];
        foreach ($parts as $part) {
            $text = $this->text($part);
            if ($text !== null) {
                $texts[] = $text;
            }
        }
        return $texts !== [] ? implode($separator, $texts) : null;
    }

    private function invalid(): BankConnectorException
    {
        return new BankConnectorException(BankConnectorException::INVALID_RESPONSE, 'Bankovní avízo nemá platný formát ČSOB BBF.');
    }
}
