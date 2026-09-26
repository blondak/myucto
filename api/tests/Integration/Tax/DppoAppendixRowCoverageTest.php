<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Tax\Return\DppoXmlBuilder;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Příloha účetní závěrky k přiznání DPPO (VetaUA/VetaUB/VetaUD) musí nést KAŽDÝ řádek
 * rozvahy a výkazu zisku a ztráty, který aplikace počítá. Řádek, který ve výkazu je, ale
 * builder pro něj nemá číslo řádku formuláře, se do přiznání tiše nedostane: výkaz v
 * aplikaci a příloha podání se pak rozejdou (A.1. prodané zboží, III.1. tržby z prodeje
 * majetku, J.2. nákladové úroky a další bývaly v příloze nulové, přestože je výkaz měl).
 *
 * Čísla řádků jsou opisem číselníku MF ČR „Informace o číslech řádků pro věty účetních
 * výkazů" (daňový portál, idpr_pub/hlib/uv_info/info_detail.faces?tabulka=…&platnost=2026):
 * tabulka 23810 rozvaha-aktiva (věta UA), 24810 rozvaha-pasiva (věta UD), 25810 VZZ
 * v druhovém členění (věta UB). Kódy řádků jsou v konvenci `statement_rows` (pasiva
 * s prefixem „P.", druhé „I." VZZ jako „I.n", řádky bez označení podle calc_key).
 * Číselník uvádí i řádky, které aplikace nevede (např. vyměnitelné dluhopisy); ty se
 * kontrolují jen tehdy, když je builder mapuje.
 */
#[Group('integration')]
final class DppoAppendixRowCoverageTest extends TestCase
{
    private const OFFICIAL_ASSETS = [
        'AKTIVA' => 1, 'A.' => 2, 'B.' => 3,
        'B.I.' => 4, 'B.I.1.' => 5, 'B.I.2.' => 6, 'B.I.2.1.' => 7, 'B.I.2.2.' => 8, 'B.I.3.' => 9,
        'B.I.4.' => 10, 'B.I.5.' => 11, 'B.I.5.1.' => 12, 'B.I.5.2.' => 13,
        'B.II.' => 14, 'B.II.1.' => 15, 'B.II.1.1.' => 16, 'B.II.1.2.' => 17, 'B.II.2.' => 18,
        'B.II.3.' => 19, 'B.II.4.' => 20, 'B.II.4.1.' => 21, 'B.II.4.2.' => 22, 'B.II.4.3.' => 23,
        'B.II.5.' => 24, 'B.II.5.1.' => 25, 'B.II.5.2.' => 26,
        'B.III.' => 27, 'B.III.1.' => 28, 'B.III.2.' => 29, 'B.III.3.' => 30, 'B.III.4.' => 31,
        'B.III.5.' => 32, 'B.III.6.' => 33, 'B.III.7.' => 34, 'B.III.7.1.' => 35, 'B.III.7.2.' => 36,
        'C.' => 37, 'C.I.' => 38, 'C.I.1.' => 39, 'C.I.2.' => 40, 'C.I.3.' => 41, 'C.I.3.1.' => 42,
        'C.I.3.2.' => 43, 'C.I.4.' => 44, 'C.I.5.' => 45,
        'C.II.' => 46, 'C.II.1.' => 47, 'C.II.1.1.' => 48, 'C.II.1.2.' => 49, 'C.II.1.3.' => 50,
        'C.II.1.4.' => 51, 'C.II.1.5.' => 52, 'C.II.1.5.1.' => 53, 'C.II.1.5.2.' => 54,
        'C.II.1.5.3.' => 55, 'C.II.1.5.4.' => 56,
        'C.II.2.' => 57, 'C.II.2.1.' => 58, 'C.II.2.2.' => 59, 'C.II.2.3.' => 60, 'C.II.2.4.' => 61,
        'C.II.2.4.1.' => 62, 'C.II.2.4.2.' => 63, 'C.II.2.4.3.' => 64, 'C.II.2.4.4.' => 65,
        'C.II.2.4.5.' => 66, 'C.II.2.4.6.' => 67,
        'C.III.' => 68, 'C.III.1.' => 69, 'C.III.2.' => 70,
        'C.IV.' => 71, 'C.IV.1.' => 72, 'C.IV.2.' => 73,
        'D.' => 74, 'D.1.' => 75, 'D.2.' => 76, 'D.3.' => 77,
        'C.II.3.' => 78, 'C.II.3.1.' => 79, 'C.II.3.2.' => 80, 'C.II.3.3.' => 81,
    ];

    private const OFFICIAL_LIABILITIES = [
        'PASIVA' => 1, 'P.A.' => 2,
        'P.A.I.' => 3, 'P.A.I.1.' => 4, 'P.A.I.2.' => 5, 'P.A.I.3.' => 6,
        'P.A.II.' => 7, 'P.A.II.1.' => 8, 'P.A.II.2.' => 9, 'P.A.II.2.1.' => 10, 'P.A.II.2.2.' => 11,
        'P.A.II.2.3.' => 12, 'P.A.II.2.4.' => 13, 'P.A.II.2.5.' => 14,
        'P.A.III.' => 15, 'P.A.III.1.' => 16, 'P.A.III.2.' => 17,
        'P.A.IV.' => 18, 'P.A.IV.1.' => 19, 'P.A.IV.2.' => 21, 'P.A.V.' => 22, 'P.A.VI.' => 23,
        'P.B.+C.' => 24,
        'P.B.' => 25, 'P.B.1.' => 26, 'P.B.2.' => 27, 'P.B.3.' => 28, 'P.B.4.' => 29,
        'P.C.' => 30, 'P.C.I.' => 31, 'P.C.I.1.' => 32, 'P.C.I.1.1.' => 33, 'P.C.I.1.2.' => 34,
        'P.C.I.2.' => 35, 'P.C.I.3.' => 36, 'P.C.I.4.' => 37, 'P.C.I.5.' => 38, 'P.C.I.6.' => 39,
        'P.C.I.7.' => 40, 'P.C.I.8.' => 41, 'P.C.I.9.' => 42, 'P.C.I.9.1.' => 43, 'P.C.I.9.2.' => 44,
        'P.C.I.9.3.' => 45,
        'P.C.II.' => 46, 'P.C.II.1.' => 47, 'P.C.II.1.1.' => 48, 'P.C.II.1.2.' => 49, 'P.C.II.2.' => 50,
        'P.C.II.3.' => 51, 'P.C.II.4.' => 52, 'P.C.II.5.' => 53, 'P.C.II.6.' => 54, 'P.C.II.7.' => 55,
        'P.C.II.8.' => 56, 'P.C.II.8.1.' => 57, 'P.C.II.8.2.' => 58, 'P.C.II.8.3.' => 59,
        'P.C.II.8.4.' => 60, 'P.C.II.8.5.' => 61, 'P.C.II.8.6.' => 62, 'P.C.II.8.7.' => 63,
        'P.D.' => 64, 'P.D.1.' => 65, 'P.D.2.' => 66,
        'P.C.III.' => 67, 'P.C.III.1.' => 68, 'P.C.III.2.' => 69,
    ];

    private const OFFICIAL_INCOME_STATEMENT = [
        'I.' => 1, 'II.' => 2, 'A.' => 3, 'A.1.' => 4, 'A.2.' => 5, 'A.3.' => 6, 'B.' => 7, 'C.' => 8,
        'D.' => 9, 'D.1.' => 10, 'D.2.' => 11, 'D.2.1.' => 12, 'D.2.2.' => 13,
        'E.' => 14, 'E.1.' => 15, 'E.1.1.' => 16, 'E.1.2.' => 17, 'E.2.' => 18, 'E.3.' => 19,
        'III.' => 20, 'III.1.' => 21, 'III.2.' => 22, 'III.3.' => 23,
        'F.' => 24, 'F.1.' => 25, 'F.2.' => 26, 'F.3.' => 27, 'F.4.' => 28, 'F.5.' => 29,
        'PVH' => 30,
        'IV.' => 31, 'IV.1.' => 32, 'IV.2.' => 33, 'G.' => 34,
        'V.' => 35, 'V.1.' => 36, 'V.2.' => 37, 'H.' => 38,
        'VI.' => 39, 'VI.1.' => 40, 'VI.2.' => 41, 'I.n' => 42,
        'J.' => 43, 'J.1.' => 44, 'J.2.' => 45, 'VII.' => 46, 'K.' => 47,
        'FVH' => 48, 'VHPZ' => 49, 'L.' => 50, 'L.1.' => 51, 'L.2.' => 52, 'VHPO' => 53,
        'M.' => 54, 'VH' => 55, 'OBRAT' => 56,
    ];

    private Connection $db;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $this->db = Bootstrap::buildContainer()->get(Connection::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    /** Každý řádek výkazu má v příloze právě jedno číslo, a to to z číselníku. */
    public function testEveryStatementRowHasItsOfficialAppendixLine(): void
    {
        $numbers = DppoXmlBuilder::appendixRowNumbers();
        $problems = [];
        foreach ($this->statementRows() as $row) {
            $section = $row['section'];
            $code = $row['row_code'];
            $official = $this->official($section)[$code] ?? null;
            if ($official === null) {
                $problems[] = "{$section} {$code}: řádek není v číselníku MF ČR";
                continue;
            }
            $mapped = $numbers[$section][$code] ?? [];
            if ($mapped !== [$official]) {
                $problems[] = sprintf('%s %s: v příloze %s, v číselníku ř. %d', $section, $code, $mapped === [] ? 'chybí' : 'ř. ' . implode('+', $mapped), $official);
            }
        }

        self::assertSame([], $problems, "Řádky výkazů bez správného řádku přílohy DPPO:\n" . implode("\n", $problems));
    }

    /** I řádky, které výkaz zatím nevede, musí mít v builderu číslo z číselníku a žádné dva stejné. */
    public function testAppendixNumbersFollowOfficialCodebook(): void
    {
        $problems = [];
        foreach (DppoXmlBuilder::appendixRowNumbers() as $section => $byCode) {
            $seen = [];
            foreach ($byCode as $code => $cRadku) {
                $official = $this->official($section)[$code] ?? null;
                if ($cRadku !== [$official]) {
                    $problems[] = sprintf('%s %s: ř. %s, číselník %s', $section, $code, implode('+', $cRadku), $official ?? 'řádek nezná');
                }
                foreach ($cRadku as $n) {
                    if (isset($seen[$n])) {
                        $problems[] = sprintf('%s: ř. %d dvakrát (%s a %s)', $section, $n, $seen[$n], $code);
                    }
                    $seen[$n] = $code;
                }
            }
        }

        self::assertSame([], $problems, implode("\n", $problems));
    }

    /**
     * Zaokrouhlovací absorpce rozvahy jde po stromu rodič => podřádky. Chybí-li ve stromu
     * podřádek, který výkaz má, jeho tisíce se do součtu rodiče nezapočtou a EPO vytkne
     * „hodnota řádku se nerovná součtu". Výsledovka strom nemá: podřádky se zaokrouhlují
     * každý zvlášť a absorbuje se jen do vzorců PVH/FVH.
     */
    public function testRoundingTreeFollowsStatementHierarchy(): void
    {
        $expected = [];
        foreach ($this->statementRows() as $row) {
            if ($row['parent_row_code'] !== null && $row['section'] !== 'income_statement') {
                $expected[$row['section']][$row['parent_row_code']][] = $row['row_code'];
            }
        }
        $tree = DppoXmlBuilder::appendixRowTree();
        $problems = [];
        foreach ($expected as $section => $parents) {
            foreach ($parents as $parent => $children) {
                $actual = $tree[$section][$parent] ?? [];
                sort($children);
                sort($actual);
                if ($children !== $actual) {
                    $problems[] = sprintf('%s %s: výkaz [%s], strom přílohy [%s]', $section, $parent, implode(', ', $children), implode(', ', $actual));
                }
            }
        }

        self::assertSame([], $problems, implode("\n", $problems));
    }

    /** @return array<string,int> */
    private function official(string $section): array
    {
        return match ($section) {
            'assets' => self::OFFICIAL_ASSETS,
            'liabilities' => self::OFFICIAL_LIABILITIES,
            default => self::OFFICIAL_INCOME_STATEMENT,
        };
    }

    /** @return list<array{section:string,row_code:string,parent_row_code:?string}> */
    private function statementRows(): array
    {
        $stmt = $this->db->pdo()->query(
            "SELECT sv.statement_type, sr.section, sr.row_code, sr.parent_row_code
               FROM statement_rows sr
               JOIN statement_versions sv ON sv.id = sr.version_id
              WHERE sv.version_code = 'vyhl500-2002/2024'
                AND sv.statement_type IN ('balance_sheet', 'income_statement')
              ORDER BY sv.statement_type, sr.position"
        );
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = [
                'section' => $r['statement_type'] === 'income_statement' ? 'income_statement' : (string) $r['section'],
                'row_code' => (string) $r['row_code'],
                'parent_row_code' => $r['parent_row_code'] === null ? null : (string) $r['parent_row_code'],
            ];
        }
        self::assertNotSame([], $rows, 'Seed výkazů (migrace 1012) v DB chybí.');
        return $rows;
    }
}
