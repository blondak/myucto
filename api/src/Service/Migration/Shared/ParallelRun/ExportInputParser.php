<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared\ParallelRun;

use MyInvoice\Service\Migration\MoneyS3\MoneyReportParser;

/**
 * Výstupy starého programu jako soubory → části {@see SourceSnapshot}.
 *
 * Předvaha se čte parserem sestav Money ({@see MoneyReportParser}), který nezávisí na
 * programu: řádek s kódem účtu a třemi nebo šesti čísly. DPH a KH jsou XML z EPO. Ostatní
 * sestavy jsou tabulky s hlavičkou; jména sloupců, která parser pozná, jsou níže
 * (bez diakritiky, malými písmeny) a popisuje je manuál.
 */
final class ExportInputParser
{
    private const SALDO = [
        'account' => ['ucet', 'uc', 'account', 'saldokontni ucet'],
        'document' => ['doklad', 'cislo dokladu', 'c dokladu', 'cislo', 'variabilni symbol', 'vs', 'document'],
        'partner' => ['firma', 'partner', 'nazev firmy', 'odberatel', 'dodavatel', 'nazev', 'name'],
        'ico' => ['ico', 'ic', 'ic firmy'],
        'amount' => ['zbyva', 'zbyva uhradit', 'k uhrade', 'neuhrazeno', 'zustatek', 'saldo', 'castka', 'amount'],
    ];

    private const COUNTS = [
        'book' => ['kniha', 'agenda', 'druh dokladu', 'druh', 'rada', 'book'],
        'count' => ['pocet', 'pocet dokladu', 'ks', 'count'],
    ];

    private const BANK = [
        'account' => ['ucet', 'cislo uctu', 'bankovni ucet', 'iban', 'account', 'ucet v uctovani'],
        'currency' => ['mena', 'currency'],
        'balance' => ['zustatek', 'konecny stav', 'konecny zustatek', 'stav', 'balance'],
        'balance_czk' => ['zustatek kc', 'zustatek v kc', 'v kc', 'balance czk'],
    ];

    private const ASSETS = [
        'inventory_number' => ['inventarni cislo', 'inv cislo', 'cislo majetku', 'cislo karty', 'cislo', 'inventory number'],
        'name' => ['nazev', 'nazev majetku', 'name'],
        'input_price' => ['vstupni cena', 'porizovaci cena', 'pc', 'input price'],
        'acc_amount' => ['opravky', 'ucetni opravky', 'accumulated depreciation'],
        'net_book_value' => ['zustatkova cena', 'ucetni zustatkova cena', 'zc', 'net book value'],
    ];

    private const COST_CENTERS = [
        'center' => ['stredisko', 'kod strediska', 'cost center', 'center'],
        'revenue' => ['vynosy', 'revenue'],
        'cost' => ['naklady', 'cost'],
        'account' => ['ucet', 'account'],
        'amount' => ['obrat', 'castka', 'amount'],
    ];

    private const STATEMENT = [
        'code' => ['radek', 'oznaceni', 'kod radku', 'kod', 'row', 'code'],
        'side' => ['strana', 'aktiva pasiva', 'side'],
        'amount' => ['bezne obdobi', 'netto', 'castka', 'hodnota', 'amount', 'current'],
    ];

    public function __construct(
        private readonly MoneyReportParser $trialBalances = new MoneyReportParser(),
        private readonly EpoVatFilingReader $epo = new EpoVatFilingReader(),
    ) {}

    /**
     * @param array<string,string> $files druh ({@see ParallelRunInput}) => obsah souboru
     * @param array<string,string> $names druh => jméno souboru (do protokolu)
     * @param array{statement_unit?:float} $options
     */
    public function snapshot(string $source, array $files, array $names = [], array $options = []): SourceSnapshot
    {
        $warnings = [];
        $inputs = [];
        foreach ($files as $kind => $content) {
            if (!in_array($kind, ParallelRunInput::fileKinds(), true)) {
                throw new ParallelRunException('input_unknown', "Neznámý druh výstupu: {$kind}.", ['input' => $kind]);
            }
            $inputs[] = ['kind' => $kind, 'name' => (string) ($names[$kind] ?? $kind), 'sha256' => hash('sha256', $content), 'size' => strlen($content)];
        }
        $unit = (float) ($options['statement_unit'] ?? 1.0);
        $unit = $unit > 0 ? $unit : 1.0;

        $trialBalance = null;
        if (isset($files[ParallelRunInput::TRIAL_BALANCE])) {
            $parsed = $this->trialBalances->parse($files[ParallelRunInput::TRIAL_BALANCE]);
            if ($parsed['accounts'] === []) {
                throw new ParallelRunException('input_empty', 'V obratové předvaze není žádný řádek s účtem.', ['input' => ParallelRunInput::TRIAL_BALANCE]);
            }
            if ($parsed['skipped'] > 0) {
                $warnings[] = sprintf('Obratová předvaha: %d řádků s účtem nešlo přečíst (čekají se 3 nebo 6 čísel).', $parsed['skipped']);
            }
            $trialBalance = $parsed['accounts'];
        }

        return new SourceSnapshot(
            source: $source,
            trialBalance: $trialBalance,
            documentCounts: isset($files[ParallelRunInput::DOCUMENT_COUNTS]) ? $this->documentCounts($files[ParallelRunInput::DOCUMENT_COUNTS], $warnings) : null,
            saldo: isset($files[ParallelRunInput::SALDO]) ? $this->saldo($files[ParallelRunInput::SALDO]) : null,
            bankBalances: isset($files[ParallelRunInput::BANK_BALANCES]) ? $this->bankBalances($files[ParallelRunInput::BANK_BALANCES]) : null,
            vatReturn: isset($files[ParallelRunInput::VAT_RETURN]) ? $this->epo->read($files[ParallelRunInput::VAT_RETURN], 'dphdp3')['values'] : null,
            controlStatement: isset($files[ParallelRunInput::CONTROL_STATEMENT]) ? self::controlStatement($this->epo->read($files[ParallelRunInput::CONTROL_STATEMENT], 'dphkh1')) : null,
            assets: isset($files[ParallelRunInput::ASSETS]) ? $this->assets($files[ParallelRunInput::ASSETS]) : null,
            costCenters: isset($files[ParallelRunInput::COST_CENTERS]) ? $this->costCenters($files[ParallelRunInput::COST_CENTERS]) : null,
            balanceSheet: isset($files[ParallelRunInput::BALANCE_SHEET]) ? $this->statement($files[ParallelRunInput::BALANCE_SHEET], true, $unit) : null,
            incomeStatement: isset($files[ParallelRunInput::INCOME_STATEMENT]) ? $this->statement($files[ParallelRunInput::INCOME_STATEMENT], false, $unit) : null,
            inputs: $inputs,
            warnings: $warnings,
        );
    }

    /**
     * @param array{values:array<string,float>,rows:array<string,array{section:string,partner:string,document:string,amount:float}>} $read
     * @return array{values:array<string,float>,rows:array<string,array{section:string,partner:string,document:string,amount:float}>}
     */
    public static function controlStatement(array $read): array
    {
        return ['values' => $read['values'], 'rows' => $read['rows']];
    }

    /**
     * @param list<string> $warnings
     * @return array<string,int>
     */
    public function documentCounts(string $content, array &$warnings): array
    {
        $out = [];
        foreach (DelimitedExport::rows($content, self::COUNTS, ['book', 'count'], ParallelRunInput::DOCUMENT_COUNTS) as $row) {
            $book = ParallelRunInput::book($row['book']);
            $count = DelimitedExport::number($row['count']);
            if ($row['book'] === '' || $count === null || DelimitedExport::isTotalLabel($row['book'])) {
                continue;
            }
            if ($book === null) {
                $warnings[] = sprintf('Počty dokladů: knihu „%s" kontrola nezná, řádek se vynechal.', $row['book']);
                continue;
            }
            $out[$book] = ($out[$book] ?? 0) + (int) round($count);
        }
        return $out;
    }

    /** @return list<array{account:?string,document:string,partner:string,ico:string,amount:float}> */
    public function saldo(string $content): array
    {
        $out = [];
        foreach (DelimitedExport::rows($content, self::SALDO, ['document', 'amount'], ParallelRunInput::SALDO) as $row) {
            $amount = DelimitedExport::number($row['amount']);
            if ($row['document'] === '' || $amount === null || DelimitedExport::isTotalLabel($row['document'])) {
                continue;
            }
            $account = preg_match('/^(\d{3})/', (string) ($row['account'] ?? ''), $m) === 1 ? $m[1] : null;
            $out[] = [
                'account' => $account,
                'document' => $row['document'],
                'partner' => (string) ($row['partner'] ?? ''),
                'ico' => preg_replace('/\D+/', '', (string) ($row['ico'] ?? '')) ?? '',
                'amount' => round($amount, 2),
            ];
        }
        return $out;
    }

    /** @return list<array{account:string,currency:string,balance:float,balance_czk:?float}> */
    public function bankBalances(string $content): array
    {
        $out = [];
        foreach (DelimitedExport::rows($content, self::BANK, ['account', 'balance'], ParallelRunInput::BANK_BALANCES) as $row) {
            $balance = DelimitedExport::number($row['balance']);
            if ($row['account'] === '' || $balance === null || DelimitedExport::isTotalLabel($row['account'])) {
                continue;
            }
            $currency = mb_strtoupper(trim((string) ($row['currency'] ?? '')));
            $czk = isset($row['balance_czk']) ? DelimitedExport::number($row['balance_czk']) : null;
            $out[] = [
                'account' => $row['account'],
                'currency' => in_array($currency, ['', 'KC', 'KČ'], true) ? 'CZK' : $currency,
                'balance' => round($balance, 2),
                'balance_czk' => $czk === null ? null : round($czk, 2),
            ];
        }
        return $out;
    }

    /** @return list<array{inventory_number:string,name:string,input_price:?float,acc_amount:?float,net_book_value:?float}> */
    public function assets(string $content): array
    {
        $out = [];
        foreach (DelimitedExport::rows($content, self::ASSETS, ['inventory_number'], ParallelRunInput::ASSETS) as $row) {
            if ($row['inventory_number'] === '' || DelimitedExport::isTotalLabel($row['inventory_number'])) {
                continue;
            }
            $values = [];
            foreach (['input_price', 'acc_amount', 'net_book_value'] as $field) {
                $v = isset($row[$field]) ? DelimitedExport::number($row[$field]) : null;
                $values[$field] = $v === null ? null : round($v, 2);
            }
            if ($values['input_price'] === null && $values['acc_amount'] === null && $values['net_book_value'] === null) {
                continue;
            }
            $out[] = ['inventory_number' => $row['inventory_number'], 'name' => (string) ($row['name'] ?? '')] + $values;
        }
        return $out;
    }

    /** @return array<string,array{revenue:float,cost:float}> */
    public function costCenters(string $content): array
    {
        $rows = DelimitedExport::rows($content, self::COST_CENTERS, ['center'], ParallelRunInput::COST_CENTERS);
        $out = [];
        foreach ($rows as $row) {
            $center = trim($row['center']);
            if ($center === '' || DelimitedExport::isTotalLabel($center)) {
                continue;
            }
            if (in_array(DelimitedExport::fold($center), ['bez strediska', 'nezarazeno', 'bez'], true)) {
                $center = '';
            }
            $out[$center] ??= ['revenue' => 0.0, 'cost' => 0.0];
            if (isset($row['revenue']) || isset($row['cost'])) {
                $out[$center]['revenue'] += DelimitedExport::number((string) ($row['revenue'] ?? '')) ?? 0.0;
                $out[$center]['cost'] += DelimitedExport::number((string) ($row['cost'] ?? '')) ?? 0.0;
                continue;
            }
            $amount = DelimitedExport::number((string) ($row['amount'] ?? ''));
            $class = substr(trim((string) ($row['account'] ?? '')), 0, 1);
            if ($amount === null || !in_array($class, ['5', '6'], true)) {
                continue;
            }
            $out[$center][$class === '6' ? 'revenue' : 'cost'] += $amount;
        }
        if ($out === []) {
            throw new ParallelRunException('input_empty', 'V sestavě středisek není žádný řádek se střediskem a obratem.', ['input' => ParallelRunInput::COST_CENTERS]);
        }
        return array_map(static fn (array $v): array => ['revenue' => round($v['revenue'], 2), 'cost' => round($v['cost'], 2)], $out);
    }

    /**
     * Řádky výkazu. U rozvahy se aktiva a pasiva rozliší sloupcem „strana" (A/P), jinak
     * prefixem kódu „P." (tak kódy pasiv vede i MyÚčto); bez obojího jde řádek do aktiv.
     *
     * @return array{rows:array<string,float>,unit:float}
     */
    public function statement(string $content, bool $balanceSheet, float $unit): array
    {
        $kind = $balanceSheet ? ParallelRunInput::BALANCE_SHEET : ParallelRunInput::INCOME_STATEMENT;
        $out = [];
        foreach (DelimitedExport::rows($content, self::STATEMENT, ['code', 'amount'], $kind) as $row) {
            $amount = DelimitedExport::number($row['amount']);
            $code = self::rowCode($row['code']);
            if ($code === '' || $amount === null) {
                continue;
            }
            if ($balanceSheet) {
                $side = strtoupper(substr(DelimitedExport::fold((string) ($row['side'] ?? '')), 0, 1));
                if (str_starts_with($code, 'P.')) {
                    $code = substr($code, 2);
                    $side = 'P';
                } elseif ($code === 'PASIVA') {
                    $side = 'P';
                }
                $code = ($side === 'P' ? 'P:' : 'A:') . $code;
            }
            $out[$code] = round(($out[$code] ?? 0.0) + $amount * $unit, 2);
        }
        if ($out === []) {
            throw new ParallelRunException('input_empty', 'Ve výkazu není žádný řádek s označením a částkou.', ['input' => $kind]);
        }
        return ['rows' => $out, 'unit' => $unit];
    }

    /** Označení řádku výkazu bez mezer, velkými písmeny a s tečkou na konci („b. ii. 1" → „B.II.1."). */
    public static function rowCode(string $code): string
    {
        $c = strtoupper(preg_replace('/\s+/', '', $code) ?? $code);
        if ($c === '') {
            return '';
        }
        if (preg_match('/^(AKTIVA|PASIVA)/', $c) === 1) {
            return rtrim($c, '.');
        }
        return str_ends_with($c, '.') ? $c : $c . '.';
    }
}
