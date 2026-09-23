<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared\ParallelRun;

/**
 * Starý účetní program, proti kterému se měsíc porovnává. Adaptér převede jeho výstupy
 * (sestavy, podání, případně zálohu agendy) na {@see SourceSnapshot}; porovnání samo
 * ({@see ParallelRunReconciliation}) na programu nezávisí.
 *
 * Nový program = nový adaptér: stačí umět přečíst jeho výstupy. Sestavy v tabulkovém
 * tvaru a podání z EPO čte společný {@see ExportInputParser}, takže adaptér programu,
 * který nic zvláštního nemá, jen přidá svůj klíč ({@see ExportFileSource}).
 */
interface ParallelRunSource
{
    /** Klíč programu (`money_s3`, `pohoda`, `premier`, `other`). */
    public function key(): string;

    /** @return list<string> druhy výstupů ({@see ParallelRunInput}), které adaptér přečte */
    public function inputs(): array;

    /** Umí adaptér číst zálohu agendy bez zápisu do MyÚčta? */
    public function readsBackup(): bool;

    /**
     * @param array<string,string> $files druh => obsah souboru
     * @param array<string,string> $names druh => jméno souboru
     * @param array{statement_unit?:float,backup_dir?:string} $options
     */
    public function snapshot(int $year, int $month, array $files, array $names, array $options): SourceSnapshot;
}
