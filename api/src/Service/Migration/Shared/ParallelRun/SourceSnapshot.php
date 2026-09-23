<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared\ParallelRun;

/**
 * Co o měsíci říká starý účetní program — převedené na tvar nezávislý na programu.
 * Každá část je volitelná: null = výstup nebyl dodán a kritérium se nekontroluje.
 *
 * Znaménka: předvaha netto MD − D (jako {@see \MyInvoice\Service\Migration\Shared\TrialBalanceReconciliation}),
 * saldokonto kladně na normální straně účtu (pohledávka i závazek kladně), zůstatky
 * bank v měně účtu, obraty středisek kladně (výnos i náklad).
 */
final class SourceSnapshot
{
    /**
     * @param array<string,array{0:float,1:float,2:float}>|null $trialBalance syntetika => [PS, obrat, KS]
     * @param array<string,int>|null $documentCounts kniha ({@see ParallelRunInput::BOOKS}) => počet dokladů v měsíci
     * @param list<array{account:?string,document:string,partner:string,ico:string,amount:float}>|null $saldo otevřené položky
     * @param list<array{account:string,currency:string,balance:float,balance_czk:?float}>|null $bankBalances
     * @param array<string,float>|null $vatReturn atribut přiznání k DPH (`Veta4.odp_tuz23_nar`) => částka
     * @param array{values:array<string,float>,rows:array<string,array{section:string,partner:string,document:string,amount:float}>}|null $controlStatement
     * @param list<array{inventory_number:string,name:string,input_price:?float,acc_amount:?float,net_book_value:?float}>|null $assets
     * @param array<string,array{revenue:float,cost:float}>|null $costCenters kód střediska => obraty tříd 6 a 5
     * @param array{rows:array<string,float>,unit:float}|null $balanceSheet `A:B.II.` / `P:A.I.` => částka v Kč
     * @param array{rows:array<string,float>,unit:float}|null $incomeStatement kód řádku => částka v Kč
     * @param list<array{kind:string,name:string,sha256:string,size:int,note?:string}> $inputs
     * @param list<string> $warnings
     */
    public function __construct(
        public readonly string $source,
        public readonly ?array $trialBalance = null,
        public readonly ?array $documentCounts = null,
        public readonly ?array $saldo = null,
        public readonly ?array $bankBalances = null,
        public readonly ?array $vatReturn = null,
        public readonly ?array $controlStatement = null,
        public readonly ?array $assets = null,
        public readonly ?array $costCenters = null,
        public readonly ?array $balanceSheet = null,
        public readonly ?array $incomeStatement = null,
        public readonly array $inputs = [],
        public readonly array $warnings = [],
    ) {}
}
