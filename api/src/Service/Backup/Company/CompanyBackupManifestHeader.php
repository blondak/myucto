<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use JsonException;
use MyInvoice\Service\Backup\CanonicalJson;

/**
 * Stabilní obálka manifestu, kterou lze ověřit před načtením business dat.
 * Další sekce manifestu jsou záměrně zachované pro pozdější doménový preflight.
 */
final readonly class CompanyBackupManifestHeader
{
    /**
     * @param list<string> $requiredCapabilities
     * @param list<string> $optionalCapabilities
     * @param array<string,mixed> $manifest
     */
    private function __construct(
        public string $product,
        public string $format,
        public int $formatMajor,
        public int $formatMinor,
        public string $backupId,
        public string $sourceAppVersion,
        public string $schemaRevision,
        public array $requiredCapabilities,
        public array $optionalCapabilities,
        private array $manifest,
    ) {}

    /** @param array<mixed> $manifest */
    public static function fromArray(array $manifest): self
    {
        if (array_is_list($manifest)) {
            self::fail('manifest_not_object', 'manifest', 'Manifest musí být JSON objekt.');
        }

        $format = $manifest['format'] ?? null;
        if ($format === 'myucto-instance-export') {
            self::fail(
                'legacy_format_requires_adapter',
                'format',
                'Starý myucto-instance-export musí zpracovat samostatný legacy adaptér.',
            );
        }
        if (($manifest['product'] ?? null) !== CompanyBackupFormat::PRODUCT) {
            self::fail('product_unsupported', 'product', 'Záloha nepatří produktu MyÚčto.');
        }
        if ($format !== CompanyBackupFormat::FORMAT) {
            self::fail('format_unsupported', 'format', 'Archiv není záloha firmy podporovaného formátu.');
        }

        $version = self::object($manifest['format_version'] ?? null, 'format_version');
        $major = self::integer($version['major'] ?? null, 'format_version.major', 1);
        $minor = self::integer($version['minor'] ?? null, 'format_version.minor', 0);

        $backupId = $manifest['backup_id'] ?? null;
        if (!is_string($backupId)
            || !self::isCanonicalBackupId($backupId)
        ) {
            self::fail('backup_id_invalid', 'backup_id', 'Identifikátor zálohy musí být kanonické UUID.');
        }

        $source = self::object($manifest['source'] ?? null, 'source');
        $appVersion = $source['app_version'] ?? null;
        if (!is_string($appVersion) || !self::isSemanticVersion($appVersion)) {
            self::fail(
                'source_app_version_invalid',
                'source.app_version',
                'Zdrojová verze aplikace musí být sémantická verze major.minor.patch.',
            );
        }
        $schemaRevision = self::identifier(
            $source['schema_revision'] ?? null,
            'source.schema_revision',
            'schema_revision_invalid',
        );

        $capabilities = self::object($manifest['capabilities'] ?? null, 'capabilities');
        $required = self::capabilityList($capabilities['required'] ?? null, 'capabilities.required');
        $optional = self::capabilityList($capabilities['optional'] ?? null, 'capabilities.optional');
        $overlap = array_values(array_intersect($required, $optional));
        if ($overlap !== []) {
            self::fail(
                'capability_classification_invalid',
                'capabilities',
                'Capability nesmí být současně povinná i volitelná.',
            );
        }

        sort($required, SORT_STRING);
        sort($optional, SORT_STRING);
        $manifest['capabilities']['required'] = $required;
        $manifest['capabilities']['optional'] = $optional;

        try {
            CanonicalJson::encode($manifest);
        } catch (\InvalidArgumentException | JsonException $e) {
            self::fail(
                'manifest_value_invalid',
                'manifest',
                'Manifest obsahuje hodnotu, kterou nelze kanonicky zapsat: ' . $e->getMessage(),
            );
        }

        return new self(
            CompanyBackupFormat::PRODUCT,
            CompanyBackupFormat::FORMAT,
            $major,
            $minor,
            $backupId,
            $appVersion,
            $schemaRevision,
            $required,
            $optional,
            $manifest,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->manifest;
    }

    public function canonicalJson(): string
    {
        return CanonicalJson::encode($this->manifest);
    }

    public function sha256(): string
    {
        return hash('sha256', $this->canonicalJson());
    }

    public static function isSemanticVersion(string $version): bool
    {
        if (strlen($version) > 128
            || preg_match(
                '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)'
                . '(?:-[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?'
                . '(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/D',
                $version,
            ) !== 1
        ) {
            return false;
        }
        $withoutBuild = explode('+', $version, 2)[0];
        $prerelease = explode('-', $withoutBuild, 2)[1] ?? null;
        if ($prerelease === null) {
            return true;
        }
        foreach (explode('.', $prerelease) as $identifier) {
            if (ctype_digit($identifier) && strlen($identifier) > 1 && $identifier[0] === '0') {
                return false;
            }
        }
        return true;
    }

    public static function isCanonicalBackupId(string $backupId): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',
            $backupId,
        ) === 1;
    }

    /** @return array<string,mixed> */
    private static function object(mixed $value, string $field): array
    {
        if (!is_array($value) || array_is_list($value)) {
            self::fail('manifest_field_invalid', $field, $field . ' musí být JSON objekt.');
        }
        return $value;
    }

    private static function integer(mixed $value, string $field, int $minimum): int
    {
        if (!is_int($value) || $value < $minimum) {
            self::fail(
                'manifest_field_invalid',
                $field,
                $field . ' musí být celé číslo nejméně ' . $minimum . '.',
            );
        }
        return $value;
    }

    private static function identifier(mixed $value, string $field, string $errorCode): string
    {
        if (!is_string($value)
            || preg_match('/^[a-z][a-z0-9._-]{0,127}$/D', $value) !== 1
        ) {
            self::fail($errorCode, $field, $field . ' nemá bezpečný identifikátor.');
        }
        return $value;
    }

    /** @return list<string> */
    private static function capabilityList(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            self::fail('capability_list_invalid', $field, $field . ' musí být JSON pole.');
        }
        if (count($value) > 256) {
            self::fail('capability_list_invalid', $field, $field . ' obsahuje příliš mnoho položek.');
        }
        $result = [];
        $seen = [];
        foreach ($value as $capability) {
            $identifier = self::identifier($capability, $field, 'capability_invalid');
            if (isset($seen[$identifier])) {
                self::fail('capability_duplicate', $field, 'Capability ' . $identifier . ' je uvedena vícekrát.');
            }
            $seen[$identifier] = true;
            $result[] = $identifier;
        }
        return $result;
    }

    private static function fail(string $errorCode, string $field, string $message): never
    {
        throw new CompanyBackupFormatException($errorCode, $field, $message);
    }
}
