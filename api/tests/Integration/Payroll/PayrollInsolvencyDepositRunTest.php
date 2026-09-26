<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Repository\Payroll\PayrollEnforcementPaymentRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Tests\Support\EnforcementRunFixtureTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Zahájené insolvenční řízení v celém mzdovém běhu: sráží se v rozsahu
 * dosavadní exekuce a sražená částka se deponuje — ani případ, který jinak
 * odesílá, nesmí dostat platební závazek (§ 109 odst. 1 písm. c) IZ, R 4/2020).
 *
 * Dřív režim `alert_only` shodil osobu do ručního posouzení a celý běh stál.
 */
#[Group('integration')]
final class PayrollInsolvencyDepositRunTest extends TestCase
{
    use EnforcementRunFixtureTrait;

    protected function setUp(): void
    {
        $this->bootEnforcementRun();
    }

    protected function tearDown(): void
    {
        $this->tearDownEnforcementRun();
    }

    public function testCommencedInsolvencyWithholdsAndDepositsEvenForRemittingCase(): void
    {
        $caseId = $this->seedRunCase('remit');
        $claimId = $this->seedRunClaim($caseId);
        $this->seedRunMonthEvidence(insolvencyMode: 'alert_only', insolvencyVerified: true);

        $run = $this->calculateEnforcementRun();

        self::assertSame('supported', $run['enforcement']['status'], CanonicalJson::encode($run['enforcement']));
        self::assertNotContains('enforcement_manual_review', $run['validation_codes']);
        // Stejná srážka jako u exekuce bez insolvence (viz
        // PayrollEnforcementEvidenceScopeRunTest): třetina 3 862 Kč.
        self::assertSame(386_200, $run['enforcement']['total_withheld_minor_units']);

        self::assertSame([], array_values(array_filter(
            array_map(static fn (array $v): string => $v['severity'] . ':' . $v['code'] . ':' . $v['message'], $run['validations']),
            static fn (string $v): bool => str_starts_with($v, 'blocker'),
        )));
        $this->approveEnforcementRun($run);

        $ledger = $this->enforcementLedger();
        $byKind = [];
        foreach ($ledger as $row) {
            $byKind[$row['entry_kind']] = ($byKind[$row['entry_kind']] ?? 0) + $row['amount_minor_units'];
        }
        self::assertSame(381_200, $byKind['withheld'] ?? null, CanonicalJson::encode($ledger));
        self::assertSame(381_200, $byKind['held'] ?? null, 'Sražená částka se nedeponovala.');
        self::assertSame(5_000, $byKind['employer_fee'] ?? null);
        self::assertSame($claimId, $ledger[0]['claim_id']);

        $remittable = (new PayrollEnforcementPaymentRepository($this->db))
            ->remittableForRevision($this->supplierId, $run['revision_id']);
        self::assertSame(0, $remittable[0]['remittable_minor']);
    }
}
