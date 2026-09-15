<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

final class KbPlusConnector implements
    StructuredBankConnector,
    BankConnectorCredentialKeyProvider,
    BankPaymentCapabilityProvider,
    BankConnectorSyncPacing
{
    /**
     * ADAA v2 (GET /accounts/{accountId}/transactions) vrací 429 „Unchanged data
     * download limit reached … Limit: 1 download per 61 minutes“. Cron běží
     * častěji (typicky po 15 minutách), takže bez odstupu by u účtu bez nových
     * pohybů většina běhů skončila odmítnutím.
     */
    private const MINIMUM_SYNC_INTERVAL_SECONDS = 61 * 60;
    /** Basic: KB povoluje nejvýš 50 stažení měsíčně, cron proto stahuje jednou denně. */
    private const BASIC_SYNC_INTERVAL_SECONDS = 24 * 60 * 60;
    private const STATEMENT_FORMAT_KM = 'KM';
    private const STATEMENTS_UNAVAILABLE = 'kb_plus_statements_unavailable';

    public function __construct(
        private readonly KbPlusApiClient $api,
        private readonly KbPlusCredentialVault $vault,
        private readonly KbPlusTransactionParser $parser,
        private readonly KbPlusAboBatchMapper $batchMapper,
        private readonly KbPlusKmStatementParser $kmParser = new KbPlusKmStatementParser(),
    ) {}

    public function minimumAutomaticSyncIntervalSeconds(#[\SensitiveParameter] string $credential): int
    {
        return KbPlusCredentialVault::plan($this->vault->decode($credential)) === KbPlusCredentialVault::PLAN_BASIC
            ? self::BASIC_SYNC_INTERVAL_SECONDS
            : self::MINIMUM_SYNC_INTERVAL_SECONDS;
    }

    public function provider(): string
    {
        return 'kb_plus';
    }

    public function credentials(#[\SensitiveParameter] array $input, array $account, #[\SensitiveParameter] ?string $existing): string
    {
        if ($input !== [] || $existing === null) {
            throw new BankConnectorOperationException('kb_plus_oauth_required');
        }
        $credentials = $this->vault->decode($existing);
        if ((int) $credentials['supplier_id'] !== (int) ($account['supplier_id'] ?? 0)
            || (int) $credentials['connection_id'] !== (int) ($account['id'] ?? 0)
        ) {
            throw new BankConnectorOperationException('credential_context_invalid');
        }
        return $existing;
    }

    public function downloadStatement(#[\SensitiveParameter] string $token, string $from, string $to): string
    {
        $credentials = $this->vault->decode($token);
        if (KbPlusCredentialVault::plan($credentials) === KbPlusCredentialVault::PLAN_BASIC) {
            return $this->downloadBasicStatements($token, $credentials, $from, $to);
        }
        $access = $this->vault->access($token);
        $credentials = $access['credentials'];
        $result = $this->api->transactions(
            $credentials,
            $access['access_token'],
            (string) $credentials['account_id'],
            $from,
            $to,
        );
        return $this->envelope([
            'account_number' => $credentials['account_iban'],
            'currency' => $credentials['account_currency'],
            'from' => $from,
            'to' => $to,
            'transactions' => $result['transactions'],
        ]);
    }

    public function parseStatement(#[\SensitiveParameter] string $content): array
    {
        try {
            $data = json_decode($content, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('KB+ statement envelope is invalid.');
        }
        $isKm = is_array($data) && ($data['format'] ?? null) === self::STATEMENT_FORMAT_KM;
        $keys = $isKm
            ? ['account_number', 'currency', 'from', 'to', 'format', 'files']
            : ['account_number', 'currency', 'from', 'to', 'transactions'];
        if (!is_array($data) || array_is_list($data) || array_diff(array_keys($data), $keys) !== []) {
            throw new \RuntimeException('KB+ statement envelope is invalid.');
        }
        $account = is_string($data['account_number'] ?? null) ? $data['account_number'] : '';
        $currency = is_string($data['currency'] ?? null) ? $data['currency'] : '';
        $from = is_string($data['from'] ?? null) ? $data['from'] : '';
        $to = is_string($data['to'] ?? null) ? $data['to'] : '';
        if ($isKm) {
            return $this->kmParser->parse($this->kmFiles($data['files'] ?? null), $account, $currency, $from, $to);
        }
        return $this->parser->parse(
            is_array($data['transactions'] ?? null) ? $data['transactions'] : [],
            $account,
            $currency,
            $from,
            $to,
        );
    }

    public function statementFormat(): string
    {
        return 'json';
    }

    public function submitPaymentOrder(#[\SensitiveParameter] string $token, #[\SensitiveParameter] string $abo): array
    {
        if (!$this->canSubmitPaymentOrder($token)) {
            throw new BankConnectorException('payment_submission_unavailable', 'Souhlas KB+ nezahrnuje oprávnění bpisp pro dávky.');
        }
        $access = $this->vault->access($token);
        $batch = $this->batchMapper->map($abo, (string) $access['credentials']['account_iban']);
        return $this->api->submitPaymentBatch($access['credentials'], $access['access_token'], $batch);
    }

    public function callGuardCredential(#[\SensitiveParameter] string $credential): string
    {
        return (string) $this->vault->decode($credential)['call_guard_key'];
    }

    public function canSubmitPaymentOrder(#[\SensitiveParameter] string $credential): bool
    {
        $credentials = $this->vault->decode($credential);
        return KbPlusCredentialVault::plan($credentials) !== KbPlusCredentialVault::PLAN_BASIC
            && KbPlusApiClient::grantsBatchPayments((string) $credentials['scope']);
    }

    /**
     * Basic nezahrnuje pohyby ani zůstatky z ADAA, jen výpisy STATDA do předchozího
     * obchodního dne; dotaz na dnešek by banka odmítla.
     *
     * @param array<string,mixed> $credentials
     */
    private function downloadBasicStatements(
        #[\SensitiveParameter] string $token,
        #[\SensitiveParameter] array $credentials,
        string $from,
        string $to,
    ): string {
        if (!KbPlusApiClient::grantsStatements((string) $credentials['scope'])) {
            throw new BankConnectorException(self::STATEMENTS_UNAVAILABLE, 'Souhlas KB+ nezahrnuje oprávnění statda pro výpisy.');
        }
        $to = min($to, (new \DateTimeImmutable('yesterday'))->format('Y-m-d'));
        $from = min($from, $to);
        $access = $this->vault->access($token);
        $files = $this->api->statements(
            $access['access_token'],
            (string) $access['credentials']['account_id'],
            $from,
            $to,
        );
        return $this->envelope([
            'account_number' => $access['credentials']['account_iban'],
            'currency' => $access['credentials']['account_currency'],
            'from' => $from,
            'to' => $to,
            'format' => self::STATEMENT_FORMAT_KM,
            'files' => array_map('base64_encode', $files),
        ]);
    }

    /** @return list<string> */
    private function kmFiles(mixed $files): array
    {
        if (!is_array($files) || !array_is_list($files)) {
            throw new \RuntimeException('KB+ statement envelope is invalid.');
        }
        $result = [];
        foreach ($files as $file) {
            $raw = is_string($file) ? base64_decode($file, true) : false;
            if ($raw === false) {
                throw new \RuntimeException('KB+ statement envelope is invalid.');
            }
            $result[] = $raw;
        }
        return $result;
    }

    /** @param array<string,mixed> $envelope */
    private function envelope(#[\SensitiveParameter] array $envelope): string
    {
        try {
            return json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE, 64);
        } catch (\JsonException) {
            throw new BankConnectorException(BankConnectorException::INVALID_RESPONSE, 'Pohyby KB+ nelze bezpečně serializovat.');
        }
    }
}
