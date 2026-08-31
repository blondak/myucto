<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;
use MyInvoice\Service\Backup\Registry\TenantSecretPolicy;

/** Typovaný řádek credentialu a jeho přenositelná citlivá pole uvnitř envelope. */
final readonly class CompanyBackupCredentialSecretBundle
{
    public const FORMAT = 'myucto-company-credential-secret';
    public const VERSION = 1;

    /** @var array<string,int|string> */
    public array $primaryKey;

    /** @var array<string,int|string|null> */
    public array $record;

    /** @var array<string,string> */
    private array $attachments;

    /** @var array<string,?string> */
    private array $secrets;

    /** @var array<string,?string> */
    private array $externalReferences;

    /**
     * @param array<string,int|string> $primaryKey
     * @param array<string,int|string|null> $record
     * @param array<string,string> $attachments
     * @param array<string,?string> $secrets
     * @param array<string,?string> $externalReferences
     */
    private function __construct(
        public string $registryKey,
        public string $variant,
        array $primaryKey,
        array $record,
        array $attachments,
        array $secrets,
        array $externalReferences,
        private CompanyBackupCredentialTableProjection $projection,
    ) {
        $this->primaryKey = $primaryKey;
        $this->record = $record;
        $this->attachments = $attachments;
        $this->secrets = $secrets;
        $this->externalReferences = $externalReferences;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<mixed> $attachments
     * @param array<mixed> $plaintextSecrets
     */
    public static function fromExportRow(
        CompanyBackupCredentialTableProjection $projection,
        string $variant,
        ?int $ownerUserId,
        array $row,
        array $attachments,
        array $plaintextSecrets,
    ): self {
        try {
            if (array_keys($row) !== $projection->columns) {
                throw self::invalid();
            }
            $actualVariant = $projection->variantFor(
                $ownerUserId,
                self::nullableString($row[$projection->sourceColumns['file']]),
                self::nullablePositiveInt(
                    $row[$projection->sourceColumns['vault']],
                ),
            );
            if ($actualVariant['name'] !== $variant) {
                throw self::invalid();
            }

            $record = [];
            foreach ($projection->recordColumns() as $column) {
                $record[$column] = self::recordValue($row[$column] ?? null);
            }
            $primaryKey = [];
            foreach ($projection->primaryKey as $column) {
                $value = $record[$column] ?? null;
                if (!is_int($value) && !is_string($value)) {
                    throw self::invalid();
                }
                $primaryKey[$column] = $value;
            }

            $attachmentPayload = [];
            foreach ($attachments as $column => $contents) {
                if (!is_string($column) || !is_string($contents)) {
                    throw self::invalid();
                }
                $attachmentPayload[$column] = self::encodeBinary($contents);
            }
            $secretPayload = [];
            foreach ($plaintextSecrets as $column => $plaintext) {
                if (!is_string($column)
                    || !is_string($plaintext) && $plaintext !== null
                ) {
                    throw self::invalid();
                }
                $secretPayload[$column] = $plaintext === null
                    ? null
                    : ['value_base64' => base64_encode($plaintext)];
            }
            $external = [];
            foreach ($projection->transportColumns as $column => $transport) {
                if ($transport !== CompanyBackupCredentialTransport::ExternalReference) {
                    continue;
                }
                $value = $row[$column] ?? null;
                if (!is_string($value) && $value !== null) {
                    throw self::invalid();
                }
                $external[$column] = $value;
            }

            return self::fromArray([
                'format' => self::FORMAT,
                'version' => self::VERSION,
                'registry_key' => $projection->registryKey,
                'variant' => $variant,
                'record' => $record,
                'attachments' => $attachmentPayload,
                'secrets' => $secretPayload,
                'external_references' => $external,
            ], $projection, $variant, $primaryKey);
        } catch (CompanyBackupSecretPayloadException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw self::invalid($e);
        }
    }

    /** @param array<mixed> $expectedPrimaryKey */
    public static function fromJson(
        #[\SensitiveParameter] string $json,
        CompanyBackupCredentialTableProjection $projection,
        string $expectedVariant,
        array $expectedPrimaryKey,
    ): self {
        if ($json === '' || strlen($json) > CompanyBackupSecretValue::MAX_VALUE_BYTES) {
            throw self::invalid();
        }
        try {
            $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (!hash_equals(CanonicalJson::encode($value), $json)) {
                throw self::invalid();
            }
            return self::fromArray(
                $value,
                $projection,
                $expectedVariant,
                $expectedPrimaryKey,
            );
        } catch (CompanyBackupSecretPayloadException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw self::invalid($e);
        }
    }

    /**
     * @param array<mixed> $expectedPrimaryKey
     */
    public static function fromArray(
        mixed $value,
        CompanyBackupCredentialTableProjection $projection,
        string $expectedVariant,
        array $expectedPrimaryKey,
    ): self {
        try {
            if (!is_array($value) || array_is_list($value)) {
                throw self::invalid();
            }
            $keys = array_keys($value);
            sort($keys, SORT_STRING);
            if ($keys !== [
                'attachments',
                'external_references',
                'format',
                'record',
                'registry_key',
                'secrets',
                'variant',
                'version',
            ] || $value['format'] !== self::FORMAT
                || $value['version'] !== self::VERSION
                || $value['registry_key'] !== $projection->registryKey
                || $value['variant'] !== $expectedVariant
            ) {
                throw self::invalid();
            }
            $variant = $projection->variantNamed($expectedVariant);

            $record = self::record($value['record'], $projection);
            $primaryKey = self::primaryKey($record, $projection);
            self::assertVariantRecord($record, $variant, $projection);
            $normalizedExpected = self::normalizedPrimaryKey(
                $expectedPrimaryKey,
                $projection,
            );
            if ($primaryKey !== $normalizedExpected) {
                throw self::invalid();
            }
            $attachments = self::attachments(
                $value['attachments'],
                $projection,
                $variant['source'] === 'file',
            );
            $secrets = self::secrets($value['secrets'], $projection);
            $external = self::externalReferences(
                $value['external_references'],
                $projection,
            );
            self::assertPassphrasePolicy($record, $secrets, $external);

            return new self(
                $projection->registryKey,
                $expectedVariant,
                $primaryKey,
                $record,
                $attachments,
                $secrets,
                $external,
                $projection,
            );
        } catch (CompanyBackupSecretPayloadException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw self::invalid($e);
        }
    }

    public function attachment(string $column): string
    {
        return $this->attachments[$column] ?? throw self::invalid();
    }

    public function secret(string $column): ?string
    {
        if (!array_key_exists($column, $this->secrets)) {
            throw self::invalid();
        }
        return $this->secrets[$column];
    }

    /** @return array<string,int|string|null> */
    public function restorableRow(): array
    {
        $row = $this->record;
        foreach ($this->projection->transportColumns as $column => $transport) {
            $row[$column] = $transport === CompanyBackupCredentialTransport::ExternalReference
                ? $this->externalReferences[$column]
                : null;
        }
        $ordered = [];
        foreach ($this->projection->columns as $column) {
            $ordered[$column] = $row[$column];
        }
        return $this->projection->restoreOverrides->apply($ordered);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $attachments = [];
        foreach ($this->attachments as $column => $contents) {
            $attachments[$column] = self::encodeBinary($contents);
        }
        $secrets = [];
        foreach ($this->secrets as $column => $plaintext) {
            $secrets[$column] = $plaintext === null
                ? null
                : ['value_base64' => base64_encode($plaintext)];
        }
        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'registry_key' => $this->registryKey,
            'variant' => $this->variant,
            'record' => $this->record,
            'attachments' => $attachments,
            'secrets' => $secrets,
            'external_references' => $this->externalReferences,
        ];
    }

    public function toJson(): string
    {
        return CanonicalJson::encode($this->toArray());
    }

    /** @return array{bytes:int,sha256:string,value_base64:string} */
    private static function encodeBinary(string $contents): array
    {
        return [
            'bytes' => strlen($contents),
            'sha256' => hash('sha256', $contents),
            'value_base64' => base64_encode($contents),
        ];
    }

    /** @return array<string,int|string|null> */
    private static function record(
        mixed $value,
        CompanyBackupCredentialTableProjection $projection,
    ): array {
        if (!is_array($value) || array_is_list($value)) {
            throw self::invalid();
        }
        $expected = $projection->recordColumns();
        sort($expected, SORT_STRING);
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        if ($keys !== $expected) {
            throw self::invalid();
        }
        ksort($value, SORT_STRING);
        $record = [];
        foreach ($value as $column => $item) {
            $record[$column] = self::recordValue($item);
        }
        return $record;
    }

    /**
     * @param array<string,int|string|null> $record
     * @return array<string,int|string>
     */
    private static function primaryKey(
        array $record,
        CompanyBackupCredentialTableProjection $projection,
    ): array {
        $result = [];
        foreach ($projection->primaryKey as $column) {
            $value = $record[$column] ?? null;
            if (!is_int($value) && !is_string($value)) {
                throw self::invalid();
            }
            $result[$column] = $value;
        }
        ksort($result, SORT_STRING);
        return $result;
    }

    /**
     * @param array<mixed> $value
     * @return array<string,int|string>
     */
    private static function normalizedPrimaryKey(
        array $value,
        CompanyBackupCredentialTableProjection $projection,
    ): array {
        if (array_is_list($value)) {
            throw self::invalid();
        }
        $expected = $projection->primaryKey;
        sort($expected, SORT_STRING);
        if (array_keys($value) !== $expected) {
            throw self::invalid();
        }
        foreach ($value as $item) {
            if (!is_int($item) && !is_string($item)) {
                throw self::invalid();
            }
            self::recordValue($item);
        }
        return $value;
    }

    /** @return array<string,string> */
    private static function attachments(
        mixed $value,
        CompanyBackupCredentialTableProjection $projection,
        bool $required,
    ): array {
        if (!is_array($value) || array_is_list($value) && $value !== []) {
            throw self::invalid();
        }
        $expected = $required ? array_keys($projection->attachmentSources) : [];
        if (array_keys($value) !== $expected) {
            throw self::invalid();
        }
        $result = [];
        foreach ($value as $column => $item) {
            if (!is_array($item) || array_is_list($item)) {
                throw self::invalid();
            }
            $keys = array_keys($item);
            sort($keys, SORT_STRING);
            $bytes = $item['bytes'] ?? null;
            $sha256 = $item['sha256'] ?? null;
            $encoded = $item['value_base64'] ?? null;
            $decoded = is_string($encoded) ? base64_decode($encoded, true) : false;
            $maxBytes = $projection->attachmentSources[$column]['max_bytes'] ?? 0;
            if ($keys !== ['bytes', 'sha256', 'value_base64']
                || !is_int($bytes)
                || $bytes < 1
                || $bytes > $maxBytes
                || !is_string($sha256)
                || preg_match('/^[0-9a-f]{64}$/D', $sha256) !== 1
                || !is_string($decoded)
                || !hash_equals(base64_encode($decoded), $encoded)
                || strlen($decoded) !== $bytes
                || !hash_equals($sha256, hash('sha256', $decoded))
            ) {
                throw self::invalid();
            }
            $result[$column] = $decoded;
        }
        return $result;
    }

    /** @return array<string,?string> */
    private static function secrets(
        mixed $value,
        CompanyBackupCredentialTableProjection $projection,
    ): array {
        if (!is_array($value) || array_is_list($value) && $value !== []) {
            throw self::invalid();
        }
        if (array_keys($value) !== array_keys($projection->secretStorage)) {
            throw self::invalid();
        }
        $result = [];
        foreach ($value as $column => $item) {
            if ($item === null) {
                $result[$column] = null;
                continue;
            }
            if (!is_array($item) || array_is_list($item)) {
                throw self::invalid();
            }
            $keys = array_keys($item);
            $encoded = $item['value_base64'] ?? null;
            $decoded = is_string($encoded) ? base64_decode($encoded, true) : false;
            if ($keys !== ['value_base64']
                || !is_string($decoded)
                || $decoded === ''
                || strlen($decoded) > CompanyBackupSecretValue::MAX_VALUE_BYTES
                || !hash_equals(base64_encode($decoded), $encoded)
            ) {
                throw self::invalid();
            }
            $result[$column] = $decoded;
        }
        return $result;
    }

    /** @return array<string,?string> */
    private static function externalReferences(
        mixed $value,
        CompanyBackupCredentialTableProjection $projection,
    ): array {
        if (!is_array($value) || array_is_list($value) && $value !== []) {
            throw self::invalid();
        }
        $expected = [];
        foreach ($projection->transportColumns as $column => $transport) {
            if ($transport === CompanyBackupCredentialTransport::ExternalReference) {
                $expected[] = $column;
            }
        }
        if (array_keys($value) !== $expected) {
            throw self::invalid();
        }
        $result = [];
        foreach ($value as $column => $item) {
            if (!is_string($item) && $item !== null
                || is_string($item) && !self::validText($item)
            ) {
                throw self::invalid();
            }
            $result[$column] = $item;
        }
        return $result;
    }

    /**
     * @param array<string,int|string|null> $record
     * @param array<string,?string> $secrets
     * @param array<string,?string> $external
     */
    private static function assertPassphrasePolicy(
        array $record,
        array $secrets,
        array $external,
    ): void {
        $policy = $record['passphrase_policy'] ?? null;
        $passphrase = $secrets['encrypted_passphrase'] ?? null;
        $profileId = $external['passphrase_profile_id'] ?? null;
        if ($policy === 'encrypted_store') {
            if ($passphrase === null || $profileId !== null) {
                throw self::invalid();
            }
            return;
        }
        if ($policy === 'passphrase_file') {
            if ($passphrase !== null || !is_string($profileId) || $profileId === '') {
                throw self::invalid();
            }
            return;
        }
        if ($policy !== 'prompt_on_use'
            || $passphrase !== null
            || $profileId !== null
        ) {
            throw self::invalid();
        }
    }

    /**
     * @param array<string,int|string|null> $record
     * @param array{name:string,owner:string,policy:TenantSecretPolicy,source:string} $variant
     */
    private static function assertVariantRecord(
        array $record,
        array $variant,
        CompanyBackupCredentialTableProjection $projection,
    ): void {
        foreach ([
            ...$projection->primaryKey,
            $projection->ownership['profile_column'],
        ] as $column) {
            if (!self::positiveInteger($record[$column] ?? null)) {
                throw self::invalid();
            }
        }
        $vault = $record[$projection->sourceColumns['vault']] ?? null;
        if ($variant['source'] === 'file' && $vault !== null
            || $variant['source'] === 'vault' && !self::positiveInteger($vault)
        ) {
            throw self::invalid();
        }
    }

    private static function recordValue(mixed $value): int|string|null
    {
        if (is_int($value) || $value === null) {
            return $value;
        }
        if (!is_string($value) || !self::validText($value)) {
            throw self::invalid();
        }
        return $value;
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || $value === '') {
            throw self::invalid();
        }
        return $value;
    }

    private static function nullablePositiveInt(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)
            && preg_match('/^[1-9][0-9]*$/D', $value) === 1
            && strlen($value) <= strlen((string) PHP_INT_MAX)
            && (strlen($value) < strlen((string) PHP_INT_MAX)
                || strcmp($value, (string) PHP_INT_MAX) <= 0)
        ) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < 1) {
            throw self::invalid();
        }
        return $value;
    }

    private static function positiveInteger(mixed $value): bool
    {
        return is_int($value) && $value > 0
            || is_string($value)
                && preg_match('/^[1-9][0-9]*$/D', $value) === 1
                && strlen($value) <= strlen((string) PHP_INT_MAX)
                && (strlen($value) < strlen((string) PHP_INT_MAX)
                    || strcmp($value, (string) PHP_INT_MAX) <= 0);
    }

    private static function validText(string $value): bool
    {
        return $value !== ''
            && strlen($value) <= CompanyBackupSecretValue::MAX_VALUE_BYTES
            && preg_match('//u', $value) === 1
            && !str_contains($value, "\0");
    }

    private static function invalid(
        ?\Throwable $previous = null,
    ): CompanyBackupSecretPayloadException {
        return new CompanyBackupSecretPayloadException(
            'secret_payload_invalid',
            $previous,
        );
    }
}
