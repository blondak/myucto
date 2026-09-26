<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Garnishment;

use MyInvoice\Repository\Payroll\PayrollEnforcementTerminationNoticeRepository;

/**
 * PDF zmrazeného oznámení o skončení poměru. Samostatně, aby ho mohla použít
 * i fronta podání (resolver artefaktu) bez závislosti na službě, která do
 * fronty sama zapisuje.
 */
final class EnforcementTerminationNoticeDocument
{
    public function __construct(
        private readonly PayrollEnforcementTerminationNoticeRepository $notices,
        private readonly EnforcementTerminationNoticePdfRenderer $renderer,
    ) {}

    /** @return array{bytes:string,filename:string,mime:string}|null */
    public function pdf(int $supplierId, int $noticeId): ?array
    {
        $notice = $this->notices->find($supplierId, $noticeId);
        if ($notice === null) {
            return null;
        }
        $json = (string) $notice['snapshot_json'];
        if (!hash_equals((string) $notice['snapshot_hash'], hash('sha256', $json))) {
            throw new \DomainException('Otisk oznámení nesouhlasí s uloženým obsahem.');
        }
        $snapshot = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($snapshot)
            || ($snapshot['schema'] ?? null) !== EnforcementTerminationNoticeService::SCHEMA
        ) {
            throw new \DomainException('Oznámení má nepodporované schéma.');
        }

        return [
            'bytes' => $this->renderer->render($snapshot),
            'filename' => sprintf(
                'oznameni-skonceni-pomeru-pripad-%d-r%d.pdf',
                (int) $notice['case_id'],
                (int) $notice['revision_no'],
            ),
            'mime' => 'application/pdf',
        ];
    }
}
