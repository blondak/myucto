<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use GuzzleHttp\Client;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\SecretEncryption;
use Psr\Log\LoggerInterface;

/**
 * Google Gemini (AI Studio přímé API) klient pro AI extrakci z PDF faktur
 * (F7 §3.3 / §13.1).
 *
 * Endpoint: POST generativelanguage.googleapis.com/v1beta/models/{model}:generateContent
 * Auth: hlavička `x-goog-api-key`. Structured output přes
 * `generationConfig.responseMimeType=application/json` + `responseSchema` →
 * vrací IDENTICKÉ dekódované JSON schéma jako AnthropicClient.
 *
 * dataRegion: přímé API = us (EU jen přes Vertex regional endpoint, mimo rozsah v1).
 * Inkrement `gemini_extractions_count` vlastní klient sám (per-client counter model).
 */
final class GeminiClient implements LlmGatewayInterface
{
    private const TIMEOUT = 120;
    private const MAX_PDF_BYTES = 20 * 1024 * 1024;
    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';

    private Client $http;

    public function __construct(
        private readonly Connection $db,
        private readonly SecretEncryption $crypto,
        private readonly LoggerInterface $logger,
        ?Client $http = null,
    ) {
        $this->http = $http ?? new Client(['timeout' => self::TIMEOUT, 'http_errors' => false]);
    }

    /**
     * @return array{api_key:string, default_model:string}|null
     */
    public function getCredentials(int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT gemini_api_key_enc, gemini_default_model FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row || empty($row['gemini_api_key_enc'])) {
            return null;
        }
        try {
            $key = $this->crypto->decrypt((string) $row['gemini_api_key_enc']);
        } catch (\Throwable) {
            $this->logger->error('Gemini API key decryption failed', ['supplier_id' => $supplierId]);
            return null;
        }
        return [
            'api_key'       => $key,
            'default_model' => (string) ($row['gemini_default_model'] ?? 'gemini-2.0-flash') ?: 'gemini-2.0-flash',
        ];
    }

    public function setCredentials(int $supplierId, string $apiKey, ?string $defaultModel = null): void
    {
        $enc = $apiKey === '' ? null : $this->crypto->encrypt($apiKey);
        $this->db->pdo()->prepare(
            'UPDATE supplier SET gemini_api_key_enc = ?, gemini_default_model = ? WHERE id = ?'
        )->execute([
            $enc,
            $defaultModel !== null && $defaultModel !== '' ? $defaultModel : 'gemini-2.0-flash',
            $supplierId,
        ]);
    }

    public function clearCredentials(int $supplierId): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET gemini_api_key_enc = NULL WHERE id = ?')->execute([$supplierId]);
    }

    public function capabilities(int $supplierId): LlmProviderCapabilities
    {
        $stmt = $this->db->pdo()->prepare('SELECT gemini_default_model FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        return LlmProviderCapabilities::gemini($row['gemini_default_model'] ?? null);
    }

    public function strongerModel(int $supplierId, ?string $currentModel): ?string
    {
        return $this->capabilities($supplierId)->strongerModel($currentModel);
    }

    public function testConnection(int $supplierId): array
    {
        $creds = $this->getCredentials($supplierId);
        if ($creds === null) {
            return ['ok' => false, 'error' => 'Gemini API key nenastaven'];
        }
        try {
            ['code' => $code, 'body' => $body] = $this->post($creds['default_model'], $creds['api_key'], [
                'contents' => [['parts' => [['text' => 'Reply OK']]]],
                'generationConfig' => ['maxOutputTokens' => 10],
            ]);
            if ($code !== 200) {
                $msg = is_array($body) ? ($body['error']['message'] ?? 'HTTP ' . $code) : 'HTTP ' . $code;
                return ['ok' => false, 'error' => $msg];
            }
            return ['ok' => true, 'model' => $creds['default_model'], 'usage' => self::mapUsage($body['usageMetadata'] ?? null)];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function extractInvoice(int $supplierId, string $pdfBytes, ?string $modelOverride = null): array
    {
        $r = $this->generate($supplierId, $pdfBytes, $modelOverride,
            InvoiceExtractionPrompt::tenantContext($this->db, $supplierId) . InvoiceExtractionPrompt::invoiceSystem(),
            'Vytáhni strukturovaná data z této faktury podle JSON schema. Odpověz JEN samotným JSON.',
            4096, self::geminiInvoiceSchema());
        if (!$r['ok']) return $r;
        $data = InvoiceExtractionPrompt::decodeJsonText($r['text']);
        if ($data === null) {
            return ['ok' => false, 'error' => 'Gemini vrátil invalid JSON: ' . substr($r['text'], 0, 200)];
        }
        $this->incrementCounter($supplierId);
        return ['ok' => true, 'data' => $data, 'model' => $r['model'], 'usage' => $r['usage']];
    }

    public function extractFuelTransactions(int $supplierId, string $pdfBytes, ?string $modelOverride = null): array
    {
        $r = $this->generate($supplierId, $pdfBytes, $modelOverride, InvoiceExtractionPrompt::fuelSystem(),
            'Vytáhni jednotlivé transakce tankování z detailního výpisu podle JSON schema. Odpověz JEN JSON.', 8192, null);
        if (!$r['ok']) return $r;
        $data = InvoiceExtractionPrompt::decodeJsonText($r['text']);
        if ($data === null || !isset($data['transactions']) || !is_array($data['transactions'])) {
            return ['ok' => false, 'error' => 'Gemini vrátil invalid JSON: ' . substr($r['text'], 0, 200)];
        }
        $this->incrementCounter($supplierId);
        return ['ok' => true, 'transactions' => array_values($data['transactions']), 'model' => $r['model'], 'usage' => $r['usage']];
    }

    public function extractPdfTotal(int $supplierId, string $pdfBytes, ?string $modelOverride = null): array
    {
        $r = $this->generate($supplierId, $pdfBytes, $modelOverride, InvoiceExtractionPrompt::totalSystem(), 'Vrať K úhradě podle JSON schema.', 100, null);
        if (!$r['ok']) return $r;
        $data = InvoiceExtractionPrompt::decodeJsonText($r['text']);
        if ($data === null || !array_key_exists('total_with_vat', $data)) {
            return ['ok' => false, 'error' => 'Gemini vrátil invalid JSON: ' . substr($r['text'], 0, 100)];
        }
        $total = is_numeric($data['total_with_vat']) ? (float) $data['total_with_vat'] : null;
        $this->incrementCounter($supplierId);
        return ['ok' => true, 'total' => $total, 'model' => $r['model'], 'usage' => $r['usage']];
    }

    public function extractPaymentAccount(int $supplierId, string $pdfBytes, ?string $modelOverride = null): array
    {
        $r = $this->generate($supplierId, $pdfBytes, $modelOverride, InvoiceExtractionPrompt::paymentAccountSystem(),
            'Vrať platební údaje dodavatele podle JSON schema.', 200, null);
        if (!$r['ok']) return $r;
        $data = InvoiceExtractionPrompt::decodeJsonText($r['text']);
        if ($data === null) {
            return ['ok' => false, 'error' => 'Gemini vrátil invalid JSON: ' . substr($r['text'], 0, 100)];
        }
        $this->incrementCounter($supplierId);
        $str = static fn ($v) => (is_string($v) && trim($v) !== '') ? trim($v) : null;
        return [
            'ok'              => true,
            'bank_account'    => $str($data['bank_account'] ?? null),
            'iban'            => $str($data['iban'] ?? null),
            'variable_symbol' => $str($data['variable_symbol'] ?? null),
            'model'           => $r['model'],
            'usage'           => $r['usage'],
        ];
    }

    /**
     * @param array<string,mixed>|null $responseSchema Gemini-format schema (nebo null = jen JSON mime)
     * @return array{ok:bool, text?:string, model?:string, usage?:?array, error?:string}
     */
    private function generate(int $supplierId, string $pdfBytes, ?string $modelOverride, string $systemPrompt, string $userText, int $maxTokens, ?array $responseSchema): array
    {
        $creds = $this->getCredentials($supplierId);
        if ($creds === null) {
            return ['ok' => false, 'error' => 'Gemini API key nenastaven pro tohoto suppliera.'];
        }
        if (strlen($pdfBytes) > self::MAX_PDF_BYTES) {
            return ['ok' => false, 'error' => 'PDF přesahuje limit ' . self::MAX_PDF_BYTES . ' B.'];
        }
        if (!str_starts_with($pdfBytes, '%PDF')) {
            return ['ok' => false, 'error' => 'Soubor není validní PDF (chybí %PDF header).'];
        }

        $model = $modelOverride ?: $creds['default_model'];
        $generationConfig = ['responseMimeType' => 'application/json', 'maxOutputTokens' => $maxTokens];
        if ($responseSchema !== null) {
            $generationConfig['responseSchema'] = $responseSchema;
        }
        $payload = [
            'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
            'contents'          => [[
                'parts' => [
                    ['text' => $userText],
                    ['inline_data' => ['mime_type' => 'application/pdf', 'data' => base64_encode($pdfBytes)]],
                ],
            ]],
            'generationConfig'  => $generationConfig,
        ];

        try {
            ['code' => $code, 'body' => $body] = $this->post($model, $creds['api_key'], $payload);
        } catch (\Throwable $e) {
            $this->logger->error('Gemini extraction failed', ['supplier_id' => $supplierId, 'error' => $e->getMessage()]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        if ($code !== 200) {
            $msg = is_array($body) ? ($body['error']['message'] ?? 'HTTP ' . $code) : 'HTTP ' . $code;
            return ['ok' => false, 'error' => $msg];
        }
        $text = self::extractText($body);
        if ($text === null || $text === '') {
            return ['ok' => false, 'error' => 'Prázdná odpověď od Gemini'];
        }
        return ['ok' => true, 'text' => $text, 'model' => $model, 'usage' => self::mapUsage($body['usageMetadata'] ?? null)];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{code:int, body:array<string,mixed>|null}
     */
    private function post(string $model, string $apiKey, array $payload): array
    {
        $url = self::BASE_URL . '/models/' . rawurlencode($model) . ':generateContent';
        $resp = $this->http->post($url, [
            'headers' => ['x-goog-api-key' => $apiKey, 'content-type' => 'application/json'],
            'json'    => $payload,
        ]);
        $body = json_decode((string) $resp->getBody(), true);
        return ['code' => $resp->getStatusCode(), 'body' => is_array($body) ? $body : null];
    }

    /** Vytáhne text z Gemini generateContent obálky. @param array<string,mixed>|null $body */
    public static function extractText(?array $body): ?string
    {
        $parts = $body['candidates'][0]['content']['parts'] ?? null;
        if (!is_array($parts)) {
            return null;
        }
        $text = '';
        foreach ($parts as $p) {
            if (isset($p['text']) && is_string($p['text'])) {
                $text .= $p['text'];
            }
        }
        return $text === '' ? null : $text;
    }

    /** @param array<string,mixed>|null $usage @return array{input_tokens:int, output_tokens:int}|null */
    private static function mapUsage(?array $usage): ?array
    {
        if (!is_array($usage)) return null;
        return [
            'input_tokens'  => (int) ($usage['promptTokenCount'] ?? 0),
            'output_tokens' => (int) ($usage['candidatesTokenCount'] ?? 0),
        ];
    }

    /**
     * Kompaktní Gemini-format responseSchema (OpenAPI subset, typy velkými písmeny)
     * pro extrakci faktury. Zrcadlí {@see InvoiceExtractionPrompt::invoiceJsonSchema()}.
     *
     * @return array<string,mixed>
     */
    private static function geminiInvoiceSchema(): array
    {
        $party = [
            'type'       => 'OBJECT',
            'properties' => [
                'company_name' => ['type' => 'STRING', 'nullable' => true],
                'ic'           => ['type' => 'STRING', 'nullable' => true],
                'dic'          => ['type' => 'STRING', 'nullable' => true],
                'street'       => ['type' => 'STRING', 'nullable' => true],
                'city'         => ['type' => 'STRING', 'nullable' => true],
                'zip'          => ['type' => 'STRING', 'nullable' => true],
                'country_iso2' => ['type' => 'STRING', 'nullable' => true],
                'email'        => ['type' => 'STRING', 'nullable' => true],
                'phone'        => ['type' => 'STRING', 'nullable' => true],
                'web'          => ['type' => 'STRING', 'nullable' => true],
                'is_vat_payer' => ['type' => 'BOOLEAN', 'nullable' => true],
            ],
        ];
        return [
            'type'       => 'OBJECT',
            'properties' => [
                'vendor'   => $party,
                'customer' => [
                    'type'       => 'OBJECT',
                    'properties' => [
                        'company_name' => ['type' => 'STRING', 'nullable' => true],
                        'ic'           => ['type' => 'STRING', 'nullable' => true],
                        'dic'          => ['type' => 'STRING', 'nullable' => true],
                    ],
                ],
                'payment' => [
                    'type'       => 'OBJECT',
                    'properties' => [
                        'bank_account'    => ['type' => 'STRING', 'nullable' => true],
                        'iban'            => ['type' => 'STRING', 'nullable' => true],
                        'variable_symbol' => ['type' => 'STRING', 'nullable' => true],
                        // Migrace 1128 — forma úhrady. `nullable` je zásadní: null je
                        // SPRÁVNÁ odpověď, když doklad formu neuvádí (Gemini enum nesmí
                        // obsahovat null, řeší se právě přes nullable). Význam nese prompt,
                        // znovu se validuje v AiPdfExtractor.
                        'method'            => ['type' => 'STRING', 'nullable' => true, 'enum' => ['bank_transfer', 'direct_debit', 'card', 'cash', 'cash_on_delivery', 'offset', 'other']],
                        'method_confidence' => ['type' => 'NUMBER', 'nullable' => true],
                    ],
                ],
                'vendor_invoice_number' => ['type' => 'STRING', 'nullable' => true],
                'varsymbol'             => ['type' => 'STRING', 'nullable' => true],
                'document_kind'         => ['type' => 'STRING', 'enum' => ['invoice', 'credit_note', 'advance', 'receipt', 'tax_document']],
                'issue_date'            => ['type' => 'STRING'],
                'tax_date'              => ['type' => 'STRING', 'nullable' => true],
                'due_date'              => ['type' => 'STRING', 'nullable' => true],
                'currency'              => ['type' => 'STRING'],
                'items'                 => [
                    'type'  => 'ARRAY',
                    'items' => [
                        'type'       => 'OBJECT',
                        'properties' => [
                            'description'            => ['type' => 'STRING'],
                            'quantity'               => ['type' => 'NUMBER'],
                            'unit'                   => ['type' => 'STRING', 'nullable' => true],
                            'unit_price_without_vat' => ['type' => 'NUMBER'],
                            'line_total_without_vat' => ['type' => 'NUMBER', 'nullable' => true],
                            'vat_rate'               => ['type' => 'NUMBER'],
                        ],
                    ],
                ],
                'unit_prices_include_vat' => ['type' => 'BOOLEAN'],
                'total_without_vat'       => ['type' => 'NUMBER', 'nullable' => true],
                'total_with_vat'          => ['type' => 'NUMBER', 'nullable' => true],
                'total_with_vat_rounded'  => ['type' => 'NUMBER', 'nullable' => true],
                'vat_recap'               => [
                    'type'  => 'ARRAY',
                    'items' => [
                        'type'       => 'OBJECT',
                        'properties' => [
                            'rate' => ['type' => 'NUMBER'],
                            'base' => ['type' => 'NUMBER'],
                            'vat'  => ['type' => 'NUMBER'],
                        ],
                    ],
                ],
                'already_paid'      => ['type' => 'BOOLEAN'],
                'advance_reference' => ['type' => 'STRING', 'nullable' => true],
                'supply_nature'     => ['type' => 'STRING', 'nullable' => true],
            ],
        ];
    }

    private function incrementCounter(int $supplierId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE supplier SET gemini_extractions_count = gemini_extractions_count + 1 WHERE id = ?'
        )->execute([$supplierId]);
    }
}
