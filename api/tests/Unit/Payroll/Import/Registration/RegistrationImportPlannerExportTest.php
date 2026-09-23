<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Import\Registration;

use MyInvoice\Repository\Payroll\PayrollEmploymentRepository;
use MyInvoice\Repository\Payroll\PayrollRegistrationIdentityRepository;
use MyInvoice\Service\Payroll\Import\Registration\RegistrationImportLookup;
use MyInvoice\Service\Payroll\Import\Registration\RegistrationImportPlanner;
use MyInvoice\Service\Payroll\Import\Registration\RegistrationRecord;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityService;
use PHPUnit\Framework\TestCase;

/** Plánování vět exportu zaměstnanců ČSSZ nad stubovanou evidencí. */
final class RegistrationImportPlannerExportTest extends TestCase
{
    private const SHA = 'abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789';

    public function testNewPersonIsCreatedWithStartFromMonthlyReportAndCheckWarning(): void
    {
        $record = $this->record(activityCode: '2', smallScale: true)->withDerivedStart([
            'on' => '2026-01-01',
            'source' => 'insurance_from',
            'period' => '2026-01',
            'earliest_period' => '2026-01',
        ]);

        $plan = $this->planner()->plan(1, 'test', $record, 'export.xml', self::SHA);

        self::assertNull($plan['blocker']);
        self::assertSame('create_person', $plan['operation']);
        self::assertSame('Export zaměstnanců ČSSZ', $plan['action_label']);
        self::assertSame('small_scale_employment', $plan['employment']['relation_type']);
        self::assertSame('2026-01-01', $plan['employment']['start_on']);
        self::assertSame('2026-01-01', $plan['effective_on']);
        self::assertSame('2026-01-01', $plan['_steps']['create_person']['planned_start_on']);
        self::assertSame('2026-01-01', $plan['_steps']['activate_on']);
        self::assertSame(
            ['person' => $record->personIdentifier, 'employment' => $record->employmentIdentifier],
            $plan['_steps']['identifiers'],
        );
        self::assertTrue($plan['selectable']);
        self::assertTrue($this->hasWarning($plan, 'začátek pojištění 2026-01-01'));
        self::assertTrue($this->hasWarning($plan, 'první den nejstaršího hlášeného měsíce'));
    }

    public function testStartInsideMonthNeedsNoCheck(): void
    {
        $record = $this->record()->withDerivedStart([
            'on' => '2026-02-16',
            'source' => 'insurance_from',
            'period' => '2026-02',
            'earliest_period' => '2026-02',
        ]);

        $plan = $this->planner()->plan(1, 'test', $record, 'export.xml', self::SHA);

        self::assertSame('create_person', $plan['operation']);
        self::assertTrue($this->hasWarning($plan, 'začátek pojištění 2026-02-16'));
        self::assertFalse($this->hasWarning($plan, 'první den nejstaršího'));
    }

    public function testNewPersonWithoutMonthlyReportIsBlocked(): void
    {
        $plan = $this->planner()->plan(1, 'test', $this->record(), 'export.xml', self::SHA);

        self::assertSame('create_person', $plan['operation']);
        self::assertFalse($plan['selectable']);
        self::assertStringContainsString('nahrajte spolu s exportem i měsíční hlášení', mb_strtolower((string) $plan['blocker']));
    }

    public function testForeignEmployerVariableSymbolIsBlocked(): void
    {
        $record = $this->record(variableSymbol: '9876543210');

        $plan = $this->planner(variableSymbols: ['1234567890'])->plan(1, 'test', $record, 'export.xml', self::SHA);

        self::assertFalse($plan['selectable']);
        self::assertStringContainsString('jiného zaměstnavatele', (string) $plan['blocker']);
    }

    public function testMatchingVariableSymbolWithLeadingZerosPasses(): void
    {
        $record = $this->record(variableSymbol: '0012345678')->withDerivedStart([
            'on' => '2026-03-01',
            'source' => 'start_date',
            'period' => '2026-03',
            'earliest_period' => '2026-03',
        ]);

        $plan = $this->planner(variableSymbols: ['12345678'])->plan(1, 'test', $record, 'export.xml', self::SHA);

        self::assertNull($plan['blocker']);
        self::assertTrue($this->hasWarning($plan, 'převzalo se z měsíčního hlášení za 2026-03'));
    }

    public function testExistingEmploymentGetsIdentifiersAndActivityMismatchWarning(): void
    {
        $lookup = $this->createStub(RegistrationImportLookup::class);
        $lookup->method('employeesByPersonExternalIdHash')->willReturn([5]);
        $lookup->method('employeesByIdentifierHash')->willReturn([]);
        $lookup->method('variableSymbols')->willReturn([]);
        $lookup->method('employeeName')->willReturn('Syntetická Osoba');
        $lookup->method('employments')->willReturn([[
            'id' => 50,
            'employee_id' => 5,
            'code' => 'PV-1',
            'relation_type' => 'employment',
            'status' => 'active',
            'is_primary' => true,
            'start_date' => '2025-04-01',
            'actual_start_date' => '2025-04-01',
            'end_date' => null,
            'row_version' => 1,
        ]]);
        $employments = $this->createStub(PayrollEmploymentRepository::class);
        $employments->method('currentTerms')->willReturn(['activity_code' => '1']);

        $record = $this->record(activityCode: '2', smallScale: true);
        $plan = $this->planner($lookup, $employments)->plan(1, 'test', $record, 'export.xml', self::SHA);

        self::assertNull($plan['blocker'], (string) $plan['blocker']);
        self::assertSame('matched', $plan['match']['status']);
        self::assertSame(50, $plan['match']['employment_id']);
        self::assertSame('assign_identifiers', $plan['operation']);
        self::assertSame([], $plan['_steps']['terms']);
        self::assertTrue($this->hasWarning($plan, 'Druh činnosti ve vztahu PV-1 (1) se liší od exportu ČSSZ (2)'));
        self::assertTrue($this->hasWarning($plan, 'Druh vztahu PV-1'));
        self::assertFalse($this->hasWarning($plan, 'Nástup ve vztahu'));
    }

    private function record(
        string $activityCode = '1',
        bool $smallScale = false,
        string $variableSymbol = '1234567890',
    ): RegistrationRecord {
        return new RegistrationRecord(
            documentType: RegistrationRecord::CSSZ_EXPORT,
            position: 1,
            sequence: 1,
            actionCode: 0,
            preparedOn: '2026-09-20',
            birthNumber: RegistrationXmlFixtures::birthNumber('1990-01-15', 'female', 1),
            personIdentifier: RegistrationXmlFixtures::oic(7),
            firstName: 'Jana',
            lastName: 'Testovací',
            employmentIdentifier: '2000000000101',
            activityCode: $activityCode,
            smallScale: $smallScale,
            employerVariableSymbol: $variableSymbol,
        );
    }

    /** @param list<string> $variableSymbols */
    private function planner(
        ?RegistrationImportLookup $lookup = null,
        ?PayrollEmploymentRepository $employments = null,
        array $variableSymbols = [],
    ): RegistrationImportPlanner {
        if ($lookup === null) {
            $lookup = $this->createStub(RegistrationImportLookup::class);
            $lookup->method('variableSymbols')->willReturn($variableSymbols);
        }

        return new RegistrationImportPlanner(
            $lookup,
            $this->createStub(PayrollSensitiveData::class),
            $this->createStub(PayrollRegistrationIdentityService::class),
            $this->createStub(PayrollRegistrationIdentityRepository::class),
            $employments ?? $this->createStub(PayrollEmploymentRepository::class),
        );
    }

    /** @param array<string,mixed> $plan */
    private function hasWarning(array $plan, string $needle): bool
    {
        foreach ($plan['warnings'] as $warning) {
            if (str_contains($warning, $needle)) {
                return true;
            }
        }

        return false;
    }
}
