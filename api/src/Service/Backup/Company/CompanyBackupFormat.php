<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use JsonException;
use MyInvoice\Service\Backup\Company\Upcast\BackupUpcasterRegistry;

/** Verze formátu, parser kompatibilitní obálky a fail-closed schema brána. */
final class CompanyBackupFormat
{
    public const PRODUCT = 'myucto';
    public const FORMAT = 'myucto-company-backup';
    public const MAJOR = 1;
    public const MINOR = 0;
    public const CURRENT_SCHEMA_REVISION = 'company-backup.schema.v1';

    /** @var array<string,true> */
    private array $supportedCapabilities = [];

    /** @param array<mixed> $supportedCapabilities */
    public function __construct(array $supportedCapabilities = [])
    {
        foreach ($supportedCapabilities as $capability) {
            if (!is_string($capability)
                || preg_match('/^[a-z][a-z0-9._-]{0,127}$/D', $capability) !== 1
            ) {
                throw new \InvalidArgumentException('Podporovaná capability nemá bezpečný identifikátor.');
            }
            if (isset($this->supportedCapabilities[$capability])) {
                throw new \InvalidArgumentException('Podporovaná capability je uvedena vícekrát.');
            }
            $this->supportedCapabilities[$capability] = true;
        }
    }

    /** @param array<mixed> $manifest */
    public function encodeManifest(array $manifest): string
    {
        return CompanyBackupManifestHeader::fromArray($manifest)->canonicalJson();
    }

    public function parseManifestHeader(string $json): CompanyBackupManifestHeader
    {
        try {
            $manifest = json_decode($json, true, 128, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new CompanyBackupFormatException(
                'manifest_json_invalid',
                'manifest',
                'manifest.json není platný JSON: ' . $e->getMessage(),
            );
        }
        if (!is_array($manifest)) {
            throw new CompanyBackupFormatException(
                'manifest_not_object',
                'manifest',
                'Manifest musí být JSON objekt.',
            );
        }
        $header = CompanyBackupManifestHeader::fromArray($manifest);
        if (!hash_equals($header->canonicalJson(), $json)) {
            throw new CompanyBackupFormatException(
                'manifest_not_canonical',
                'manifest',
                'manifest.json musí být zapsaný v kanonickém JSON formátu.',
            );
        }
        return $header;
    }

    public function checkCompatibility(
        CompanyBackupManifestHeader $source,
        string $targetAppVersion,
        string $targetSchemaRevision,
        BackupUpcasterRegistry $upcasters,
    ): CompanyBackupCompatibilityResult {
        if (!CompanyBackupManifestHeader::isSemanticVersion($targetAppVersion)) {
            throw new \InvalidArgumentException('Cílová verze aplikace není platná sémantická verze.');
        }
        if (preg_match('/^[a-z][a-z0-9._-]{0,127}$/D', $targetSchemaRevision) !== 1) {
            throw new \InvalidArgumentException('Cílová schema revision nemá bezpečný identifikátor.');
        }

        $issues = [];
        if ($source->formatMajor !== self::MAJOR) {
            $issues[] = new CompanyBackupCompatibilityIssue(
                'format_major_unsupported',
                'format_version.major',
                'Tato verze aplikace neumí načíst major verzi formátu zálohy.',
                (string) $source->formatMajor,
            );
        }
        foreach ($source->requiredCapabilities as $capability) {
            if (!isset($this->supportedCapabilities[$capability])) {
                $issues[] = new CompanyBackupCompatibilityIssue(
                    'required_capability_unsupported',
                    'capabilities.required',
                    'Záloha vyžaduje capability, kterou cílová aplikace nepodporuje.',
                    $capability,
                );
            }
        }
        if (self::compareVersions($source->sourceAppVersion, $targetAppVersion) > 0) {
            $issues[] = new CompanyBackupCompatibilityIssue(
                'source_application_newer',
                'source.app_version',
                'Zálohu z novější aplikace nelze obnovit do starší verze.',
                $source->sourceAppVersion,
            );
        }

        // Formát, capability a směr verze jsou vstupní brána. Dokud neprojdou,
        // nesmí se plánovat transformace ani číst business payload.
        if ($issues !== []) {
            return new CompanyBackupCompatibilityResult($issues);
        }

        $path = $upcasters->path($source->schemaRevision, $targetSchemaRevision);
        if ($path === null) {
            return new CompanyBackupCompatibilityResult([
                new CompanyBackupCompatibilityIssue(
                    'schema_upcaster_unavailable',
                    'source.schema_revision',
                    'Mezi zdrojovou a cílovou schema revision není úplný směrový řetězec upcasterů.',
                    $source->schemaRevision,
                ),
            ]);
        }
        if (!$path->isLossless()) {
            return new CompanyBackupCompatibilityResult([
                new CompanyBackupCompatibilityIssue(
                    'schema_upcaster_lossy',
                    'source.schema_revision',
                    'Řetězec obsahuje ztrátový upcaster, který nelze použít bez explicitní politiky.',
                    implode(',', $path->ids()),
                ),
            ], $path->ids(), $path->warnings());
        }
        return new CompanyBackupCompatibilityResult([], $path->ids(), $path->warnings());
    }

    /** Obě hodnoty už prošly striktní SemVer validací. */
    private static function compareVersions(string $left, string $right): int
    {
        [$leftCore, $leftPrerelease] = self::versionParts($left);
        [$rightCore, $rightPrerelease] = self::versionParts($right);
        for ($i = 0; $i < 3; $i++) {
            $comparison = self::compareNumericIdentifier($leftCore[$i], $rightCore[$i]);
            if ($comparison !== 0) {
                return $comparison;
            }
        }
        if ($leftPrerelease === null || $rightPrerelease === null) {
            return $leftPrerelease === $rightPrerelease ? 0 : ($leftPrerelease === null ? 1 : -1);
        }

        $count = max(count($leftPrerelease), count($rightPrerelease));
        for ($i = 0; $i < $count; $i++) {
            if (!isset($leftPrerelease[$i]) || !isset($rightPrerelease[$i])) {
                return isset($leftPrerelease[$i]) ? 1 : -1;
            }
            $leftNumeric = ctype_digit($leftPrerelease[$i]);
            $rightNumeric = ctype_digit($rightPrerelease[$i]);
            if ($leftNumeric && $rightNumeric) {
                $comparison = self::compareNumericIdentifier($leftPrerelease[$i], $rightPrerelease[$i]);
            } elseif ($leftNumeric !== $rightNumeric) {
                $comparison = $leftNumeric ? -1 : 1;
            } else {
                $comparison = strcmp($leftPrerelease[$i], $rightPrerelease[$i]) <=> 0;
            }
            if ($comparison !== 0) {
                return $comparison;
            }
        }
        return 0;
    }

    /** @return array{list<string>,?list<string>} */
    private static function versionParts(string $version): array
    {
        $withoutBuild = explode('+', $version, 2)[0];
        $parts = explode('-', $withoutBuild, 2);
        $core = $parts[0];
        $prerelease = $parts[1] ?? null;
        return [explode('.', $core), $prerelease === null ? null : explode('.', $prerelease)];
    }

    private static function compareNumericIdentifier(string $left, string $right): int
    {
        $length = strlen($left) <=> strlen($right);
        return $length !== 0 ? $length : (strcmp($left, $right) <=> 0);
    }
}
