<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\SocialInsurance;

use InvalidArgumentException;
use MyInvoice\Service\Payroll\SocialInsurance\SocialEmploymentKind;
use MyInvoice\Service\Payroll\SocialInsurance\SocialParticipationAggregationGroup;
use MyInvoice\Service\Payroll\SocialInsurance\SocialRelationshipKindMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SocialRelationshipKindMapperTest extends TestCase
{
    #[DataProvider('supportedRelationTypeProvider')]
    public function testMapsDatabaseRelationTypeToSocialDomain(
        string $relationType,
        SocialEmploymentKind $expectedKind,
        SocialParticipationAggregationGroup $expectedAggregationGroup,
    ): void {
        $mapping = (new SocialRelationshipKindMapper())->fromRelationType($relationType);

        self::assertSame($expectedKind, $mapping->kind);
        self::assertSame($expectedAggregationGroup, $mapping->aggregationGroup);
    }

    /**
     * @return iterable<string, array{
     *     string,
     *     SocialEmploymentKind,
     *     SocialParticipationAggregationGroup
     * }>
     */
    public static function supportedRelationTypeProvider(): iterable
    {
        yield 'employment' => [
            'employment',
            SocialEmploymentKind::Employment,
            SocialParticipationAggregationGroup::RegularRelationship,
        ];
        yield 'small-scale employment' => [
            'small_scale_employment',
            SocialEmploymentKind::Employment,
            SocialParticipationAggregationGroup::SmallScaleCandidate,
        ];
        yield 'DPP' => [
            'dpp',
            SocialEmploymentKind::Dpp,
            SocialParticipationAggregationGroup::Dpp,
        ];
        yield 'DPC' => [
            'dpc',
            SocialEmploymentKind::Dpc,
            SocialParticipationAggregationGroup::SmallScaleCandidate,
        ];
        yield 'partner dependent activity' => [
            'partner_dependent',
            SocialEmploymentKind::CorporateBody,
            SocialParticipationAggregationGroup::SmallScaleCandidate,
        ];
        yield 'statutory body' => [
            'statutory_body',
            SocialEmploymentKind::CorporateBody,
            SocialParticipationAggregationGroup::SmallScaleCandidate,
        ];
    }

    #[DataProvider('unsupportedRelationTypeProvider')]
    public function testRejectsUnsupportedRelationType(string $relationType): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Unsupported payroll relation type for social insurance.',
        );

        (new SocialRelationshipKindMapper())->fromRelationType($relationType);
    }

    /** @return iterable<string, array{string}> */
    public static function unsupportedRelationTypeProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'unknown value' => ['agency_employment'];
        yield 'case variant' => ['DPP'];
        yield 'whitespace variant' => [' dpp'];
    }
}
