<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Upozornění na čekající schválení, jejichž aktivní odkazy se nezálohují. */
final class CompanyBackupApprovalRequestWarning
{
    /** @param array<string,mixed> $row */
    public static function isPendingInvoice(string $registryKey, array $row): bool
    {
        return $registryKey === 'table:invoices'
            && ($row['approval_status'] ?? null) === 'requested';
    }

    /** @return array{code:string,message:string,count:int} */
    public static function pending(int $count): array
    {
        if ($count < 1) {
            throw new \InvalidArgumentException('Počet čekajících schválení musí být kladný.');
        }

        return [
            'code' => 'approval_requests_need_resend_after_restore',
            'message' => 'Ve fakturách ze zálohy zůstane stav čekajícího schválení (počet: '
                . $count . '), ale původní aktivní schvalovací odkazy po obnově nebudou dostupné. '
                . 'Žádosti je nutné znovu odeslat. Dokončená schválení tím nejsou dotčena.',
            'count' => $count,
        ];
    }
}
