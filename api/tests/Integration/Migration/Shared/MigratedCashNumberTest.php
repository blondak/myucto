<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration\Shared;

use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\Shared\MigratedCashNumber;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class MigratedCashNumberTest extends SharedMigrationDbTestCase
{
    public function testFirstFreeCandidateWinsAndLastGetsSuffixWhenAllTaken(): void
    {
        $supplier = $this->supplier();
        $numbers = new MigratedCashNumber($this->db);
        $protocol = new ImportProtocol('import');

        self::assertSame('P1', $numbers->allocate($supplier, ['P1', 'P1/2025'], $protocol, 'cash', 'P1'));
        $this->cash($supplier, 'P1');
        self::assertSame('P1/2025', $numbers->allocate($supplier, ['P1', 'P1/2025'], $protocol, 'cash', 'P1'));
        self::assertSame([], $protocol->toArray()['steps'] ?? [], 'volný kandidát nic nehlásí');

        $this->cash($supplier, 'P1/2025');
        self::assertSame('P1/2025-2', $numbers->allocate($supplier, ['P1', 'P1/2025'], $protocol, 'cash', 'P1'));
        $this->cash($supplier, 'P1/2025-2');
        self::assertSame('P1/2025-3', $numbers->allocate($supplier, ['P1', 'P1/2025'], $protocol, 'cash', 'P1'));
        self::assertSame(['cash_number_duplicate', 'cash_number_duplicate'], array_column($this->messages($protocol), 'code'));
    }

    public function testSuffixFitsIntoColumnLimit(): void
    {
        $supplier = $this->supplier();
        $long = str_repeat('Ř', 40);
        $this->cash($supplier, mb_substr($long, 0, 30));
        $number = (new MigratedCashNumber($this->db))->allocate($supplier, [$long], new ImportProtocol('import'), 'cash', 'dlouhé');
        self::assertSame(str_repeat('Ř', 28) . '-2', $number);
    }

    private function cash(int $supplier, string $number): void
    {
        $pdo = $this->db->pdo();
        $find = $pdo->prepare('SELECT id FROM cash_registers WHERE supplier_id = ?');
        $find->execute([$supplier]);
        $register = (int) $find->fetchColumn();
        if ($register === 0) {
            $pdo->prepare("INSERT INTO cash_registers (supplier_id, name, account_code, currency_code, is_active) VALUES (?, 'Pokladna', '211', 'CZK', 1)")->execute([$supplier]);
            $register = (int) $pdo->lastInsertId();
        }
        $pdo->prepare("INSERT INTO cash_documents (supplier_id, register_id, doc_type, purpose, doc_number, issue_date, description, total_amount, currency_code, status)
                       VALUES (?, ?, 'in', 'other', ?, '2025-01-02', 'Vklad', 100, 'CZK', 'posted')")->execute([$supplier, $register, $number]);
    }

    /** @return list<array<string,mixed>> */
    private function messages(ImportProtocol $protocol): array
    {
        $out = [];
        foreach ($protocol->toArray()['steps'] as $step) {
            foreach ($step['messages'] ?? [] as $m) {
                $out[] = $m;
            }
        }
        return $out;
    }
}
