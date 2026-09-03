<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Sestaví jeden archiv; worker vlastní stavový automat a storno mezi fázemi. */
interface CompanyBackupExportPipeline
{
    /** @param array<string,mixed> $job */
    public function check(array $job): void;

    /**
     * @param array<string,mixed> $job
     * @param \Closure():void $beforePackaging
     */
    public function export(
        array $job,
        #[\SensitiveParameter] string $password,
        \Closure $beforePackaging,
    ): CompanyBackupStoredArtifact;

    /** Odstraní již zveřejněný archiv, který kvůli stornu nesmí být dokončen. */
    public function discard(CompanyBackupStoredArtifact $artifact): void;
}
