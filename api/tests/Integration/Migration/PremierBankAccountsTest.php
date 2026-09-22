<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Service\Migration\Premier\PremierBackup;
use MyInvoice\Service\Migration\Premier\PremierImporter;
use MyInvoice\Tests\Fixtures\Premier\SyntheticPremierBackup;
use MyInvoice\Tests\Integration\Migration\Shared\SharedMigrationDbTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Vlastní bankový účet firmy z PREMIER patří mezi účty firmy v měnách stejně jako po
 * převodu z Money S3 a POHODY: výchozí měna dostane číslo účtu (zůstatky banky, platební
 * údaje nových faktur) a evidence účtů se na ni naváže.
 */
#[Group('integration')]
final class PremierBankAccountsTest extends SharedMigrationDbTestCase
{
    private string $tmp = '';

    protected function tearDown(): void
    {
        parent::tearDown();
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->tmp);
        }
    }

    public function testCompanyAccountIsLinkedToCurrencyOnce(): void
    {
        if (!$this->db->hasColumn('premier_import_map', 'premier_key')) {
            $this->markTestSkipped('Chybí migrace 1859 (premier_import_map).');
        }
        $pdo = $this->db->pdo();
        // Syntetický účet zálohy smí mít v testovací DB jiná firma - v transakci ho uvolníme.
        $pdo->prepare("UPDATE currencies SET account_number = CONCAT('9', account_number) WHERE account_number LIKE ?")->execute(['%' . SyntheticPremierBackup::BANK_ACCOUNT]);
        $supplierId = $this->supplier(SyntheticPremierBackup::ICO, SyntheticPremierBackup::NAME);
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'premier_acc_' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0755, true);
        $dir = $this->tmp . DIRECTORY_SEPARATOR . 'backup';
        PremierBackup::extractArchive(SyntheticPremierBackup::writeCab($this->tmp . DIRECTORY_SEPARATOR . 'zaloha.icab', $this->tmp, false, []), $dir);
        $importer = $this->container->get(PremierImporter::class);

        foreach ([SyntheticPremierBackup::YEAR1, SyntheticPremierBackup::YEAR1] as $year) {
            $protocol = $importer->run($supplierId, $this->userId, PremierBackup::open($dir), $year, false);
            self::assertFalse($protocol->hasErrors());
        }

        $account = $this->row('SELECT id, currency_id FROM supplier_bank_accounts WHERE supplier_id = ? AND account_canonical = ?', [$supplierId, SyntheticPremierBackup::BANK_ACCOUNT]);
        self::assertNotSame([], $account, 'účet firmy je v evidenci účtů');
        $currencies = $this->rows('SELECT id, code, account_number, bank_code, is_default FROM currencies WHERE supplier_id = ? ORDER BY id', [$supplierId]);
        self::assertCount(1, $currencies, 'opakovaný převod řádek měny nezdvojí');
        self::assertSame(['CZK', SyntheticPremierBackup::BANK_ACCOUNT, SyntheticPremierBackup::BANK_CODE, '1'],
            [$currencies[0]['code'], $currencies[0]['account_number'], $currencies[0]['bank_code'], (string) $currencies[0]['is_default']],
            'výchozí měna dostala číslo účtu z PREMIER');
        self::assertSame((string) $currencies[0]['id'], (string) $account['currency_id'], 'evidence účtů je navázaná na účet firmy v měně');
    }
}
