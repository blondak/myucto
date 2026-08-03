<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Fixtures\Payroll;

use MyInvoice\Service\Payroll\Document\PayslipDocumentData;
use MyInvoice\Service\Payroll\Document\PayslipLine;

final class SyntheticPayslipFixture
{
    public static function document(
        int $healthMinimumTopUpMinorUnits = 0,
        int $taxBeforeCreditsMinorUnits = 718_500,
        int $taxNonRefundableCreditsMinorUnits = 257_000,
        int $taxChildCreditMinorUnits = 511_500,
        int $taxAfterCreditsMinorUnits = 0,
        int $taxBonusMinorUnits = 50_000,
    ): PayslipDocumentData {
        $netMinorUnits = 4_790_000
            - 340_090
            - 215_550
            - $healthMinimumTopUpMinorUnits
            - $taxAfterCreditsMinorUnits
            - 8_000
            + $taxBonusMinorUnits;

        return new PayslipDocumentData(
            revisionId: 'MZ16-2026-07-0001',
            sourceSnapshotSha256: hash('sha256', 'synthetic-payroll-snapshot-v1'),
            employerName: 'Ukázková společnost s.r.o.',
            employerIdentificationNumber: '00000000',
            employeeDisplayName: 'Testovací Zaměstnanec',
            period: '2026-07',
            employmentLabel: 'Pracovní poměr',
            incomeLines: [
                new PayslipLine('Základní mzda', 4_800_000),
                new PayslipLine('Korekce minulého období', -10_000),
            ],
            grossMinorUnits: 4_790_000,
            employeeSocialMinorUnits: 340_090,
            employeeHealthMinorUnits: 215_550,
            healthMinimumTopUpMinorUnits: $healthMinimumTopUpMinorUnits,
            taxBaseMinorUnits: 4_790_000,
            taxBeforeCreditsMinorUnits: $taxBeforeCreditsMinorUnits,
            taxNonRefundableCreditsMinorUnits: $taxNonRefundableCreditsMinorUnits,
            taxChildCreditMinorUnits: $taxChildCreditMinorUnits,
            taxAfterCreditsMinorUnits: $taxAfterCreditsMinorUnits,
            taxBonusMinorUnits: $taxBonusMinorUnits,
            otherDeductionLines: [
                new PayslipLine('Příspěvek na stravování', 10_000),
                new PayslipLine('Korekce srážky', -2_000),
            ],
            roundingAdjustmentMinorUnits: 0,
            netMinorUnits: $netMinorUnits,
            employerSocialMinorUnits: 1_187_920,
            employerHealthMinorUnits: 431_100,
            grossExpenseAccount: '521',
            grossLiabilityAccount: '331',
            insuranceExpenseAccount: '524',
            insuranceLiabilityAccount: '336',
        );
    }
}
