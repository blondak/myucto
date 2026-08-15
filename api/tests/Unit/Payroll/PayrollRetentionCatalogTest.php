<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use MyInvoice\Service\Accounting\RetentionPolicy;
use MyInvoice\Service\Payroll\Retention\PayrollRetentionCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Katalog zákonných retenčních lhůt — tvrzení, která se nesmí rozjet.
 *
 * Katalog řídí NEVRATNÉ mazání osobních údajů, takže tady nejde o styl, ale
 * o následek: lhůta bez citace se nedá obhájit, neurčená lhůta uvedená jako
 * číslo maže dřív, než smí, a druhé číslo pro účetní lhůtu by se při novele
 * opravilo jen na jedné straně.
 */
final class PayrollRetentionCatalogTest extends TestCase
{
    public function testEveryCategoryCitesItsLegalBasis(): void
    {
        foreach (PayrollRetentionCatalog::rules() as $rule) {
            self::assertNotSame('', $rule->act, "Kategorie {$rule->category} neuvádí zákon.");
            self::assertNotSame(
                '',
                $rule->source(),
                "Kategorie {$rule->category} nemá citaci do UI ani do auditu.",
            );
            self::assertNotSame('', $rule->note, "Kategorie {$rule->category} nevysvětluje výklad.");
            self::assertContains($rule->sourceStatus, [
                PayrollRetentionCatalog::REPO_VERIFIED,
                PayrollRetentionCatalog::EXTERNAL_UNVERIFIED,
                PayrollRetentionCatalog::UNDETERMINED,
            ], "Kategorie {$rule->category} má neznámý stav doloženosti.");
        }
    }

    /**
     * Nedoložená lhůta MUSÍ být `null`, ne odhad. Kdyby se sem propsalo číslo,
     * modul by podle něj navrhl výmaz a nikdo by nepoznal, že pro něj není opora.
     */
    public function testUndeterminedCategoriesCarryNoNumber(): void
    {
        $undetermined = 0;
        foreach (PayrollRetentionCatalog::rules() as $rule) {
            if ($rule->sourceStatus !== PayrollRetentionCatalog::UNDETERMINED) {
                continue;
            }
            $undetermined++;
            self::assertNull(
                $rule->retentionYears,
                "Kategorie {$rule->category} je vedená jako neurčená, ale nese lhůtu.",
            );
            self::assertFalse($rule->isDetermined());
            self::assertNull($rule->retainedUntil(1990), 'Neurčená lhůta nesmí nikdy expirovat.');
        }

        self::assertGreaterThan(
            0,
            $undetermined,
            'Aspoň evidence pracovní doby a exekuční spis zákonnou lhůtu nemají — '
            . 'kdyby je někdo „doplnil", je to změna výkladu, ne úklid.',
        );
    }

    public function testDeterminedCategoriesExpireOnTheLastDayOfTheYear(): void
    {
        foreach (PayrollRetentionCatalog::rules() as $rule) {
            if (!$rule->isDetermined()) {
                continue;
            }
            $until = $rule->retainedUntil(1990);
            self::assertIsString($until);
            self::assertSame(
                sprintf('%04d-12-31', 1990 + (int) $rule->retentionYears),
                $until,
                "Kategorie {$rule->category} počítá konec lhůty jinak než ke konci roku.",
            );
        }
    }

    public function testPayrollSheetHoldsTheLongestPeriod(): void
    {
        $sheet = PayrollRetentionCatalog::rule(PayrollRetentionCatalog::PAYROLL_SHEET);
        self::assertSame(30, $sheet->retentionYears);
        self::assertStringContainsString('582/1991', $sheet->source());

        foreach (PayrollRetentionCatalog::rules() as $rule) {
            if ($rule->retentionYears !== null) {
                self::assertLessThanOrEqual(
                    30,
                    $rule->retentionYears,
                    'Delší lhůtu než mzdový list katalog nezná — kdyby přibyla, musí se '
                    . 'přepsat i komentář, který mzdový list označuje za rozhodující.',
                );
            }
        }
    }

    /**
     * Účetní lhůta má v aplikaci JEDEN zdroj pravdy. Kdyby si ji katalog držel
     * vlastní, novela by opravila jen jedno číslo a druhé by mazalo dál.
     */
    public function testAccountingPeriodComesFromTheSingleSourceOfTruth(): void
    {
        self::assertSame(
            RetentionPolicy::retentionYears(RetentionPolicy::ACCOUNTING_RECORDS),
            PayrollRetentionCatalog::rule(PayrollRetentionCatalog::ACCOUNTING_RECORDS)
                ->retentionYears,
        );
    }

    public function testUnknownCategoryIsRefusedLoudly(): void
    {
        self::assertFalse(PayrollRetentionCatalog::has('vymyslena_kategorie'));
        $this->expectException(\InvalidArgumentException::class);
        PayrollRetentionCatalog::rule('vymyslena_kategorie');
    }

    public function testTrackedTablesAreUniqueAndNonEmpty(): void
    {
        $tables = PayrollRetentionCatalog::trackedTables();
        self::assertNotSame([], $tables);
        self::assertSame(
            $tables,
            array_values(array_unique($tables)),
            'Duplicitní tabulka by osobu započítala do kategorie dvakrát.',
        );
    }
}
