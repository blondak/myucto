<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Upozornění na změny dokladů po podání přiznání musí sledovat OBĚ větve evidence.
 *
 * Registr SSOT §3 vedl „varování k datům přiznání" jako 🔴 jednostranný nález se
 * značkou „agent". Ověřením se to **nepotvrdilo** — `VatPostFilingChangesService`
 * čte `invoices` i `purchase_invoices`. Nález byl falešný.
 *
 * Guard tu přesto je, a to je pointa: parita, která dnes PLATÍ, je taky něco, co se
 * dá tiše rozbít. Doklad změněný po podání je přitom důvod k dodatečnému přiznání
 * (§ 141 DŘ) — kdyby se hlídání zúžilo na jednu větev, uživatel by se o změně přijaté
 * faktury nedozvěděl a přiznání by zůstalo neopravené.
 */
final class PostFilingChangeParityTest extends TestCase
{
    private const SERVICE = 'Service/Report/VatPostFilingChangesService.php';

    /** Obě strany evidence, které musí služba sledovat. */
    private const TRACKED_TABLES = ['invoices', 'purchase_invoices'];

    public function testBothBranchesAreTrackedForPostFilingChanges(): void
    {
        $code = $this->source();
        $missing = [];

        foreach (self::TRACKED_TABLES as $table) {
            if (!preg_match('/FROM\s+' . preg_quote($table, '/') . '\b/i', $code)) {
                $missing[] = $table;
            }
        }

        self::assertSame([], $missing, sprintf(
            "Kontrola změn po podání nesleduje tabulku: %s.\n"
                . 'Doklad změněný po podání je důvod k dodatečnému přiznání (§ 141 DŘ) — '
                . 'jednostranné hlídání znamená, že se uživatel o změně na druhé větvi nedozví.',
            implode(', ', $missing),
        ));
    }

    /** Pojistka, že guard má co hlídat — přejmenovaná služba by ho umlčela. */
    public function testServiceStillExists(): void
    {
        self::assertFileExists(
            dirname(__DIR__, 2) . '/src/' . self::SERVICE,
            'VatPostFilingChangesService se přesunul — aktualizuj guard, jinak nekontroluje nic.',
        );
    }

    private function source(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/src/' . self::SERVICE);
    }
}
