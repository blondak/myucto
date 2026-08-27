<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Limity technické validace, vynucované před rozbalením jediné položky. */
final readonly class CompanyBackupArchiveLimits
{
    public function __construct(
        public int $maxArchiveBytes = 5_368_709_120,
        public int $maxEntries = 50_000,
        public int $maxEntryBytes = 4_294_967_296,
        public int $maxExpandedBytes = 21_474_836_480,
        public int $maxCompressionRatio = 200,
        public int $maxManifestBytes = 4_194_304,
        public int $maxChecksumsBytes = 33_554_432,
    ) {
        foreach ([
            $maxArchiveBytes,
            $maxEntries,
            $maxEntryBytes,
            $maxExpandedBytes,
            $maxCompressionRatio,
            $maxManifestBytes,
            $maxChecksumsBytes,
        ] as $limit) {
            if ($limit < 1) {
                throw new \InvalidArgumentException('Limit zálohového archivu musí být kladný.');
            }
        }
        if ($maxManifestBytes > $maxEntryBytes
            || $maxChecksumsBytes > $maxEntryBytes
            || $maxEntryBytes > $maxExpandedBytes
        ) {
            throw new \InvalidArgumentException('Limity zálohového archivu si odporují.');
        }
    }
}
