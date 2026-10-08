<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Eldp\EldpAnnualStatement;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpAnnualStatementBuilder;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpValidationException;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpXmlSerializer;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpXmlValidator;
use PHPUnit\Framework\TestCase;

/**
 * Souběh a návaznost zaměstnání u téhož zaměstnavatele (Všeobecné zásady ELDP,
 * Hlavní zásady; Metodická pomůcka ČSSZ k ELDP př. 2, 12, 13 a 33).
 *
 * - souběžné vztahy: každý svůj evidenční list,
 * - nástup do tří měsíců od skončení ve stejném roce: list se neuzavírá,
 *   doba navazujícího vztahu jde do dalších řádků téhož listu,
 * - bezprostředně navazující zaměstnání stejného druhu a podmínek: jeden řádek,
 *   změna druhu nebo zaměstnání malého rozsahu: samostatné řádky.
 *
 * Syntetická data: firma 7, osoba 11, vztahy 101 až 104.
 */
final class EldpContinuedEmploymentTest extends TestCase
{
    use EldpYearFixture;

    private const SUPPLIER_ID = 7;
    private const EMPLOYEE_ID = 11;

    /**
     * Př. 33: zaměstnání malého rozsahu 4. 1. až 31. 5. s účastí jen v březnu,
     * od 1. 6. nový pracovní poměr. Oba na jednom listu, samostatné řádky; ZMR
     * až od vzniku účasti, „Výdělečná činnost od" je skutečný nástup do ZMR.
     */
    public function testSmallScaleEmploymentFollowedByEmploymentContinuesOnTheSameStatement(): void
    {
        $employments = [
            ['id' => 101, 'relation' => 'small_scale_employment', 'code' => '1', 'start' => '2025-01-04', 'end' => '2025-05-31',
                'participates' => [3], 'base' => 140_000, 'participating_base' => 450_000],
            ['id' => 103, 'relation' => 'employment', 'code' => '1', 'start' => '2025-06-01', 'end' => null],
        ];

        $statement = $this->build(101, $this->year($employments));

        $sections = $statement->sections();
        self::assertCount(2, $sections);
        self::assertSame(['1++', '1++'], array_column($sections, 'code'));
        self::assertSame(['2025-03-01', '2025-06-01'], array_column($sections, 'valid_from'));
        self::assertSame(['2025-05-31', '2025-12-31'], array_column($sections, 'valid_to'));
        self::assertSame([31, 214], array_column($sections, 'insurance_days'));
        self::assertSame([4, 5], $sections[0]['months_without_insurance']);
        self::assertSame('2025-01-04', $statement->payload['form']['employed_from']);
        self::assertSame('01', $statement->payload['form']['eldp_type']);
        self::assertSame([103], $statement->scope()['continued_employment_ids']);
        self::assertSame('2025-03-01', $statement->scope()['period_from']);
        self::assertSame('2025-12-31', $statement->scope()['period_to']);
        $xml = (new EldpXmlSerializer())->serialize($statement);
        (new EldpXmlValidator())->validate($statement, $xml);
    }

    /** Navazující vztah vlastní list nemá; odkáže na list dřívějšího vztahu. */
    public function testContinuingEmploymentRefersToTheEarlierStatement(): void
    {
        $revisions = $this->year([
            ['id' => 101, 'relation' => 'employment', 'code' => '1', 'start' => '2025-01-01', 'end' => '2025-03-31'],
            ['id' => 103, 'relation' => 'employment', 'code' => '1', 'start' => '2025-05-15', 'end' => null],
        ]);

        try {
            $this->build(103, $revisions);
            self::fail('Navazující vztah nesmí dostat samostatný list.');
        } catch (EldpValidationException $exception) {
            self::assertSame(
                ['eldp_statement_continues_previous_employment'],
                array_column($exception->blockers, 'code'),
            );
            self::assertSame(101, $exception->blockers[0]['detail']['previous_employment_id']);
        }
    }

    /**
     * Na list, který už zmrazený je, pokračovat nejde: navazující vztah dostane
     * list vlastní a dřívější vztah ho do svého listu nepřidá.
     */
    public function testContinuationOfAnAlreadyFiledStatementGetsItsOwnStatement(): void
    {
        $revisions = $this->year([
            ['id' => 101, 'relation' => 'employment', 'code' => '1', 'start' => '2025-01-01', 'end' => '2025-03-31'],
            ['id' => 103, 'relation' => 'employment', 'code' => '1', 'start' => '2025-05-15', 'end' => null],
        ]);

        $own = $this->build(103, $revisions, separatelyFiled: [101]);
        $earlier = $this->build(101, $revisions, separatelyFiled: [103]);

        self::assertSame(['2025-05-15'], array_column($own->sections(), 'valid_from'));
        self::assertArrayNotHasKey('continued_employment_ids', $own->scope());
        self::assertSame(['2025-03-31'], array_column($earlier->sections(), 'valid_to'));
        self::assertSame('02', $earlier->payload['form']['eldp_type']);
    }

    /**
     * Opětovný nástup do tří měsíců s přerušením: týž list, další řádek
     * s novým intervalem; list se neuzavírá (typ 01, činnost trvá).
     */
    public function testRehireWithinThreeMonthsContinuesOnTheNextRow(): void
    {
        $statement = $this->build(101, $this->year([
            ['id' => 101, 'relation' => 'employment', 'code' => '1', 'start' => '2025-01-01', 'end' => '2025-03-31'],
            ['id' => 103, 'relation' => 'employment', 'code' => '1', 'start' => '2025-05-15', 'end' => null],
        ]));

        $sections = $statement->sections();
        self::assertSame(['2025-01-01', '2025-05-15'], array_column($sections, 'valid_from'));
        self::assertSame(['2025-03-31', '2025-12-31'], array_column($sections, 'valid_to'));
        self::assertSame([90, 231], array_column($sections, 'insurance_days'));
        self::assertSame('01', $statement->payload['form']['eldp_type']);
        self::assertSame('2026-04-30', $statement->payload['deadline']['due_on']);
    }

    /**
     * Rok 2026: list za účast skončenou před 1. 4. 2026 vyhotoví zaměstnavatel
     * (čl. V bod 8 zák. č. 360/2025 Sb.); navazující vztah jde měsíčním
     * hlášením, takže se do listu nepřidává.
     */
    public function testInTwentyTwentySixTheEndedParticipationHasItsOwnStatement(): void
    {
        $revisions = $this->year([
            ['id' => 101, 'relation' => 'employment', 'code' => '1', 'start' => '2025-06-01', 'end' => '2026-02-28'],
            ['id' => 103, 'relation' => 'employment', 'code' => '1', 'start' => '2026-04-15', 'end' => null],
        ], 2026, 5);

        $statement = $this->build(101, $revisions, year: 2026);

        self::assertSame(['2026-01-01'], array_column($statement->sections(), 'valid_from'));
        self::assertSame(['2026-02-28'], array_column($statement->sections(), 'valid_to'));
        self::assertSame('02', $statement->payload['form']['eldp_type']);
        self::assertArrayNotHasKey('continued_employment_ids', $statement->scope());
    }

    /** Nástup později než tři měsíce po skončení na list nenavazuje. */
    public function testRehireAfterThreeMonthsKeepsSeparateStatements(): void
    {
        $revisions = $this->year([
            ['id' => 101, 'relation' => 'employment', 'code' => '1', 'start' => '2025-01-01', 'end' => '2025-03-31'],
            ['id' => 103, 'relation' => 'employment', 'code' => '1', 'start' => '2025-07-01', 'end' => null],
        ]);

        $first = $this->build(101, $revisions);
        $second = $this->build(103, $revisions);

        self::assertSame(['2025-03-31'], array_column($first->sections(), 'valid_to'));
        self::assertSame('02', $first->payload['form']['eldp_type']);
        self::assertSame(['2025-07-01'], array_column($second->sections(), 'valid_from'));
    }

    /**
     * Př. 12: bezprostředně navazující pracovní poměry téhož druhu a podmínek
     * jsou jedno nepřerušené pojištění a zapíší se jedním řádkem.
     */
    public function testImmediatelyFollowingEmploymentOfTheSameKindIsOneRow(): void
    {
        $statement = $this->build(101, $this->year([
            ['id' => 101, 'relation' => 'employment', 'code' => '1', 'start' => '2025-01-01', 'end' => '2025-04-30'],
            ['id' => 103, 'relation' => 'employment', 'code' => '1', 'start' => '2025-05-01', 'end' => null],
        ]));

        $sections = $statement->sections();
        self::assertCount(1, $sections);
        self::assertSame('2025-01-01', $sections[0]['valid_from']);
        self::assertSame('2025-12-31', $sections[0]['valid_to']);
        self::assertSame(365, $sections[0]['insurance_days']);
        self::assertSame(120_000, $sections[0]['assessment_base_czk']);
    }

    /**
     * Př. 13: na pracovní poměr bezprostředně navazuje dohoda o pracovní
     * činnosti — změna druhu činnosti, samostatné řádky téhož listu.
     */
    public function testImmediatelyFollowingAgreementOfAnotherKindIsASeparateRow(): void
    {
        $statement = $this->build(101, $this->year([
            ['id' => 101, 'relation' => 'employment', 'code' => '1', 'start' => '2025-01-01', 'end' => '2025-05-31'],
            ['id' => 104, 'relation' => 'dpc', 'code' => 'A', 'start' => '2025-06-01', 'end' => null],
        ]));

        $sections = $statement->sections();
        self::assertSame(['1++', 'A++'], array_column($sections, 'code'));
        self::assertSame(['2025-01-01', '2025-06-01'], array_column($sections, 'valid_from'));
        self::assertSame([104], $statement->scope()['continued_employment_ids']);
    }

    /**
     * Př. 2: souběžné pracovní poměry — každý svůj list. Souběžný vztah se do
     * listu nepřidá ani tehdy, když jeden z nich v roce skončí.
     */
    public function testConcurrentEmploymentsKeepTheirOwnStatements(): void
    {
        $revisions = $this->year([
            ['id' => 101, 'relation' => 'employment', 'code' => '1', 'start' => '2024-01-01', 'end' => '2025-09-30'],
            ['id' => 102, 'relation' => 'employment', 'code' => '2', 'start' => '2025-03-01', 'end' => null],
        ]);

        $first = $this->build(101, $revisions);
        $second = $this->build(102, $revisions);

        self::assertSame(['1++'], array_column($first->sections(), 'code'));
        self::assertSame('2025-09-30', $first->sections()[0]['valid_to']);
        self::assertArrayNotHasKey('continued_employment_ids', $first->scope());
        self::assertSame(['2++'], array_column($second->sections(), 'code'));
        self::assertSame('2025-03-01', $second->sections()[0]['valid_from']);
        self::assertSame(102, $second->scope()['employment_id']);
    }

    // --- fixtures ---------------------------------------------------------

    /**
     * @param list<array<string,mixed>> $revisions
     * @param list<int> $separatelyFiled
     */
    private function build(int $employmentId, array $revisions, array $separatelyFiled = [], int $year = 2025): EldpAnnualStatement
    {
        return (new EldpAnnualStatementBuilder())->build(
            self::SUPPLIER_ID,
            $employmentId,
            $year,
            $revisions,
            [
                'excluded_days_confirmed' => true,
                'pension_status' => [
                    'pension_age_reached_on' => null,
                    'early_pension_from' => null,
                    'full_pension_paid_from' => null,
                    'foreign_insurance' => false,
                ],
                'requested_by_authority' => false,
            ],
            null,
            $separatelyFiled,
        );
    }
}
