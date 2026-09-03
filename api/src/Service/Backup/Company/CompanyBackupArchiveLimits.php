<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Sdílené bezpečnostní limity zápisu, inspekce a preflightu archivu. */
final readonly class CompanyBackupArchiveLimits
{
    public int $maxDataRowBytes;

    public function __construct(
        public int $maxArchiveBytes = 5_368_709_120,
        public int $maxEntries = 50_000,
        public int $maxEntryBytes = 4_294_967_296,
        public int $maxExpandedBytes = 21_474_836_480,
        public int $maxCompressionRatio = 200,
        public int $maxManifestBytes = 4_194_304,
        public int $maxChecksumsBytes = 33_554_432,
        ?int $maxDataRowBytes = null,
        public int $maxReferenceRequirements = 100_000,
    ) {
        $resolvedMaxDataRowBytes = $maxDataRowBytes
            ?? min(16_777_216, $maxEntryBytes);
        foreach ([
            $maxArchiveBytes,
            $maxEntries,
            $maxEntryBytes,
            $maxExpandedBytes,
            $maxCompressionRatio,
            $maxManifestBytes,
            $maxChecksumsBytes,
            $resolvedMaxDataRowBytes,
            $maxReferenceRequirements,
        ] as $limit) {
            if ($limit < 1) {
                throw new \InvalidArgumentException('Limit zálohového archivu musí být kladný.');
            }
        }
        if ($maxManifestBytes > $maxEntryBytes
            || $maxChecksumsBytes > $maxEntryBytes
            || $resolvedMaxDataRowBytes > $maxEntryBytes
            || $maxEntryBytes > $maxExpandedBytes
        ) {
            throw new \InvalidArgumentException('Limity zálohového archivu si odporují.');
        }
        $this->maxDataRowBytes = $resolvedMaxDataRowBytes;
    }
}
