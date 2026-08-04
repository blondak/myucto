<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\IncomeTax;

use MyInvoice\Service\Payroll\IncomeTax\EmploymentRelationshipKind;
use MyInvoice\Service\Payroll\IncomeTax\EmploymentRelationshipKindMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class EmploymentRelationshipKindMapperTest extends TestCase
{
    /** @return iterable<string,array{string,EmploymentRelationshipKind}> */
    public static function databaseRelationTypes(): iterable
    {
        yield 'employment' => [
            'employment',
            EmploymentRelationshipKind::Employment,
        ];
        yield 'small-scale employment' => [
            'small_scale_employment',
            EmploymentRelationshipKind::SmallScaleEmployment,
        ];
        yield 'DPP' => [
            'dpp',
            EmploymentRelationshipKind::Dpp,
        ];
        yield 'DPC' => [
            'dpc',
            EmploymentRelationshipKind::Dpc,
        ];
        yield 'managing partner dependent work' => [
            'partner_dependent',
            EmploymentRelationshipKind::ManagingPartnerDependent,
        ];
        yield 'statutory body remuneration' => [
            'statutory_body',
            EmploymentRelationshipKind::StatutoryBody,
        ];
    }

    #[DataProvider('databaseRelationTypes')]
    public function testMapsDatabaseRelationType(
        string $relationType,
        EmploymentRelationshipKind $expected,
    ): void {
        self::assertSame(
            $expected,
            (new EmploymentRelationshipKindMapper())
                ->fromDatabaseRelationType($relationType),
        );
    }

    public function testUnsupportedRelationTypeFailsClosed(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Unsupported payroll relation type');

        (new EmploymentRelationshipKindMapper())
            ->fromDatabaseRelationType('managing-partner-dependent');
    }
}
