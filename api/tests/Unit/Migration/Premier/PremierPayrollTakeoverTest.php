<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Premier;

use MyInvoice\Service\Migration\Premier\PremierBackup;
use MyInvoice\Service\Migration\Premier\PremierPayroll;
use MyInvoice\Service\Migration\Premier\PremierPayrollTakeover;
use MyInvoice\Tests\Fixtures\Premier\SyntheticPremierBackup;
use PHPUnit\Framework\TestCase;

/**
 * Překlad vztahu z PREMIER do kanonické podoby převzatých mezd: odkazy a poznámky
 * zůstávají takové, jaké zapisoval převod před sdílenou vrstvou.
 */
final class PremierPayrollTakeoverTest extends TestCase
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

    public function testStatutoryPersonMapsCardEvidenceAndAccount(): void
    {
        [$statutory, $dpp] = $this->relations();
        $record = PremierPayrollTakeover::record($statutory, '2025-12-31');
        $person = $record->person;

        self::assertSame('1985-01-01', $person->identity['birth_date']);
        self::assertSame('Ing.', $person->identity['title_prefix']);
        self::assertSame('Vzorová', $person->birthSurname, 'Rodné příjmení jiné než příjmení se převezme.');
        self::assertSame('premier:per_main:rezident', $person->taxResidence?->reference);
        self::assertSame('Převzato z PREMIER: zdravotní pojišťovna 111.', $person->healthCoverage?->note);
        self::assertSame('czech', $person->socialJurisdiction?->status);
        self::assertCount(1, $person->payoutAccounts);
        self::assertSame(SyntheticPremierBackup::BANK_ACCOUNT, $person->payoutAccounts[0]->account);
        self::assertSame([['not-signed', '2025-01-01', null, 'premier:mzdy:2025-01']], array_map(
            static fn ($run): array => [$run->status, $run->from, $run->to, $run->reference],
            $person->taxDeclarations,
        ));
        self::assertSame(['1', '1', '2025-01-01', null], [$record->employment->personalNumber, $record->employment->relationKey,
            $record->employment->start, $record->employment->end]);

        $ended = PremierPayrollTakeover::record($dpp, '2025-12-31');
        self::assertSame('2025-06-30', $ended->employment->end);
        self::assertSame([], $ended->person->payoutAccounts);
        self::assertNull($ended->person->healthCoverage);
    }

    public function testPolicyKeepsPremierBehaviour(): void
    {
        $policy = PremierPayrollTakeover::policy();

        self::assertSame(['premier', 'PREMIER'], [$policy->sourceKey, $policy->label]);
        self::assertFalse($policy->strict);
        self::assertFalse($policy->addressesPerType);
        self::assertFalse($policy->verifyPayoutAccounts);
        self::assertTrue($policy->ignoreEndBeforeStart);
        self::assertTrue($policy->rewriteOwnOpenings);
        self::assertTrue($policy->checklistToleratesRuntime);
    }

    /** @return list<array<string,mixed>> */
    private function relations(): array
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'premier_takeover_' . bin2hex(random_bytes(5));
        SyntheticPremierBackup::writeDir($this->tmp, false, ['payroll' => true]);
        return PremierPayroll::fromBackup(PremierBackup::open($this->tmp))->relations;
    }
}
