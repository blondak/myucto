<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

final readonly class JmhzPvpojPreview
{
    public const SCHEMA_REFERENCE = 'payroll-jmhz-pvpoj-preview.v1';

    /**
     * @param array<string,mixed> $source
     * @param array<string,mixed> $pvpoj
     * @param list<array<string,mixed>> $reconciliation
     */
    public function __construct(
        public int $supplierId,
        public int $runId,
        public int $revisionId,
        public int $revisionNo,
        public string $period,
        public array $source,
        public array $pvpoj,
        public array $reconciliation,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'schema_reference' => self::SCHEMA_REFERENCE,
            'document_kind' => 'internal_jmhz_pvpoj_preview',
            'workflow_status' => 'preview_only',
            'official_submission' => [
                'supported' => false,
                'reason_code' => 'pvpoj_only_identity_snapshot_incomplete',
            ],
            'xsd' => [
                'bundle_version' => '1.4.3.4',
                'schema_version' => '1.4.3',
                'entry_point' => 'jmhz-1.4.3.4/PVPOJ.xsd',
                'namespace' => 'http://schemas.cssz.cz/JMHZ/PVPOJ/1.0',
            ],
            'supplier_id' => $this->supplierId,
            'run_id' => $this->runId,
            'revision_id' => $this->revisionId,
            'revision_no' => $this->revisionNo,
            'period' => $this->period,
            'currency_code' => 'CZK',
            'source' => $this->source,
            'pvpoj' => $this->pvpoj,
            'reconciliation' => $this->reconciliation,
        ];
    }

    public function canonicalJson(): string
    {
        return CanonicalJson::encode($this->toArray());
    }

    public function sha256(): string
    {
        return hash('sha256', $this->canonicalJson());
    }

    public function downloadBytes(): string
    {
        return $this->canonicalJson();
    }

    public function filename(): string
    {
        return sprintf(
            'jmhz-pvpoj-preview-%s-revize-%d.json',
            $this->period,
            $this->revisionId,
        );
    }
}
