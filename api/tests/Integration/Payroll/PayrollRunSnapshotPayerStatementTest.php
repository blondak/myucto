<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Sloupec `other_withholding_eligibility` (migrace 1403, „prohlášení plátce
 * o účasti na pojištění“) zůstává v databázi kvůli návratu na starší bundle,
 * ale do nových revizí mzdového běhu už nepatří: srážku § 6 odst. 4 ZDP určuje
 * výpočet z druhu vztahu a úhrnu příjmů. Uložená hodnota proto nesmí do
 * vstupního snímku ani výsledek změnit.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollRunSnapshotPayerStatementTest extends TestCase
{
    use PayrollFullFlowTrait;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    public function testNewRevisionSnapshotDoesNotCarryThePayerStatement(): void
    {
        $officeId = $this->createOffice('SRAZ', 'Syntetická účtárna srážky', '9990004322');
        $this->configureSocialInsuranceOutput($officeId);
        $this->configureHealthInsuranceOutput();
        $componentId = $this->createComponent('ODMENA_SNIMEK', 'base_wage', 'regular');
        $person = $this->createEmployment(
            $officeId,
            'Jednatel Snímkový',
            31,
            'statutory_body',
            'statutory_body',
            40,
            10_000,
            false,
            '2026-07-01',
            false,
            'managing_partner',
            false,
            'eligible',
        );
        $this->createApprovedTimeMonth($person['employment_id'], '2026-07');
        $this->createApprovedInput($person, $componentId, 300_000, 'snimek-base', '2026-07-01');

        $run = $this->runs->createRun($this->supplierId, '2026-07-01', '2026-08-15', $officeId, $this->actors[0]);
        $locked = $this->runs->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            'snimek-lock',
            $this->actors[0],
        );
        $calculated = $this->runs->calculate(
            $this->supplierId,
            (int) $run['id'],
            (int) $locked->run['row_version'],
            'snimek-calculate',
            $this->actors[0],
        );

        $json = $this->scalar(
            'SELECT input_snapshot_json FROM payroll_run_revisions WHERE supplier_id = ? AND id = ?',
            [$this->supplierId, (int) $calculated->revision['id']],
        );
        $snapshot = json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($snapshot);
        $terms = [];
        foreach ($snapshot['people'] as $snapshotPerson) {
            foreach ($snapshotPerson['employments'] as $employment) {
                $terms[] = $employment['term'];
            }
        }
        self::assertNotSame([], $terms);
        foreach ($terms as $term) {
            self::assertArrayHasKey('tax_regime', $term);
            self::assertArrayNotHasKey('other_withholding_eligibility', $term);
        }
    }
}
