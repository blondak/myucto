<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Document;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Repository\Payroll\PayrollDocumentAccessLinkRepository;
use MyInvoice\Repository\Payroll\PayrollDocumentRepository;
use MyInvoice\Repository\Payroll\PayrollEmployerPolicyRepository;
use MyInvoice\Repository\Payroll\PayrollModuleStateRepository;
use MyInvoice\Repository\SupplierDomainRepository;
use MyInvoice\Service\Mail\Mailer;
use MyInvoice\Service\Payroll\Document\Delivery\PayrollDeliveryRecipientResolver;
use MyInvoice\Service\Payroll\Document\Delivery\PayrollSecureDeliveryBlockedException;
use MyInvoice\Service\Payroll\Document\Delivery\PayrollSecureDeliveryPolicy;
use MyInvoice\Service\Payroll\Document\Delivery\PayrollSecureDeliveryService;
use MyInvoice\Service\Payroll\Document\PayrollDocumentDeliveryLedgerService;
use MyInvoice\Service\Payroll\PayrollProductionGate;
use MyInvoice\Service\Tenant\TenantUrlResolver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Hromadné rozeslání výplatních pásek a cesta fronta → odeslání.
 *
 * Bez DB a bez SMTP: repozitáře jsou doubly a mailer je mock, takže test nemůže
 * nic skutečně odeslat. Brána je SKUTEČNÁ ({@see PayrollSecureDeliveryPolicy}),
 * aby hromadná akce nemohla projít kolem kterékoli z jejích podmínek.
 */
#[Group('unit')]
final class PayrollSecureDeliveryBulkTest extends TestCase
{
    private const SUPPLIER = 7;
    private const RUN = 30;
    private const REVISION = 31;

    /**
     * Každá páska jde touž cestou jako jednotlivé odeslání: kdo má papír,
     * přeskočí se s důvodem, už zařazená páska se započte, zbytek se zařadí.
     */
    public function testBulkQueuesEveryOptedInPayslipAndExplainsTheRest(): void
    {
        $links = $this->createStub(PayrollDocumentAccessLinkRepository::class);
        $links->method('deadLinkGeneration')->willReturn(0);
        $links->method('create')->willReturnCallback(
            static fn (int $supplier, int $documentId): array => [
                'id' => 900 + $documentId,
                // Dokument 103 už odkaz má z dřívějška — idempotence ho nezaloží znovu.
                'created' => $documentId !== 103,
            ],
        );
        $links->method('find')->willReturnCallback(static fn (int $supplier, int $linkId): array => [
            'id' => $linkId,
            'recipient_masked' => 's***@example.invalid',
            'expires_at' => '2026-08-31 00:00:00',
        ]);

        $service = $this->service(
            $links,
            $this->documents(),
            $this->recipients(['11' => 'portal', '12' => 'paper', '13' => 'portal']),
            $this->createStub(Mailer::class),
        );

        $result = $service->enqueueRevisionPayslips(self::SUPPLIER, self::RUN, self::REVISION, 5);

        self::assertSame(3, $result['total']);
        self::assertSame(1, $result['queued']);
        self::assertSame(1, $result['already_queued']);
        self::assertCount(1, $result['skipped']);
        self::assertSame(102, $result['skipped'][0]['document_id']);
        self::assertSame('employee_prefers_paper', $result['skipped'][0]['reason']);
        self::assertSame('Syntetická Dvojka', $result['skipped'][0]['employee_name']);
    }

    /**
     * Zavřená brána zaměstnavatele zastaví celou dávku dřív, než vznikne jediný
     * odkaz — vypisovat tentýž důvod u každé osoby by nic nepřineslo.
     */
    public function testClosedEmployerGateStopsTheWholeBatchBeforeAnyLink(): void
    {
        $links = $this->createMock(PayrollDocumentAccessLinkRepository::class);
        $links->expects(self::never())->method('create');

        $service = $this->service(
            $links,
            $this->documents(),
            $this->recipients(['11' => 'portal', '12' => 'portal', '13' => 'portal']),
            $this->createStub(Mailer::class),
            employerChannel: 'manual_handover',
        );

        try {
            $service->enqueueRevisionPayslips(self::SUPPLIER, self::RUN, self::REVISION, 5);
            self::fail('Bez portálového kanálu se nesmí zařadit nic.');
        } catch (PayrollSecureDeliveryBlockedException $exception) {
            self::assertSame('employer_channel_not_portal', $exception->reasonCode());
        }
    }

    /**
     * Fronta → worker → e-mail. Odkaz jde jen do schránky zaměstnance; v DB
     * zůstane otisk tokenu a odeslání se zapíše do evidence doručení.
     */
    public function testQueuedLinkIsDispatchedByTheWorker(): void
    {
        $links = $this->createMock(PayrollDocumentAccessLinkRepository::class);
        $links->method('claimNext')->willReturn([
            'supplier_id' => self::SUPPLIER,
            'id' => 901,
            'payroll_document_id' => 101,
            'lease_token' => 'lease-1',
            'employee_id' => 11,
            'recipient_email_hash' => 'hash-11',
            'expires_at' => '2026-08-31 00:00:00',
            'attempt_count' => 1,
        ]);
        $links->expects(self::once())->method('attachToken')
            ->with(self::SUPPLIER, 901, self::matchesRegularExpression('/^[0-9a-f]{64}$/'), 'lease-1')
            ->willReturn(true);
        $links->expects(self::once())->method('markSent')
            ->with(self::SUPPLIER, 901, 'lease-1')
            ->willReturn(true);

        $mailer = $this->createMock(Mailer::class);
        $mailer->expects(self::once())->method('sendTemplate')->with(
            PayrollSecureDeliveryService::TEMPLATE_CODE,
            'cs',
            ['synthetic@example.invalid'],
            self::callback(static fn (array $vars): bool =>
                str_contains((string) $vars['url'], '/payroll-document/')
                && $vars['documentKind'] === 'payslip'),
        );

        $ledger = $this->createMock(PayrollDocumentDeliveryLedgerService::class);
        $ledger->expects(self::once())->method('recordChannelEvent')
            ->with(self::SUPPLIER, 101, 'secure_link_sent');

        $service = $this->service(
            $links,
            $this->documents(),
            $this->recipients(['11' => 'portal']),
            $mailer,
            ledger: $ledger,
        );

        $step = $service->dispatchOne();

        self::assertSame(['processed' => true, 'succeeded' => true, 'link_id' => 901], $step);
    }

    private function service(
        PayrollDocumentAccessLinkRepository $links,
        PayrollDocumentRepository $documents,
        PayrollDeliveryRecipientResolver $recipients,
        Mailer $mailer,
        string $employerChannel = 'employee_portal',
        ?PayrollDocumentDeliveryLedgerService $ledger = null,
    ): PayrollSecureDeliveryService {
        $states = $this->createStub(PayrollModuleStateRepository::class);
        $states->method('get')->willReturn(['status' => 'active']);
        $policies = $this->createStub(PayrollEmployerPolicyRepository::class);
        $policies->method('findEffective')->willReturn([
            'delivery_channel' => $employerChannel,
            'delivery_verified_on' => '2026-01-01',
        ]);
        $domains = $this->createStub(SupplierDomainRepository::class);
        $domains->method('primaryForSupplier')->willReturn(null);
        $urls = new TenantUrlResolver(
            new Config(['app' => ['url' => 'https://synthetic.invalid']]),
            $domains,
        );

        return new PayrollSecureDeliveryService(
            $links,
            $documents,
            $ledger ?? $this->createStub(PayrollDocumentDeliveryLedgerService::class),
            new PayrollSecureDeliveryPolicy(
                new Config(['payroll' => ['secure_delivery' => ['enabled' => true]]]),
                new PayrollProductionGate($states),
                $policies,
            ),
            $recipients,
            $mailer,
            $urls,
            new NullLogger(),
        );
    }

    private function documents(): PayrollDocumentRepository
    {
        $rows = [];
        foreach ([101 => [11, 'Syntetická Jednička'], 102 => [12, 'Syntetická Dvojka'], 103 => [13, 'Syntetická Trojka']] as $id => [$employee, $name]) {
            $rows[$id] = [
                'id' => $id,
                'employee_id' => $employee,
                'employee_name' => $name,
                'document_kind' => 'payslip',
                'period_start' => '2026-07-01',
                'file_sha256' => str_repeat('a', 64),
            ];
        }
        $documents = $this->createStub(PayrollDocumentRepository::class);
        $documents->method('currentPayslipsForRevision')->willReturn(array_values($rows));
        $documents->method('find')->willReturnCallback(
            static fn (int $supplier, int $documentId): ?array => $rows[$documentId] ?? null,
        );

        return $documents;
    }

    /** @param array<string,string> $channels employee_id → secure_delivery_channel */
    private function recipients(array $channels): PayrollDeliveryRecipientResolver
    {
        $recipients = $this->createStub(PayrollDeliveryRecipientResolver::class);
        $recipients->method('resolve')->willReturnCallback(
            static fn (int $supplier, int $employeeId): array => [
                'employee_id' => $employeeId,
                'contact_id' => $employeeId,
                'email_hash' => "hash-{$employeeId}",
                'masked' => 's***@example.invalid',
                'ciphertext' => 'enc:synthetic',
                'secure_delivery_channel' => $channels[(string) $employeeId] ?? 'paper',
            ],
        );
        $recipients->method('plaintextEmail')->willReturn('synthetic@example.invalid');

        return $recipients;
    }
}
