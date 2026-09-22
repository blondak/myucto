<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Premier;

use MyInvoice\Service\Migration\Premier\PremierBackup;
use MyInvoice\Service\Migration\Premier\PremierPayroll;
use MyInvoice\Service\Migration\Premier\PremierPayrollInstitutions;
use MyInvoice\Service\Migration\Premier\PremierPayrollPersonCard;
use MyInvoice\Service\Migration\Premier\PremierPayrollTakeover;
use MyInvoice\Tests\Fixtures\Premier\SyntheticPremierBackup;
use PHPUnit\Framework\TestCase;

/**
 * Karta osoby a příjemci odvodů ze syntetické zálohy PREMIER: děti a uplatnění
 * zvýhodnění, důchod, historie výplatního účtu, registr pojišťoven a účty odvodů.
 */
final class PremierPayrollPersonCardTest extends TestCase
{
    private string $tmp = '';

    protected function tearDown(): void
    {
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            foreach (scandir($this->tmp) ?: [] as $f) {
                if (is_file($this->tmp . DIRECTORY_SEPARATOR . $f)) {
                    unlink($this->tmp . DIRECTORY_SEPARATOR . $f);
                }
            }
            rmdir($this->tmp);
        }
    }

    public function testChildrenPensionAndAccountHistory(): void
    {
        $card = PremierPayrollPersonCard::read($this->backup());
        self::assertSame(['OS-E'], array_keys($card['children']), 'Děti se k osobě váží přes PER_MAIN.SUP_INTER.');
        [$credited, $without] = $card['children']['OS-E'];
        self::assertSame(['D1', '180420/0101', 13, true], [$credited['id'], $credited['birth_number'], count($credited['periods']), $credited['other_caregiver']]);
        self::assertSame([], $without['periods']);
        self::assertSame([6 => ['from' => '2020-01-01', 'kind' => '8']], $card['pensions']);
        self::assertSame([
            ['account' => '19-2000145399', 'bank_code' => '0800', 'from' => '2025-01-01'],
            ['account' => SyntheticPremierBackup::BANK_ACCOUNT, 'bank_code' => SyntheticPremierBackup::BANK_CODE, 'from' => '2025-09-01'],
        ], $card['accounts'][5]);
    }

    public function testChildClaimRunsFromActualCredit(): void
    {
        $relations = PremierPayroll::fromBackup($this->backup())->relations;
        $employee = array_values(array_filter($relations, static fn (array $r): bool => $r['key'] === '5'))[0];

        $open = PremierPayrollTakeover::children($employee, '2026-12-31');
        self::assertSame([1, '2025-01-01', null], [$open['children'][0]['order'], $open['children'][0]['from'], $open['children'][0]['to']],
            'Zvýhodnění uplatněné v posledním měsíci mezd osoby trvá.');
        self::assertSame([1, 0, 1], [$open['without_credit'], $open['without_birth_number'], $open['other_caregiver']]);

        $employee['person_last_period'] = '2026-06';
        self::assertSame('2026-01-31', PremierPayrollTakeover::children($employee, '2026-12-31')['children'][0]['to'],
            'Uplatnění skončilo dřív než mzdy osoby: nárok končí posledním uplatněným měsícem.');

        $record = PremierPayrollTakeover::record($employee, '2025-12-31');
        self::assertSame(['2025-01', 'premier:mz_deti:D1'], [$record->person->firstSignedPeriod, $record->person->children[0]['reference']]);
        self::assertSame([true, false], array_map(static fn ($a): bool => $a->active, $record->person->payoutAccounts),
            'Aktuální účet je výplatní, dřívější z historie bez výplat.');
    }

    public function testInstitutionsFromInsurerRegistryAndPayrollSettings(): void
    {
        $institutions = PremierPayrollInstitutions::read($this->backup());
        self::assertSame([
            ['health_insurer', '111', SyntheticPremierBackup::BANK_ACCOUNT, '0710', null],
            ['health_insurer', '201', '2000145399', '0100', null],
            ['social_security', null, '21012-' . SyntheticPremierBackup::BANK_ACCOUNT, '0710', null],
            ['tax_office', 'ADVANCE_TAX', '713-' . SyntheticPremierBackup::BANK_ACCOUNT, '0710', null],
            ['tax_office', 'WITHHOLDING_TAX', '7720-' . SyntheticPremierBackup::BANK_ACCOUNT, '0710', null],
        ], array_map(static fn (array $i): array => [$i['type'], $i['code'], $i['account'], $i['bank_code'], $i['issue']], $institutions),
            'Penzijní fond příjemcem odvodů není.');
        self::assertSame('1234567890', $institutions[2]['variable_symbol']);

        $withoutSettings = PremierPayrollInstitutions::read($this->backup([]));
        self::assertSame(['missing', 'missing', 'missing'], array_column(array_values(array_filter($withoutSettings,
            static fn (array $i): bool => $i['type'] !== 'health_insurer')), 'issue'));
    }

    /** @param array<string,bool>|null $flags */
    private function backup(?array $flags = null): PremierBackup
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'premier_card_' . bin2hex(random_bytes(5));
        SyntheticPremierBackup::writeDir($this->tmp, false, $flags ?? ['payroll' => true, 'payroll_detail' => true]);
        return PremierBackup::open($this->tmp);
    }
}
