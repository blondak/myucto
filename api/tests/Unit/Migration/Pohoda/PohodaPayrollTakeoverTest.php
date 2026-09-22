<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Pohoda;

use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollPeople;
use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollTakeover;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverRecord;
use MyInvoice\Tests\Fixtures\Pohoda\SyntheticPohodaPayroll;
use PHPUnit\Framework\TestCase;

/**
 * Překlad záznamu PAMICA do kanonické podoby převzatých mezd: odkazy na zdroj
 * a poznámky zůstávají přesně takové, jaké zapisoval převod před sdílenou vrstvou.
 */
final class PohodaPayrollTakeoverTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pohoda_takeover_' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->tmp);
    }

    public function testRecordCarriesPamicaReferencesAndNotes(): void
    {
        $jana = $this->records()['1001'];
        $person = $jana->person;

        self::assertSame('czech-resident', $person->taxResidence?->status);
        self::assertSame('pamica:zam:rezident', $person->taxResidence?->reference);
        self::assertSame('Převzato z PAMICA: zaměstnanec není v PAMICA veden jako daňový nerezident.', $person->taxResidence?->note);
        self::assertSame('czech', $person->socialJurisdiction?->status);
        self::assertNotSame([], $person->taxDeclarations);
        self::assertStringStartsWith('pamica:mz-prohlas:', (string) $person->taxDeclarations[0]->reference);
        self::assertSame('2026-03-10', $person->payoutAccountsPaidOn);
        self::assertNotSame([], $person->payoutAccounts);
        self::assertTrue($person->payoutAccounts[0]->active);
        foreach ($person->children as $child) {
            self::assertStringStartsWith('pamica:zampdet:', $child['reference']);
        }
        self::assertArrayHasKey(1, $person->openingMonths);

        $relation = $jana->employment;
        self::assertSame('1001', $relation->personalNumber);
        self::assertSame('2026-01', $relation->transferStart);
        self::assertStringStartsWith('Převzato z PAMICA: vztah vedený v předchozím mzdovém systému, nástup ', $relation->checklistNotes['employment_contract'] ?? '');
    }

    public function testPolicyKeepsPamicaBehaviour(): void
    {
        $policy = PohodaPayrollTakeover::policy();

        self::assertSame(['pamica', 'PAMICA'], [$policy->sourceKey, $policy->label]);
        self::assertTrue($policy->strict);
        self::assertTrue($policy->verifyPayoutAccounts);
        self::assertFalse($policy->rewriteOwnOpenings);
        self::assertFalse($policy->checklistToleratesRuntime);
    }

    /** @return array<string,PayrollTakeoverRecord> */
    private function records(): array
    {
        $out = [];
        foreach (PohodaPayrollPeople::read(SyntheticPohodaPayroll::write($this->tmp), SyntheticPohodaPayroll::YEAR) as $record) {
            $out[(string) $record['personal_number']] = PohodaPayrollTakeover::record($record);
        }
        return $out;
    }
}
