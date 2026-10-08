<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Nulové měsíční hlášení: měsíc, ve kterém zaměstnání trvá, ale nic se
 * nezúčtovalo.
 *
 * Zákon č. 323/2025 Sb. ukládá podávat hlášení za každý měsíc, dokud trvá
 * zaměstnání (§ 7 odst. 2 písm. a): naposledy za měsíc, ve kterém skončilo
 * zaměstnání posledního zaměstnance). Trvající vztah bez příjmu se proto
 * hlásí nulovým formulářem osoby (Pravidla podání JMHZ 1.4.5, kap. 4,
 * „řádný formulář s nulovými hodnotami“; Q&A MPSV k webináři 11. 5. 2026,
 * otázky 53 a 61: DPP bez příjmu se hlásí nulovým hlášením). Podání bez
 * jediného formuláře osoby ČSSZ jako řádné odmítne (kontrola 232).
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollJmhzZeroMonthFlowTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const PERIOD = '2026-07';
    private const PERIOD_START = '2026-07-01';
    private const PAYDAY = '2026-08-14';

    private int $officeId;
    private int $baseComponentId;
    private int $sequence = 0;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $this->officeId = $this->createOffice('JMHZ0', 'Syntetická registrace JMHZ', '1100004322');
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->configureHealthInsuranceOutput();
        $this->baseComponentId = $this->createComponent('MZDA_NULA_FLOW', 'base_wage', 'regular');
        $mappings = $this->container->get(PayrollComponentJmhzMappingRepository::class);
        self::assertInstanceOf(PayrollComponentJmhzMappingRepository::class, $mappings);
        $mappings->put($this->supplierId, $this->baseComponentId, '10329', null, $this->actors[0]);
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    /**
     * DPP trvá, v měsíci se nepracovalo ani nic nezúčtovalo. FAQ ČSSZ
     * (9. 6. 2026) k DPP „do limitu“: 10354/10355 se vyplní, 10356 = 0
     * a kód ELDP 10240 se neuvádí. Průměrný výdělek dohoda bez jediného
     * výdělku nemá; 10345 je v nulovém formuláři nula (Pravidla podání
     * 1.4.5, kap. 4). Dřív tu příprava skončila na chybějícím průměru.
     *
     * Nulovou mzdu účetní zadá výslovně (0 Kč u základní složky): měsíc bez
     * jediného vstupu běh dál zastaví jako pravděpodobně zapomenutou mzdu.
     */
    public function testAgreementWithoutIncomeIsReportedWithZeroForm(): void
    {
        $person = $this->hire('Nina Nulová', 'female', '1995-06-06');
        $response = $this->approveTimeMonth($person['employment_id'], self::PERIOD, [], dailyMinutes: 240);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->createApprovedInput($person, $this->baseComponentId, 0, 'zero-' . $person['employment_id'], self::PERIOD_START);

        $xml = $this->submission('zero-agreement');

        self::assertSame(1, substr_count($xml, '</formularOsoby>'));
        self::assertStringContainsString('<form:zuctovanoCelkem>0</form:zuctovanoCelkem>', $xml);
        self::assertStringContainsString(
            '<form:trvani><form:pojisteniOd>2026-07-01</form:pojisteniOd><form:pojisteniDo>2026-07-31</form:pojisteniDo></form:trvani>',
            $xml,
        );
        self::assertStringContainsString('<form:eldp><form:pocetDnu>0</form:pocetDnu></form:eldp>', $xml);
        self::assertStringNotContainsString('<form:kod>', $xml);
        self::assertStringNotContainsString('<form:mzdaRozpad>', $xml);
        self::assertStringContainsString('<form:vydelekPrumernyHod>0.00</form:vydelekPrumernyHod>', $xml);
        self::assertStringContainsString('<pvpoj:pojistneCelkem>0</pvpoj:pojistneCelkem>', $xml);
    }

    /**
     * Měsíc bez jakéhokoli vstupu, který nevysvětluje nepřítomnost, zastaví
     * běh jako zapomenutou mzdu a návod říká, jak z něj udělat nulové hlášení.
     */
    public function testAgreementWithoutAnyInputStopsTheRunWithZeroReportGuidance(): void
    {
        $person = $this->hire('Olga Bezvstupová', 'female', '1993-03-03');
        $response = $this->approveTimeMonth($person['employment_id'], self::PERIOD, [], dailyMinutes: 240);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $run = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $this->officeId, 'zero-no-input');

        self::assertNull($run['approved']);
        $messages = implode("\n", array_column($run['blockers'], 'message'));
        self::assertStringContainsString('zadejte u základní složky 0 Kč', $messages);
        self::assertStringContainsString('nulové hlášení JMHZ', $messages);
    }

    /** @return array{employee_id:int,employment_id:int,name:string,sequence:int} */
    private function hire(string $name, string $sex, string $birthDate): array
    {
        $sequence = ++$this->sequence;
        $person = $this->createEmployment(
            $this->officeId,
            $name,
            $sequence,
            'dpp',
            'dpp',
            10,
            2_500,
            false,
            self::PERIOD_START,
        );
        [$firstName, $lastName] = explode(' ', $name, 2);
        $this->completeJmhzEmployment($person, identity: [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'birth_date' => $birthDate,
            'sex' => $sex,
            'birth_number' => self::syntheticBirthNumber($birthDate, $sex, $sequence),
        ]);
        $this->assignJmhzIdentity($person, self::syntheticOic($sequence), sprintf('3%020d', $sequence));

        return $person + ['sequence' => $sequence];
    }

    private function submission(string $scenario): string
    {
        $run = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $this->officeId, "zero-{$scenario}");
        self::assertSame([], $run['blockers'], 'Zaseknutí: výpočet mzdy. ' . CanonicalJson::encode($run['blockers']));
        self::assertSame([], $run['warnings'], 'Zaseknutí: varování běhu. ' . CanonicalJson::encode($run['warnings']));
        self::assertNotNull($run['approved']);
        $revisionId = (int) $run['approved']->revision['id'];

        $preparation = $this->prepareJmhz($revisionId, "zero-{$scenario}");
        self::assertSame(201, $preparation['status'], 'Zaseknutí: příprava. ' . CanonicalJson::encode($preparation['body']));
        self::assertSame(
            'source_ready',
            $preparation['body']['readiness_status'],
            'Zaseknutí: příprava. ' . CanonicalJson::encode($preparation['body']['issues'] ?? []),
        );
        $tested = $this->dryRunJmhz((int) $preparation['body']['id'], $this->officeId);
        self::assertSame(200, $tested['status'], CanonicalJson::encode($tested['body']));
        self::assertSame(
            'dry_run_valid',
            $tested['body']['status'],
            'Zaseknutí: XSD nebo kontroly. ' . CanonicalJson::encode($tested['body']['controls'] ?? $tested['body']),
        );

        return (string) preg_replace('/>\s+</', '><', (string) $tested['body']['xml']);
    }
}
