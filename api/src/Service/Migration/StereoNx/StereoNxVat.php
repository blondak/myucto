<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\StereoNx;

use MyInvoice\Service\Migration\Premier\PremierVat;

/**
 * Adaptér Lsdph na existující klasifikátor řádků přiznání.
 *
 * PremierVat je čistá veřejná služba (žádná DB ani přepnutí účetního režimu).
 * Převádíme pouze její vstupní formát; vlastní tabulku řádek → kód MyÚčta
 * nekopírujeme. Zkratky TypDPH ani názvy nejsou autoritou daňového významu.
 */
final class StereoNxVat
{
    /** @var array<string,array<string,mixed>> */
    private array $rows = [];

    /** @param iterable<array<string,mixed>> $rows */
    public function __construct(iterable $rows)
    {
        foreach ($rows as $row) {
            $code = trim((string) ($row['TypDPH'] ?? ''));
            if ($code === '' || isset($this->rows[$code])) {
                throw new StereoNxException('vat_code_identity', 'Chybějící nebo duplicitní kód členění DPH Stereo NX.');
            }
            $this->rows[$code] = $row;
        }
    }

    /**
     * @return array{in_return:bool,code:?string,reverse:bool,deduction:string,source_lines:list<int>}
     */
    public function purchase(string $code, string $slot): array
    {
        if (!in_array($slot, ['z', 's', 't', '0'], true)) {
            throw new StereoNxException('vat_slot', 'Neznámá sazební přihrádka Stereo NX.');
        }
        $row = $this->rows[$code] ?? null;
        if ($row === null || ($row['Plneni'] ?? null) !== 'P') {
            throw new StereoNxException('vat_code_unknown', 'Členění není známým přijatým plněním Stereo NX.');
        }
        if (!is_bool($row['Kraceni'] ?? null) || ($row['Moss'] ?? false) === true) {
            throw new StereoNxException('vat_code_unsupported', 'Neověřené krácení nebo režim OSS v členění Stereo NX.');
        }
        $lines = $this->lines($row, $slot);
        $input = ['KOD_DPH' => 'source', 'FA_IN' => true, 'FA_OUT' => false,
            'IS_KRACENY' => $row['Kraceni'], 'R19' => 0];
        foreach ($lines as $i => $line) {
            $input[$i === 0 ? 'R19' : 'R19' . chr(64 + $i)] = $line;
        }
        $result = PremierVat::fromRows([$input])->purchase('source');
        if ($result === null || $result['fixed_asset']) {
            throw new StereoNxException('vat_classification_unsupported', 'Členění DPH vyžaduje samostatné mapování před převodem.');
        }
        $targetCode = $result['code'];
        if ($targetCode === null && $result['in_return']) {
            $targetCode = match ($lines) {
                [40] => '40',
                [41] => '41',
                default => throw new StereoNxException('vat_classification_ambiguous', 'Nejednoznačné členění sazby DPH Stereo NX.'),
            };
        }
        return ['in_return' => $result['in_return'], 'code' => $targetCode,
            'reverse' => $result['reverse'], 'deduction' => $result['deduction'], 'source_lines' => $lines];
    }

    /** @return array{in_return:bool,code:?string,domestic:bool,source_lines:list<int>} */
    public function sale(string $code, string $slot): array
    {
        if (!in_array($slot, ['z', 's', 't', '0'], true)) {
            throw new StereoNxException('vat_slot', 'Neznámá sazební přihrádka Stereo NX.');
        }
        $row = $this->rows[$code] ?? null;
        if ($row === null || ($row['Plneni'] ?? null) !== 'U') {
            throw new StereoNxException('vat_code_unknown', 'Členění není známým uskutečněným plněním Stereo NX.');
        }
        $lines = $this->lines($row, $slot);
        $input = ['KOD_DPH' => 'source', 'FA_IN' => false, 'FA_OUT' => true, 'R19' => 0];
        foreach ($lines as $i => $line) {
            $input[$i === 0 ? 'R19' : 'R19' . chr(64 + $i)] = $line;
        }
        $result = PremierVat::fromRows([$input])->sale('source');
        if ($result === null || $result['asset_sale']) {
            throw new StereoNxException('vat_classification_unsupported', 'Členění vydaného plnění vyžaduje samostatné mapování.');
        }
        $target = $result['code'];
        if ($target === null && $result['domestic']) {
            $target = match ($lines) {
                [1] => '1', [2] => '2',
                default => throw new StereoNxException('vat_classification_ambiguous', 'Nejednoznačné členění vydaného plnění.'),
            };
        }
        return ['in_return' => $result['in_return'], 'code' => $target,
            'domestic' => $result['domestic'], 'source_lines' => $lines];
    }

    /** @param array<string,mixed> $row @return list<int> */
    private function lines(array $row, string $slot): array
    {
        $lines = [];
        foreach (['Zaklad', 'Dan'] as $kind) {
            foreach (['', 'x'] as $suffix) {
                $field = 'E19Radek' . $kind . strtoupper($slot) . $suffix;
                // Formát nemá sazební sloupec Dan0. Ostatní chybějící sloupce jsou chyba schématu.
                if ($slot === '0' && $kind === 'Dan') continue;
                if (!array_key_exists($field, $row)) {
                    throw new StereoNxException('vat_schema', 'Chybí podporované sloupce členění DPH Stereo NX.');
                }
                $value = trim((string) $row[$field]);
                if ($value === '' || $value === 'NE') continue;
                if (!preg_match('/^[0-9]{1,2}$/D', $value) || (int) $value < 1) {
                    throw new StereoNxException('vat_line_unsupported', 'Neznámé označení řádku přiznání Stereo NX.');
                }
                $lines[] = (int) $value;
            }
        }
        $lines = array_values(array_unique($lines));
        sort($lines);
        return $lines;
    }
}
