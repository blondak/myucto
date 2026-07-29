<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Codebooks;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AssetRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;

/**
 * Import karet majetku z XLSX/CSV (Epic F5 §4.4). Identita = inventory_number per
 * firma. Create vždy status='draft' (sloupec `stav` se ignoruje); opening sloupce
 * umožňují migraci částečně odepsaného majetku. Lifecycle (zařazení/vyřazení) se
 * importem NESPOUŠTÍ. Update jen pro draft; zaúčtovaná karta (in_use/disposed):
 * shodné hodnoty → skip, odlišné → error. Import NIKDY nemaže.
 */
final class AssetImportService extends AbstractCodebookImportService
{
    private const KIND_ALIASES = [
        'tangible' => 'tangible', 'hmotny' => 'tangible',
        'intangible' => 'intangible', 'nehmotny' => 'intangible',
    ];

    private const METHOD_ALIASES = [
        'straight' => 'straight', 'rovnomerny' => 'straight',
        'accelerated' => 'accelerated', 'zrychleny' => 'accelerated',
        'extraordinary' => 'extraordinary', 'mimoradny' => 'extraordinary',
        'by_accounting' => 'by_accounting', 'dle ucetnictvi' => 'by_accounting', 'ucetni' => 'by_accounting',
        'none' => 'none', 'zadny' => 'none', 'zadna' => 'none', 'neodpisovany' => 'none', 'bez' => 'none',
    ];

    /** field_key => porovnávací typ (pro diff/round-trip). */
    private const EDITABLE = [
        'name'                     => 'string',
        'description'              => 'nstring',
        'asset_account_code'       => 'string',
        'accumulated_account_code' => 'nstring',
        'acquisition_account_code' => 'string',
        'input_price'              => 'money',
        'acquisition_date'         => 'date',
        'put_into_use_date'        => 'ndate',
        'tax_method'               => 'string',
        'tax_group'                => 'nint',
        'opening_tax_years'        => 'int',
        'opening_tax_amount'       => 'money',
        'opening_acc_months'       => 'int',
        'opening_acc_amount'       => 'money',
        'acc_useful_life_months'   => 'nint',
    ];

    /** Nullable pole: mapovaná prázdná buňka = explicitní NULL. */
    private const NULLABLE = [
        'description', 'accumulated_account_code', 'put_into_use_date', 'tax_group', 'acc_useful_life_months',
    ];

    public function __construct(
        private readonly AssetRepository $assets,
        private readonly ChartOfAccountsRepository $accounts,
        private readonly Connection $db,
    ) {}

    public static function columns(): array
    {
        return [
            'inventory_number'         => ['header' => 'inventarni_cislo', 'aliases' => ['inventory_number', 'inv_cislo', 'inv'],
                                           'required' => 'ano', 'note' => 'max 30; identita řádku'],
            'name'                     => ['header' => 'nazev', 'aliases' => ['name'], 'required' => 'nový: ano', 'note' => 'max 255'],
            'description'              => ['header' => 'popis', 'aliases' => ['description'], 'required' => 'ne', 'note' => ''],
            'kind'                     => ['header' => 'druh', 'aliases' => ['kind'], 'required' => 'ne (default tangible)',
                                           'note' => 'tangible|intangible (+ hmotny/nehmotny); u existujícího jen kontrola'],
            'asset_account_code'       => ['header' => 'majetkovy_ucet', 'aliases' => ['asset_account_code'],
                                           'required' => 'ne (default 022)', 'note' => 'existence + aktivita; prefix 01x/02x/03x jinak warning'],
            'accumulated_account_code' => ['header' => 'opravkovy_ucet', 'aliases' => ['accumulated_account_code'],
                                           'required' => 'ne', 'note' => 'prázdné = neodpisovaný'],
            'acquisition_account_code' => ['header' => 'porizovaci_ucet', 'aliases' => ['acquisition_account_code'],
                                           'required' => 'ne (default 042)', 'note' => 'existence + aktivita'],
            'input_price'              => ['header' => 'vstupni_cena', 'aliases' => ['input_price'],
                                           'required' => 'nový: ano', 'note' => '> 0; CZ i EN formát (12 345,67 i 12345.67)'],
            'acquisition_date'         => ['header' => 'datum_porizeni', 'aliases' => ['acquisition_date'],
                                           'required' => 'nový: ano', 'note' => 'd.m.Y, Y-m-d, Excel serial'],
            'put_into_use_date'        => ['header' => 'datum_zarazeni', 'aliases' => ['put_into_use_date'],
                                           'required' => 'ne', 'note' => '>= datum_porizeni'],
            'tax_method'               => ['header' => 'danova_metoda', 'aliases' => ['tax_method'],
                                           'required' => 'ne (default straight)', 'note' => 'straight|accelerated|extraordinary|by_accounting|none (+ CZ aliasy)'],
            'tax_group'                => ['header' => 'odpisova_skupina', 'aliases' => ['tax_group'],
                                           'required' => 'straight/accelerated: ano', 'note' => '1–6'],
            'opening_tax_years'        => ['header' => 'let_odepsano', 'aliases' => ['opening_tax_years'], 'required' => 'ne (default 0)', 'note' => 'opening pro migraci'],
            'opening_tax_amount'       => ['header' => 'danove_odepsano', 'aliases' => ['opening_tax_amount'], 'required' => 'ne', 'note' => ''],
            'opening_acc_months'       => ['header' => 'mesicu_ucetne', 'aliases' => ['opening_acc_months'], 'required' => 'ne', 'note' => ''],
            'opening_acc_amount'       => ['header' => 'ucetne_odepsano', 'aliases' => ['opening_acc_amount'], 'required' => 'ne', 'note' => ''],
            'acc_useful_life_months'   => ['header' => 'ucetni_zivotnost_mesicu', 'aliases' => ['acc_useful_life_months'], 'required' => 'ne', 'note' => ''],
            'status'                   => ['header' => 'stav', 'aliases' => ['status'], 'required' => 'export-only', 'note' => 'draft|in_use|disposed; na importu ignorován, lifecycle jen přes API'],
        ];
    }

    protected function requiredHeaderKeys(): array
    {
        return ['inventory_number'];
    }

    protected function process(int $supplierId, array $map, array $rows, bool $dryRun): array
    {
        $pdo = $this->db->pdo();

        $byInv = $this->allAssets($supplierId);
        $activeCodes = [];
        foreach ($this->accounts->listForTenant($supplierId, false) as $acc) {
            $activeCodes[(string) $acc['account_code']] = true;
        }

        $reportRows = [];
        $writers = [];
        $seen = [];

        ksort($rows);
        foreach ($rows as $line => $cols) {
            $inv = $this->col($cols, $map, 'inventory_number');
            $row = ['line' => $line, 'key' => $inv, 'status' => 'skip'];

            if ($inv === '') {
                $reportRows[$line] = $this->err($row, 'Chybí inventární číslo.');
                continue;
            }
            if (mb_strlen($inv) > 30) {
                $reportRows[$line] = $this->err($row, 'Inventární číslo „' . $inv . '" je delší než 30 znaků.');
                continue;
            }
            if (isset($seen[$inv])) {
                $reportRows[$line] = $this->err($row, 'Inventární číslo „' . $inv . '" je v souboru vícekrát.');
                continue;
            }
            $seen[$inv] = true;

            [$spec, $val, $parseError, $kindProvided, $kind] = $this->parseRow($cols, $map);
            if ($parseError !== null) {
                $reportRows[$line] = $this->err($row, $parseError);
                continue;
            }

            $existing = $byInv[$inv] ?? null;
            $reportRows[$line] = $existing !== null
                ? $this->handleExisting($supplierId, $row, $existing, $spec, $val, $kindProvided, $kind, $activeCodes, $writers)
                : $this->handleCreate($supplierId, $row, $spec, $val, $kindProvided, $kind, $activeCodes, $writers);
        }

        return $this->summarize($dryRun, $reportRows, $writers, $pdo);
    }

    /**
     * @return array{0:array<string,bool>, 1:array<string,mixed>, 2:?string, 3:bool, 4:?string}
     */
    private function parseRow(array $cols, array $map): array
    {
        $spec = [];
        $val = [];
        $error = null;

        foreach (self::EDITABLE as $field => $type) {
            if (!isset($map[$field])) {
                $spec[$field] = false;
                $val[$field] = null;
                continue;
            }
            $raw = $this->col($cols, $map, $field);
            if ($raw === '') {
                if (in_array($field, self::NULLABLE, true)) {
                    $spec[$field] = true;
                    $val[$field] = null;
                } else {
                    $spec[$field] = false;
                    $val[$field] = null;
                }
                continue;
            }
            $parsed = $this->parseTyped($type, $raw);
            if ($parsed === self::INVALID) {
                $error = $this->parseErrorFor($field, $raw);
                break;
            }
            $spec[$field] = true;
            $val[$field] = $parsed;
        }

        // kind (validate-only, non-✎)
        $kindProvided = false;
        $kind = null;
        if ($error === null && isset($map['kind'])) {
            $raw = $this->col($cols, $map, 'kind');
            if ($raw !== '') {
                $kind = self::parseEnum($raw, self::KIND_ALIASES);
                if ($kind === null) {
                    $error = 'Neznámý druh majetku „' . $raw . '" (tangible|intangible|hmotny|nehmotny).';
                } else {
                    $kindProvided = true;
                }
            }
        }

        return [$spec, $val, $error, $kindProvided, $kind];
    }

    private const INVALID = "\0invalid\0";

    private function parseTyped(string $type, string $raw): mixed
    {
        return match ($type) {
            'string', 'nstring' => $raw,
            'money'  => self::parseDecimal($raw) ?? self::INVALID,
            'int', 'nint' => (($d = self::parseDecimal($raw)) === null ? self::INVALID : (int) round($d)),
            'date', 'ndate' => self::parseDate($raw) ?? self::INVALID,
            default => self::INVALID,
        };
    }

    private function parseErrorFor(string $field, string $raw): string
    {
        return match (self::EDITABLE[$field]) {
            'money' => 'Neplatné číslo v „' . self::columns()[$field]['header'] . '": „' . $raw . '".',
            'int', 'nint' => 'Neplatné celé číslo v „' . self::columns()[$field]['header'] . '": „' . $raw . '".',
            'date', 'ndate' => 'Neplatné datum v „' . self::columns()[$field]['header'] . '": „' . $raw . '".',
            default => 'Neplatná hodnota v „' . self::columns()[$field]['header'] . '".',
        };
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $existing
     * @param array<string,bool> $spec
     * @param array<string,mixed> $val
     * @param array<string,bool> $activeCodes
     * @param list<callable():void> $writers
     */
    private function handleExisting(int $supplierId, array $row, array $existing, array $spec, array $val, bool $kindProvided, ?string $kind, array $activeCodes, array &$writers): array
    {
        $inv = (string) $existing['inventory_number'];
        $status = (string) $existing['status'];

        if ($kindProvided && $kind !== (string) $existing['kind']) {
            return $this->err($row, 'Druh majetku ' . $inv . ' nelze měnit importem.');
        }

        $desired = $this->buildDesired($existing, $spec, $val, false);
        $changes = $this->diff($existing, $desired);

        if ($status !== 'draft') {
            if ($changes === []) {
                $row['status'] = 'skip';
                return $row;
            }
            return $this->err($row, 'Majetek ' . $inv . ' je zařazen/vyřazen — zaúčtovaný majetek nelze měnit importem.');
        }

        if ($changes === []) {
            $row['status'] = 'skip';
            return $row;
        }

        $err = $this->validateCard($desired, $activeCodes, $warnings);
        if ($err !== null) {
            return $this->err($row, $err);
        }

        $data = [];
        foreach (array_keys($changes) as $field) {
            $data[$field] = $desired[$field];
        }
        $id = (int) $existing['id'];
        $writers[] = function () use ($supplierId, $id, $data): void {
            $this->assets->update($supplierId, $id, $data);
        };
        $row['status'] = 'update';
        $row['changes'] = $changes;
        if ($warnings !== '') {
            $row['message'] = $warnings;
        }
        return $row;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,bool> $spec
     * @param array<string,mixed> $val
     * @param array<string,bool> $activeCodes
     * @param list<callable():void> $writers
     */
    private function handleCreate(int $supplierId, array $row, array $spec, array $val, bool $kindProvided, ?string $kind, array $activeCodes, array &$writers): array
    {
        $inv = (string) $row['key'];
        $desired = $this->buildDesired($this->createDefaults($inv), $spec, $val, true);
        $desired['kind'] = $kindProvided ? $kind : 'tangible';

        $err = $this->validateCard($desired, $activeCodes, $warnings, true);
        if ($err !== null) {
            return $this->err($row, $err);
        }

        $card = [
            'inventory_number'         => $inv,
            'name'                     => $desired['name'],
            'description'              => $desired['description'],
            'kind'                     => $desired['kind'],
            'asset_account_code'       => $desired['asset_account_code'],
            'accumulated_account_code' => $desired['accumulated_account_code'],
            'acquisition_account_code' => $desired['acquisition_account_code'],
            'input_price'              => $desired['input_price'],
            'acquisition_date'         => $desired['acquisition_date'],
            'put_into_use_date'        => $desired['put_into_use_date'],
            'status'                   => 'draft',
            'tax_method'               => $desired['tax_method'],
            'tax_group'                => $desired['tax_group'],
            'opening_tax_years'        => $desired['opening_tax_years'],
            'opening_tax_amount'       => $desired['opening_tax_amount'],
            'opening_acc_months'       => $desired['opening_acc_months'],
            'opening_acc_amount'       => $desired['opening_acc_amount'],
            'acc_useful_life_months'   => $desired['acc_useful_life_months'],
        ];
        $writers[] = function () use ($supplierId, $card): void {
            $this->assets->insert($supplierId, $card);
        };
        $row['status'] = 'create';
        if ($warnings !== '') {
            $row['message'] = $warnings;
        }
        return $row;
    }

    /** @return array<string,mixed> */
    private function createDefaults(string $inv): array
    {
        return [
            'inventory_number'         => $inv,
            'name'                     => '',
            'description'              => null,
            'asset_account_code'       => '022',
            'accumulated_account_code' => null,
            'acquisition_account_code' => '042',
            'input_price'              => 0.0,
            'acquisition_date'         => null,
            'put_into_use_date'        => null,
            'tax_method'               => 'straight',
            'tax_group'                => null,
            'opening_tax_years'        => 0,
            'opening_tax_amount'       => 0.0,
            'opening_acc_months'       => 0,
            'opening_acc_amount'       => 0.0,
            'acc_useful_life_months'   => null,
        ];
    }

    /**
     * @param array<string,mixed> $base existing card / defaults
     * @param array<string,bool> $spec
     * @param array<string,mixed> $val
     * @return array<string,mixed>
     */
    private function buildDesired(array $base, array $spec, array $val, bool $isCreate): array
    {
        $out = [];
        foreach (self::EDITABLE as $field => $type) {
            if (!empty($spec[$field])) {
                $out[$field] = $val[$field];
            } else {
                $out[$field] = $base[$field] ?? null;
            }
        }
        $out['acc_residual_value'] = (float) ($base['acc_residual_value'] ?? 0.0);
        return $out;
    }

    /**
     * Diff editable polí existující karty vs. desired (typová rovnost).
     *
     * @param array<string,mixed> $existing
     * @param array<string,mixed> $desired
     * @return array<string,array{from:mixed,to:mixed}>
     */
    private function diff(array $existing, array $desired): array
    {
        $changes = [];
        foreach (self::EDITABLE as $field => $type) {
            $old = $existing[$field] ?? null;
            $new = $desired[$field];
            if (!$this->eq($type, $old, $new)) {
                $changes[$field] = ['from' => $this->norm($type, $old), 'to' => $this->norm($type, $new)];
            }
        }
        return $changes;
    }

    private function eq(string $type, mixed $a, mixed $b): bool
    {
        return match ($type) {
            'money' => $a !== null && $b !== null
                ? (int) round(((float) $a) * 100) === (int) round(((float) $b) * 100)
                : ($a === null && $b === null),
            'int' => (int) $a === (int) $b,
            'nint' => ($a === null) === ($b === null) && ($a === null || (int) $a === (int) $b),
            'date', 'ndate', 'nstring', 'string' => (string) ($a ?? '') === (string) ($b ?? ''),
            default => $a === $b,
        };
    }

    private function norm(string $type, mixed $v): mixed
    {
        if ($v === null) {
            return null;
        }
        return match ($type) {
            'money' => round((float) $v, 2),
            'int', 'nint' => (int) $v,
            default => (string) $v,
        };
    }

    /**
     * Validace desired karty (§4.4). Warningy se skládají do &$warnings (string).
     *
     * @param array<string,mixed> $card
     * @param array<string,bool> $activeCodes
     */
    private function validateCard(array $card, array $activeCodes, ?string &$warnings, bool $isCreate = false): ?string
    {
        $warnings = '';
        $msgs = [];

        if ((string) $card['name'] === '') {
            return 'Název majetku je povinný.';
        }
        if (!is_float($card['input_price']) && !is_int($card['input_price'])) {
            return 'Vstupní cena je povinná.';
        }
        if ((float) $card['input_price'] <= 0) {
            return 'Vstupní cena musí být kladná (§29).';
        }
        if ((float) $card['opening_tax_amount'] < 0
            || (float) $card['opening_tax_amount'] > (float) $card['input_price']
        ) {
            return 'Počáteční daňové oprávky musí být ≥ 0 a nesmí přesáhnout vstupní cenu.';
        }
        if ((float) $card['opening_acc_amount'] < 0
            || (float) $card['opening_acc_amount'] + (float) $card['acc_residual_value'] > (float) $card['input_price']
        ) {
            return 'Počáteční účetní oprávky musí být ≥ 0 a spolu se zbytkovou hodnotou nesmí přesáhnout vstupní cenu.';
        }
        if (!is_string($card['acquisition_date']) || $card['acquisition_date'] === '') {
            return 'Datum pořízení je povinné (d.m.Y nebo Y-m-d).';
        }
        if ($card['put_into_use_date'] !== null && (string) $card['put_into_use_date'] < (string) $card['acquisition_date']) {
            return 'Datum zařazení nesmí předcházet datu pořízení.';
        }

        $method = (string) $card['tax_method'];
        if (in_array($method, ['straight', 'accelerated'], true)) {
            $g = $card['tax_group'];
            if ($g === null || (int) $g < 1 || (int) $g > 6) {
                return 'Odpisová skupina 1–6 je povinná pro rovnoměrné a zrychlené odpisy (§30).';
            }
        }

        foreach (['asset_account_code' => false, 'acquisition_account_code' => false, 'accumulated_account_code' => true] as $field => $nullable) {
            $code = $card[$field];
            if ($code === null || $code === '') {
                if ($nullable) {
                    continue;
                }
                return 'Chybí účet (' . $field . ').';
            }
            if (!isset($activeCodes[(string) $code])) {
                return 'Účet ' . $code . ' není v aktivní osnově firmy (' . $field . ').';
            }
        }

        $assetPrefix = substr((string) $card['asset_account_code'], 0, 2);
        if (!in_array($assetPrefix, ['01', '02', '03'], true)) {
            $msgs[] = 'Majetkový účet ' . $card['asset_account_code'] . ' není z účtové třídy 01x/02x/03x.';
        }
        if (($card['accumulated_account_code'] === null || $card['accumulated_account_code'] === '') && $method !== 'none') {
            $msgs[] = 'Bez oprávkového účtu jde o neodpisovaný majetek — daňová metoda „' . $method . '" se neprojeví v odpisech.';
        }
        if (($card['accumulated_account_code'] === null || $card['accumulated_account_code'] === '')
            && ((int) $card['opening_acc_months'] !== 0 || (float) $card['opening_acc_amount'] !== 0.0)
        ) {
            return 'Neodpisovaný majetek bez oprávkového účtu nemůže mít počáteční účetní oprávky.';
        }

        $warnings = implode(' ', $msgs);
        return null;
    }

    /** @return array<string,array<string,mixed>> inventory_number => karta (cast) */
    private function allAssets(int $supplierId): array
    {
        $byInv = [];
        $page = 1;
        do {
            $res = $this->assets->list($supplierId, ['per_page' => 200, 'page' => $page]);
            foreach ($res['items'] as $item) {
                $byInv[(string) $item['inventory_number']] = $item;
            }
            $fetched = $page * 200;
            $page++;
        } while ($fetched < (int) $res['total']);
        return $byInv;
    }

    /** @param array<string,mixed> $row */
    private function err(array $row, string $message): array
    {
        $row['status'] = 'error';
        $row['message'] = $message;
        unset($row['changes']);
        return $row;
    }
}
