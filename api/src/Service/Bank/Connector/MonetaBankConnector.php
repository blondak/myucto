<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

use MyInvoice\Service\Bank\AccountNumberNormalizer;

/**
 * MONETA Money Bank přes MONETA API. Klient zadá jen token z Internet Banky,
 * interní id účtu se dohledá podle IBANu nastaveného účtu při každém volání.
 * Token platí pro všechny účty klienta, proto se uložené údaje váží na IBAN
 * a měnu konkrétního účtu a jiný účet téhož tokenu se nikdy nepoužije.
 */
final class MonetaBankConnector implements StructuredBankConnector
{
    public function __construct(
        private readonly MonetaApiClient $api,
        private readonly MonetaTransactionParser $parser,
        private readonly MonetaAboBatchMapper $mapper,
    ) {}

    public function provider(): string
    {
        return 'moneta';
    }

    public function statementFormat(): string
    {
        return 'json';
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $account
     */
    public function credentials(#[\SensitiveParameter] array $input, array $account, #[\SensitiveParameter] ?string $existing): string
    {
        if ($input !== []) {
            throw new BankConnectorOperationException('credential_format_invalid');
        }
        if ($existing === null || $existing === '') {
            throw new BankConnectorOperationException('token_required');
        }
        $token = str_starts_with($existing, '{') ? $this->credentialData($existing)['token'] : trim($existing);
        if (preg_match('/^[A-Za-z0-9._~-]{16,512}$/D', $token) !== 1) {
            throw new BankConnectorOperationException('token_invalid');
        }
        $currency = strtoupper(trim((string) ($account['account_code'] ?? '')));
        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw new BankConnectorOperationException('account_currency_missing');
        }

        return json_encode([
            'token' => $token,
            'iban' => $this->configuredIban($account),
            'currency' => $currency,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public function downloadStatement(#[\SensitiveParameter] string $token, string $from, string $to): string
    {
        $data = $this->credentialData($token);
        $this->assertPeriod($from, $to);
        $accountId = $this->accountId($data);
        $rows = $this->api->transactions($data['token'], $accountId, $from, $to);
        $this->parser->parse($rows, $data['iban'], $data['currency'], $to);

        return json_encode([
            'provider' => $this->provider(),
            'account_id' => $accountId,
            'iban' => $data['iban'],
            'currency' => $data['currency'],
            'from' => $from,
            'to' => $to,
            'transactions' => $rows,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** @return array{header:array<string,mixed>,transactions:list<array<string,mixed>>} */
    public function parseStatement(#[\SensitiveParameter] string $content): array
    {
        try {
            if (strlen($content) > 32 * 1024 * 1024) {
                throw new \RuntimeException('Výpis je příliš velký.');
            }
            $data = json_decode($content, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if (
                !is_array($data)
                || ($data['provider'] ?? null) !== $this->provider()
                || !is_string($data['iban'] ?? null)
                || !is_string($data['currency'] ?? null)
                || !is_string($data['to'] ?? null)
                || !is_array($data['transactions'] ?? null)
                || !array_is_list($data['transactions'])
            ) {
                throw new \RuntimeException('Neplatná struktura výpisu.');
            }
            return $this->parser->parse($data['transactions'], $data['iban'], $data['currency'], $data['to']);
        } catch (\Throwable) {
            throw new BankConnectorException(BankConnectorException::INVALID_RESPONSE, 'Neplatný výpis MONETA Money Bank.');
        }
    }

    public function submitPaymentOrder(#[\SensitiveParameter] string $token, #[\SensitiveParameter] string $abo): array
    {
        $data = $this->credentialData($token);
        if ($data['currency'] !== 'CZK') {
            throw new BankConnectorException(BankConnectorException::INVALID_PAYMENT_ORDER, 'MONETA API přijímá jen tuzemské CZK příkazy.');
        }
        $payments = $this->mapper->map($abo, $data['iban']);
        $this->accountId($data);

        return ['accepted' => true, 'reference' => $this->api->submitBatch($data['token'], $payments)];
    }

    /** @param array{token:string,iban:string,currency:string} $data */
    private function accountId(#[\SensitiveParameter] array $data): string
    {
        $matches = [];
        foreach ($this->api->accounts($data['token']) as $account) {
            $iban = strtoupper((string) preg_replace('/\s+/', '', (string) ($account['identification']['iban'] ?? '')));
            if ($iban === $data['iban'] && ($account['currency'] ?? null) === $data['currency']) {
                $matches[] = $account;
            }
        }
        $id = $matches[0]['id'] ?? null;
        if (count($matches) !== 1 || !is_string($id) || preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $id) !== 1) {
            throw new BankConnectorException('statement_account_mismatch', 'Token MONETA API nemá přístup k nastavenému účtu.');
        }
        return $id;
    }

    /** @param array<string,mixed> $account */
    private function configuredIban(array $account): string
    {
        $iban = strtoupper((string) preg_replace('/\s+/', '', (string) ($account['iban'] ?? '')));
        $number = trim((string) ($account['account_number'] ?? ''));
        if ($iban === '' && $number !== '') {
            $iban = $this->czechIban($number);
        }
        if (
            preg_match('/^CZ\d{2}0600\d{16}$/D', $iban) !== 1
            || !$this->validIbanChecksum($iban)
            || ($number !== '' && !AccountNumberNormalizer::equalsCzech($number, $iban))
        ) {
            throw new BankConnectorOperationException('provider_account_mismatch');
        }
        return $iban;
    }

    private function czechIban(string $number): string
    {
        $national = (string) preg_replace('#/\d{4}$#', '', (string) preg_replace('/\s+/', '', $number));
        if (preg_match('/^(?:(\d{1,6})-)?(\d{1,10})$/D', $national, $m) !== 1) {
            throw new BankConnectorOperationException('provider_account_mismatch');
        }
        $bban = '0600' . str_pad($m[1], 6, '0', STR_PAD_LEFT) . str_pad($m[2], 10, '0', STR_PAD_LEFT);
        return 'CZ' . str_pad((string) (98 - $this->mod97($bban . '123500')), 2, '0', STR_PAD_LEFT) . $bban;
    }

    private function validIbanChecksum(string $iban): bool
    {
        return $this->mod97(substr($iban, 4) . '1235' . substr($iban, 2, 2)) === 1;
    }

    private function mod97(string $digits): int
    {
        $remainder = 0;
        foreach (str_split($digits) as $digit) {
            $remainder = ($remainder * 10 + (int) $digit) % 97;
        }
        return $remainder;
    }

    private function assertPeriod(string $from, string $to): void
    {
        foreach ([$from, $to] as $value) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if ($date === false || $date->format('Y-m-d') !== $value) {
                throw new BankConnectorException(BankConnectorException::INVALID_DATE, 'Datum pohybů nemá platný formát.');
            }
        }
        if ($from > $to) {
            throw new BankConnectorException(BankConnectorException::INVALID_DATE_RANGE, 'Počáteční datum musí předcházet koncovému.');
        }
    }

    /** @return array{token:string,iban:string,currency:string} */
    private function credentialData(#[\SensitiveParameter] string $json): array
    {
        try {
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new BankConnectorOperationException('credential_format_invalid');
        }
        if (
            !is_array($data)
            || !is_string($data['token'] ?? null) || preg_match('/^[A-Za-z0-9._~-]{16,512}$/D', $data['token']) !== 1
            || !is_string($data['iban'] ?? null) || preg_match('/^CZ\d{2}0600\d{16}$/D', $data['iban']) !== 1
            || !is_string($data['currency'] ?? null) || preg_match('/^[A-Z]{3}$/D', $data['currency']) !== 1
        ) {
            throw new BankConnectorOperationException('credential_format_invalid');
        }
        return ['token' => $data['token'], 'iban' => $data['iban'], 'currency' => $data['currency']];
    }
}
