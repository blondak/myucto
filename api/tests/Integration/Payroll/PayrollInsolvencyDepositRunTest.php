<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Repository\Payroll\PayrollEnforcementPaymentRepository;
use MyInvoice\Repository\Payroll\PayrollEnforcementRepository;
use MyInvoice\Repository\Payroll\PayrollInstitutionAccountRepository;
use MyInvoice\Service\Payroll\Garnishment\EnforcementCaseCommand;
use MyInvoice\Service\Payroll\Garnishment\EnforcementCaseLifecycle;
use MyInvoice\Service\Payroll\Garnishment\EnforcementDecisionDocumentReference;
use MyInvoice\Service\Payroll\Payment\PayrollEnforcementLiabilityMaterializer;
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

    /**
     * Celý tok: zahájení → sraženo a deponováno → schválené oddlužení →
     * depozit se vydá insolvenčnímu správci → platební závazek na jeho účet.
     * Pohledávka oprávněného se vydáním NEUMOŘÍ.
     */
    public function testDepositIsHandedOverToAdministratorAndBecomesPayable(): void
    {
        $caseId = $this->seedRunCase('remit');
        $claimId = $this->seedRunClaim($caseId, outstandingMinor: 500_000);
        $this->seedRunMonthEvidence(insolvencyMode: 'alert_only', insolvencyVerified: true);
        $run = $this->calculateEnforcementRun();
        $this->approveEnforcementRun($run);

        $repository = $this->service(PayrollEnforcementRepository::class);
        $institutions = $this->service(PayrollInstitutionAccountRepository::class);
        $materializer = $this->service(PayrollEnforcementLiabilityMaterializer::class);

        $account = $institutions->create($this->supplierId, [
            'institution_type' => 'other_recipient',
            'institution_code' => 'ISPRAVCE',
            'institution_name' => 'Syntetický insolvenční správce',
            'bank_account' => '1000000005/0100',
            'currency_code' => 'CZK',
            'variable_symbol' => '1234567890',
            'specific_symbol' => null,
            'constant_symbol' => null,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'source_kind' => 'official_document',
            'source_reference' => 'synthetic:insolvency-administrator',
            'verified_on' => date('Y-m-d'),
        ], $this->actorId);
        $documentId = $this->decisionDocument();
        $sha = (string) $this->db->pdo()
            ->query("SELECT sha256 FROM documents WHERE id = {$documentId}")
            ->fetchColumn();
        $case = $repository->findCase($this->supplierId, $caseId);
        self::assertSame(381_200, $case['settlement']['held_minor']);

        $updated = $repository->transition(
            $this->supplierId,
            $caseId,
            EnforcementCaseCommand::ReleaseToAdministrator,
            (int) $case['row_version'],
            'Schváleno oddlužení, depozit patří do majetkové podstaty.',
            new EnforcementDecisionDocumentReference($documentId, $sha),
            $this->actorId,
            new EnforcementCaseLifecycle(),
            (int) $account['id'],
        );

        self::assertSame('deferred_no_withholding', $updated['status']);
        self::assertSame(0, $updated['settlement']['held_minor']);
        self::assertSame(381_200, $updated['settlement']['administrator_minor']);
        // Oprávněný nic nedostal: zbývá srazit celá pohledávka.
        self::assertSame(500_000, $updated['settlement']['remaining_to_withhold_minor']);

        $liabilities = $materializer->materialize($this->supplierId, $run['revision_id'], $this->actorId);
        self::assertSame(1, $liabilities['created_count']);
        $liability = $this->db->pdo()->query(
            'SELECT liability_reference, amount_minor, due_on, direction
               FROM payroll_payment_liabilities WHERE id = ' . (int) $liabilities['liability_ids'][0],
        )->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(381_200, (int) $liability['amount_minor']);
        self::assertSame('outgoing', $liability['direction']);
        self::assertStringStartsWith("enforcement:c{$caseId}:cl{$claimId}:administrator:e", $liability['liability_reference']);
        self::assertSame(date('Y-m-d'), $liability['due_on']);

        // Druhé vydání téhož depozita nepřipustí ani databáze.
        $this->expectException(\DomainException::class);
        $repository->transition(
            $this->supplierId,
            $caseId,
            EnforcementCaseCommand::ReleaseToAdministrator,
            (int) $updated['row_version'],
            'Podruhé.',
            new EnforcementDecisionDocumentReference($documentId, $sha),
            $this->actorId,
            new EnforcementCaseLifecycle(),
            (int) $account['id'],
        );
    }

    private function decisionDocument(): int
    {
        $hash = hash('sha256', 'insolvency-approval:' . $this->supplierId);
        $this->db->pdo()->prepare(
            'INSERT INTO documents
                (supplier_id, title, original_name, filename, sha256, mime_type,
                 size_bytes, doc_type, source, uploaded_by, scope, owner_user_id)
             VALUES (?, "Syntetické schválení oddlužení", "approval.pdf",
                     ?, ?, "application/pdf", 1, "pdf", "manual", ?, "company", NULL)',
        )->execute([$this->supplierId, "{$hash}.pdf", $hash, $this->actorId]);

        return (int) $this->db->pdo()->lastInsertId();
    }
}
