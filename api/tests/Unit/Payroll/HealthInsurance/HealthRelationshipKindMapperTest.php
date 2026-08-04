<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\HealthInsurance;

use MyInvoice\Service\Payroll\HealthInsurance\HealthEmploymentKind;
use MyInvoice\Service\Payroll\HealthInsurance\HealthRelationshipKindMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class HealthRelationshipKindMapperTest extends TestCase
{
    /** @return iterable<string,array{string,HealthEmploymentKind}> */
    public static function databaseRelationTypes(): iterable
    {
        yield 'employment' => ['employment', HealthEmploymentKind::Employment];
        yield 'small-scale employment' => [
            'small_scale_employment',
            HealthEmploymentKind::Employment,
        ];
        yield 'DPP' => ['dpp', HealthEmploymentKind::Dpp];
        yield 'DPC' => ['dpc', HealthEmploymentKind::Dpc];
        yield 'partner dependent activity' => [
            'partner_dependent',
            HealthEmploymentKind::CorporateBody,
        ];
        yield 'statutory body' => [
            'statutory_body',
            HealthEmploymentKind::CorporateBody,
        ];
    }

    #[DataProvider('databaseRelationTypes')]
    public function testMapsDatabaseRelationType(
        string $relationType,
        HealthEmploymentKind $expected,
    ): void {
        self::assertSame(
            $expected,
            (new HealthRelationshipKindMapper())
                ->fromDatabaseRelationType($relationType),
        );
    }

    public function testUnsupportedRelationTypeFailsClosed(): void
    {
        $this->expectException(UnexpectedValueException::class);

        (new HealthRelationshipKindMapper())
            ->fromDatabaseRelationType('statutory-body');
    }
}
