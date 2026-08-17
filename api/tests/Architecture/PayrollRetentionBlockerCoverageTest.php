<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Repository\Payroll\PayrollEmployeeDeletionRepository;
use MyInvoice\Repository\Payroll\PayrollEmploymentDeletionRepository;
use MyInvoice\Service\Payroll\Retention\PayrollRetentionCatalog;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Každá tabulka, která BLOKUJE smazání osoby, musí mít retenční lhůtu.
 *
 * ── Proč to jde uhlídat jen testem ────────────────────────────────────────────
 * Posudek retence ({@see \MyInvoice\Service\Payroll\Retention\PayrollRetentionService})
 * se ptá výhradně na tabulky, které jmenuje
 * {@see PayrollRetentionCatalog}. Mazací rutiny mají vlastní seznamy
 * (`BLOCKERS`, `GUARD_ONLY`) a nic je s katalogem nespojuje. Tabulka, která je
 * v jednom seznamu a chybí v druhém, vyrobí DVĚ protichůdné a obě nepravdivé
 * odpovědi:
 *   • posudek řekne, že osobu nic nedrží, a navrhne ji k výmazu;
 *   • samotné smazání pak selže na stráži mazací rutiny.
 * Osoba zůstane držená napořád a žádná lhůta ji nikdy neuvolní. Přesně tak se
 * chovaly `payroll_eldp_statements` a po nich dalších šestnáct tabulek — a ani
 * jednu z nich by code review neukázalo, protože se měnila migrace, ne katalog.
 *
 * ── Výjimka musí mít napsaný důvod ────────────────────────────────────────────
 * Výjimku smí dostat jen tabulka, která vlastní lhůtu mít NEMŮŽE, protože je
 * potomkem jiné tabulky s NENULLOVATELNOU vazbou na rodiče — pak rodičovský
 * řádek pro tutéž osobu existuje vždy, když existuje potomek, a lhůta rodiče
 * potomka pokrývá. Je-li vazba nullovatelná, výjimka NEPLATÍ: osiřelý potomek
 * (typicky idempotenční zámek, jehož cíl nikdy nevznikl) by osobu držel bez
 * konce. Takové tabulky proto v katalogu jsou, i když vlastní zákonnou lhůtu
 * nemají — drží lhůtu rodiče, aby vůbec někdy skončila.
 *
 * Důvod se píše sem, do hodnoty allowlistu, ne do commit message: seznam bez
 * napsaného důvodu se za rok stane seznamem, na který se nikdo neptá.
 */
final class PayrollRetentionBlockerCoverageTest extends TestCase
{
    /**
     * Blokující tabulky bez vlastní retenční kategorie a důvod, proč ji nemají.
     *
     * @var array<string,string> tabulka => důvod (rodičovská tabulka + kategorie)
     */
    private const ALLOWED_WITHOUT_CATEGORY = [
        'payroll_annual_document_sources' =>
            'Manifest provenience ročního dokladu: jen vazby a hash, žádná vlastní data. '
            . 'Rodič je payroll_annual_document_revisions (kategorie payroll_sheet) přes '
            . 'NOT NULL annual_revision_id, takže rodičovský řádek pro tutéž osobu existuje '
            . 'vždy, když existuje tenhle. Vlastní lhůta by byla druhé číslo pro tutéž věc.',
    ];

    public function testEveryBlockingTableHasARetentionCategoryOrAWrittenReason(): void
    {
        $tracked = PayrollRetentionCatalog::trackedTables();

        $uncovered = [];
        foreach ($this->blockingTables() as $table) {
            if (in_array($table, $tracked, true)) {
                continue;
            }
            if (array_key_exists($table, self::ALLOWED_WITHOUT_CATEGORY)) {
                continue;
            }
            $uncovered[] = $table;
        }

        self::assertSame(
            [],
            $uncovered,
            "Tyhle tabulky blokují smazání osoby, ale retenční katalog o nich neví.\n"
            . "Posudek proto řekne, že osobu nic nedrží, a smazání přesto selže na stráži —\n"
            . "osoba je držená napořád a žádná lhůta ji neuvolní. Doplňte je do kategorie\n"
            . "v PayrollRetentionCatalog, nebo do ALLOWED_WITHOUT_CATEGORY s důvodem:\n"
            . implode("\n", $uncovered),
        );
    }

    public function testEveryWrittenReasonCarriesAnActualExplanation(): void
    {
        foreach (self::ALLOWED_WITHOUT_CATEGORY as $table => $reason) {
            self::assertGreaterThan(
                80,
                mb_strlen(trim($reason)),
                "Výjimka pro {$table} nemá napsaný důvod. Výjimka bez důvodu je jen "
                . 'ticho na místě, kde má být rozhodnutí.',
            );
        }
    }

    /**
     * Výjimka smí existovat jen pro tabulku, která opravdu blokuje. Zapomenutá
     * výjimka nad zrušeným blokátorem by tiše kryla i tabulku, která se pod
     * stejným jménem objeví znovu s jiným významem.
     */
    public function testNoStaleExceptionSurvivesTheBlockerItExplains(): void
    {
        $blocking = $this->blockingTables();
        foreach (array_keys(self::ALLOWED_WITHOUT_CATEGORY) as $table) {
            self::assertContains(
                $table,
                $blocking,
                "Výjimka pro {$table} přežila blokátor, který vysvětlovala. Smažte ji, "
                . 'ať nekryje tabulku, která se pod tím jménem objeví znovu.',
            );
        }
    }

    /**
     * Výjimka nesmí zároveň být v katalogu — to by znamenalo, že jedno z obou
     * tvrzení je mrtvé a nikdo neví které.
     */
    public function testNoExceptionDuplicatesACatalogEntry(): void
    {
        $tracked = PayrollRetentionCatalog::trackedTables();
        foreach (array_keys(self::ALLOWED_WITHOUT_CATEGORY) as $table) {
            self::assertNotContains(
                $table,
                $tracked,
                "Tabulka {$table} je v katalogu i mezi výjimkami. Jedno z těch dvou "
                . 'tvrzení je mrtvé a nejde poznat které.',
            );
        }
    }

    /**
     * Sjednocení `BLOCKERS['tables']` a `GUARD_ONLY` obou mazacích repozitářů.
     * Konstanty jsou private záměrně — jsou to interní seznamy mazací rutiny,
     * ne veřejné API. Guard je proto čte reflexí a nepožaduje, aby se kvůli
     * testu otevřely.
     *
     * @return list<string>
     */
    private function blockingTables(): array
    {
        $tables = [];
        foreach (
            [PayrollEmployeeDeletionRepository::class, PayrollEmploymentDeletionRepository::class]
            as $class
        ) {
            $reflection = new ReflectionClass($class);
            $constants = $reflection->getConstants();

            self::assertArrayHasKey(
                'BLOCKERS',
                $constants,
                "Mazací repozitář {$class} přestal mít BLOCKERS — guard se rozešel s kódem.",
            );
            /** @var array<string,array{tables:list<string>}> $blockers */
            $blockers = $constants['BLOCKERS'];
            foreach ($blockers as $blocker) {
                foreach ($blocker['tables'] as $table) {
                    $tables[] = $table;
                }
            }

            /** @var list<string> $guardOnly */
            $guardOnly = $constants['GUARD_ONLY'] ?? [];
            foreach ($guardOnly as $table) {
                $tables[] = $table;
            }
        }

        self::assertNotEmpty($tables, 'Guard nenašel ani jeden blokátor — sonda je slepá.');
        $tables = array_values(array_unique($tables));
        sort($tables);

        return $tables;
    }
}
