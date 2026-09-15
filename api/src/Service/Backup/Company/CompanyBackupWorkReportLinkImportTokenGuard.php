<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use PDO;

/** Vydá token právě pro jeden INSERT a ověří jej proti cíli i zdrojovému archivu. */
final class CompanyBackupWorkReportLinkImportTokenGuard
{
    /** @var array<string,true> */
    private array $archivedFingerprints = [];

    /** @var array<string,true> */
    private array $issuedFingerprints = [];

    /** @var array<int,true> */
    private array $issuedSourceIds = [];

    public function __construct(
        private readonly PDO $database,
        private readonly CompanyBackupWorkReportLinkInventory $inventory,
        private readonly CompanyBackupWorkReportLinkDecisionPlan $decisions,
    ) {
        foreach ($inventory->entries() as $entry) {
            $this->archivedFingerprints[$entry['token_fingerprint']] = true;
        }
    }

    public function resolve(int $sourceId, #[\SensitiveParameter] string $sourceToken): string
    {
        $entry = $this->inventory->entry($sourceId);
        if ($entry === null || isset($this->issuedSourceIds[$sourceId])) {
            throw self::error('work_report_link_decision_stale');
        }
        $token = $this->decisions->resolve($sourceId, $sourceToken, $entry['collision']);
        $fingerprint = hash('sha256', $token);
        if (isset($this->issuedFingerprints[$fingerprint])
            || (!hash_equals($sourceToken, $token)
                && isset($this->archivedFingerprints[$fingerprint]))
            || CompanyBackupWorkReportLinkCollisionLookup::hasCollision($this->database, $token)
        ) {
            throw self::error('work_report_link_restore_token_collision');
        }
        $this->issuedFingerprints[$fingerprint] = true;
        $this->issuedSourceIds[$sourceId] = true;
        return $token;
    }

    private static function error(string $code): CompanyBackupPreflightException
    {
        return new CompanyBackupPreflightException(
            $code,
            CompanyBackupWorkReportLinkPolicy::REGISTRY_KEY,
            CompanyBackupWorkReportLinkPolicy::COLUMN,
        );
    }
}
