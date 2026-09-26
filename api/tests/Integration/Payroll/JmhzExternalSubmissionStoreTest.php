<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzExternalSubmissionStore;
use MyInvoice\Service\Payroll\Security\PayrollRevealPurpose;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Historie podání předchozím programem: opakovaný import téhož podání řádek přepíše
 * (nebo nechá), nezdvojí; obsah leží zapečetěný a jde přečíst jen v kontextu řádku.
 */
#[Group('integration')]
final class JmhzExternalSubmissionStoreTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private JmhzExternalSubmissionStore $store;
    private PayrollSensitiveData $sensitive;
    private int $supplierId = 0;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        if (!$this->db->hasTable('payroll_external_jmhz_submissions')) {
            self::markTestSkipped('Chybí tabulka payroll_external_jmhz_submissions (migrace 1901).');
        }
        $this->store = $container->get(JmhzExternalSubmissionStore::class);
        $this->sensitive = $container->get(PayrollSensitiveData::class);
        $pdo = $this->db->pdo();
        $source = (int) ($pdo->query('SELECT MIN(id) FROM supplier')?->fetchColumn() ?: 0);
        if ($source === 0) {
            self::markTestSkipped('Chybí výchozí firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    public function testRepeatedImportUpdatesInsteadOfDuplicating(): void
    {
        $first = $this->store->store($this->supplierId, 'production', JmhzExternalSubmissionStore::SOURCE_PAMICA,
            self::submission('R', 'sent'), [self::form(1, 'Syntetická'), self::form(2, 'Zkušební')], null);
        self::assertSame('created', $first['status']);

        $same = $this->store->store($this->supplierId, 'production', JmhzExternalSubmissionStore::SOURCE_PAMICA,
            self::submission('R', 'sent'), [self::form(1, 'Syntetická'), self::form(2, 'Zkušební')], null);
        self::assertSame(['id' => $first['id'], 'status' => 'unchanged'], $same);

        $changed = $this->store->store($this->supplierId, 'production', JmhzExternalSubmissionStore::SOURCE_PAMICA,
            self::submission('R', 'sent'), [self::form(1, 'Opravená')], null);
        self::assertSame(['id' => $first['id'], 'status' => 'updated'], $changed);

        self::assertSame(1, $this->rowCount('payroll_external_jmhz_submissions'));
        self::assertSame(1, $this->rowCount('payroll_external_jmhz_submission_forms'));
        $row = $this->db->pdo()->prepare('SELECT form_count, payload_ciphertext, payload_sha256 FROM payroll_external_jmhz_submissions WHERE supplier_id = ? AND id = ?');
        $row->execute([$this->supplierId, $first['id']]);
        $stored = $row->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(1, (int) $stored['form_count']);
        self::assertStringStartsWith('enc:v2:', (string) $stored['payload_ciphertext'], 'Obsah podání leží zapečetěný.');
        $plain = $this->sensitive->reveal((string) $stored['payload_ciphertext'], PayrollSensitiveField::EXTERNAL_JMHZ_PAYLOAD,
            $this->supplierId, $first['id'], PayrollRevealPurpose::ANONYMIZATION);
        self::assertSame(hash('sha256', $plain), $stored['payload_sha256']);
        self::assertSame(['program' => 'PAMICA', 'summary' => [['id' => 10029, 'value' => '15000']]], json_decode($plain, true));

        $form = $this->db->pdo()->prepare('SELECT payload_ciphertext, id FROM payroll_external_jmhz_submission_forms WHERE supplier_id = ?');
        $form->execute([$this->supplierId]);
        $formRow = $form->fetch(\PDO::FETCH_ASSOC);
        self::assertStringContainsString('Opravená', $this->sensitive->reveal((string) $formRow['payload_ciphertext'],
            PayrollSensitiveField::EXTERNAL_JMHZ_PAYLOAD, $this->supplierId, (int) $formRow['id'], PayrollRevealPurpose::ANONYMIZATION));
    }

    public function testSentMonthlyIgnoresUnsentAndCancellation(): void
    {
        $this->store->store($this->supplierId, 'production', JmhzExternalSubmissionStore::SOURCE_PAMICA,
            ['source_key' => 'MH:9'] + self::submission('R', 'not_sent'), [], null);
        self::assertNull($this->store->sentMonthly($this->supplierId, 'production', '2026-02'), 'Neodeslané hlášení za měsíc nic nepodalo.');

        $this->store->store($this->supplierId, 'production', JmhzExternalSubmissionStore::SOURCE_JMHZ_XML,
            ['source_key' => 'xml:1'] + self::submission('R', 'sent'), [], null);
        $sent = $this->store->sentMonthly($this->supplierId, 'production', '2026-02');
        self::assertSame(JmhzExternalSubmissionStore::SOURCE_JMHZ_XML, $sent['source'] ?? null);
        self::assertNull($this->store->sentMonthly($this->supplierId, 'test', '2026-02'), 'Prostředí se nemíchají.');
        self::assertNull($this->store->sentMonthly($this->supplierId, 'production', '2026-03'));

        $overview = $this->store->overview($this->supplierId, 'production');
        self::assertSame(['sent', 'not_sent'], array_column($overview, 'status'));
    }

    /** @return array<string,mixed> */
    private static function submission(string $type, string $status): array
    {
        return [
            'source_key' => 'MH:1',
            'document_kind' => 'monthly',
            'period' => '2026-02',
            'submission_type' => $type,
            'submission_guid' => '22222222-2222-4222-8222-222222222222',
            'corrected_source_key' => null,
            'status' => $status,
            'filled_at' => '2026-03-16T09:00:00',
            'submitted_at' => $status === 'sent' ? '2026-03-16T09:00:00' : null,
            'accepted_at' => $status === 'sent' ? '2026-03-16T09:05:00' : null,
            'program' => 'PAMICA',
            'file_name' => null,
            'payload' => ['program' => 'PAMICA', 'summary' => [['id' => 10029, 'value' => '15000']]],
        ];
    }

    /** @return array<string,mixed> */
    private static function form(int $position, string $name): array
    {
        return [
            'position' => $position,
            'form_guid' => sprintf('AAAAAAAA-AAAA-4AAA-8AAA-%012d', $position),
            'form_type' => 'R',
            'source_relation_ref' => (string) $position,
            'employee_id' => null,
            'employment_id' => null,
            'payload' => ['attributes' => [['id' => 10053, 'value' => $name]]],
        ];
    }

    private function rowCount(string $table): int
    {
        $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM {$table} WHERE supplier_id = ?");
        $stmt->execute([$this->supplierId]);

        return (int) $stmt->fetchColumn();
    }
}
