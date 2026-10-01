<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Dimension;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DimensionAccountMapRepository;
use MyInvoice\Repository\DimensionRepository;

/**
 * Účtotvorná dimenze — nastavení (Firma → Dimenze): přepínač u typu a mapa hodnota ×
 * syntetika → analytika. Za zaúčtování odpovídá {@see DimensionAccountRouter}.
 *
 * Mapa se ukládá per firma i u skupinové hodnoty, protože účtový rozvrh je per firma.
 */
final class DimensionAccountMapService
{
    public const DEFAULT_MASK = '5, 6';

    public function __construct(private readonly Connection $db) {}

    /**
     * Zapne / vypne účtotvornost typu a nastaví masku výsledkových účtů.
     * Nejvýš jeden účtotvorný typ na firmu: i firemní typ vedle skupinového.
     *
     * @param array<string,mixed> $body `drives_accounts`, `drives_accounts_mask`
     * @return array<string,mixed> změny pro DimensionRepository::updateType
     */
    public function typeChanges(int $supplierId, array $type, array $body): array
    {
        $changes = [];
        if (array_key_exists('drives_accounts_mask', $body)) {
            $raw = trim((string) ($body['drives_accounts_mask'] ?? ''));
            $mask = DimensionAccountMask::parse($raw === '' ? self::DEFAULT_MASK : $raw);
            foreach ($mask->include as $prefix) {
                if (!in_array($prefix[0], ['5', '6'], true)) {
                    throw new DimensionException(
                        'invalid_account_mask',
                        'Účtotvorná dimenze se uplatní jen na výsledkové účty (třídy 5 a 6) — předpona „' . $prefix . '" mezi ně nepatří.',
                    );
                }
            }
            $changes['drives_accounts_mask'] = $mask->normalized();
        }
        if (array_key_exists('drives_accounts', $body)) {
            $drives = (bool) $body['drives_accounts'];
            if ($drives) {
                $others = array_values(array_diff($this->repo()->drivingTypeIds($supplierId), [(int) $type['id']]));
                if ($others !== []) {
                    $other = (new DimensionRepository($this->db))->findType($supplierId, $others[0]);
                    throw new DimensionException(
                        'driving_type_exists',
                        'Účtotvorná může být jen jedna dimenze — už je jí „' . ($other['name'] ?? '#' . $others[0]) . '".',
                        409,
                    );
                }
                if ($type['level'] === 'global') {
                    // Skupinový typ: žádná firma skupiny nesmí mít vlastní účtotvorný typ.
                    $stmt = $this->db->pdo()->prepare(
                        'SELECT t.name FROM dimension_types t
                           JOIN supplier s ON s.id = t.supplier_id
                          WHERE t.drives_accounts = 1 AND s.supplier_group_id = ?
                          LIMIT 1'
                    );
                    $stmt->execute([(int) $type['supplier_group_id']]);
                    $name = $stmt->fetchColumn();
                    if ($name !== false) {
                        throw new DimensionException(
                            'driving_type_exists',
                            'Firma skupiny už má účtotvornou dimenzi „' . $name . '" — skupinová může být účtotvorná, až ji vypne.',
                            409,
                        );
                    }
                }
            }
            $changes['drives_accounts'] = $drives;
        }
        return $changes;
    }

    /** @return list<array<string,mixed>> */
    public function list(int $supplierId, ?int $valueId = null): array
    {
        return $this->repo()->listForSupplier($supplierId, $valueId);
    }

    /**
     * Nahradí mapu hodnoty dimenze ve firmě.
     *
     * Každý řádek: `synthetic_account_id` (nebo `synthetic_code`), `analytic_account_id`
     * (nebo `analytic_code`), volitelně `valid_from` / `valid_to`. Cílová analytika musí
     * existovat, být aktivní, ležet přímo pod syntetikou, mít stejný druh (náklad/výnos)
     * a stejnou daňovou uznatelnost — jinak by dimenze tiše přesunula náklad mezi daňový
     * a nedaňový a změnila základ daně.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public function saveForValue(int $supplierId, int $valueId, array $rows, ?int $userId): array
    {
        $dimensions = new DimensionRepository($this->db);
        $value = $dimensions->findValue($supplierId, $valueId);
        if ($value === null) {
            throw new DimensionException('not_found', 'Hodnota dimenze nenalezena.', 404);
        }
        $type = $dimensions->findType($supplierId, (int) $value['type_id']);
        if ($type === null || !(bool) ($type['drives_accounts'] ?? false)) {
            throw new DimensionException('not_driving_type', 'Typ dimenze není účtotvorný — zapněte u něj „Účtotvorná dimenze".', 409);
        }
        $mask = DimensionAccountMask::parse((string) ($type['drives_accounts_mask'] ?? self::DEFAULT_MASK));
        $accounts = $this->repo()->accounts($supplierId);
        $byCode = [];
        foreach ($accounts as $id => $a) {
            $byCode[strtoupper($a['code'])] = $id;
        }
        $clean = [];
        foreach ($rows as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $n = $i + 1;
            $syntheticId = self::accountId($row, 'synthetic', $byCode);
            $analyticId = self::accountId($row, 'analytic', $byCode);
            if ($syntheticId === null && $analyticId === null) {
                continue;
            }
            $synthetic = $syntheticId !== null ? ($accounts[$syntheticId] ?? null) : null;
            $analytic = $analyticId !== null ? ($accounts[$analyticId] ?? null) : null;
            if ($synthetic === null) {
                throw new DimensionException('invalid_account', "Řádek {$n}: syntetický účet není v účtovém rozvrhu firmy.");
            }
            if ($analytic === null) {
                throw new DimensionException('invalid_account', "Řádek {$n}: analytický účet není v účtovém rozvrhu firmy.");
            }
            if ($synthetic['parent_id'] !== null || !in_array($synthetic['account_type'], ['expense', 'revenue'], true)) {
                throw new DimensionException('invalid_account', "Řádek {$n}: účet {$synthetic['code']} není výsledková syntetika (třída 5 nebo 6).");
            }
            if (!$mask->matches($synthetic['code'])) {
                throw new DimensionException('invalid_account', "Řádek {$n}: účet {$synthetic['code']} neodpovídá masce účtotvorné dimenze ({$mask->normalized()}).");
            }
            if (!$analytic['is_active']) {
                throw new DimensionException('invalid_account', "Řádek {$n}: analytika {$analytic['code']} je v rozvrhu neaktivní.");
            }
            if ($analytic['parent_id'] !== $syntheticId) {
                throw new DimensionException('invalid_account', "Řádek {$n}: analytika {$analytic['code']} neleží pod syntetikou {$synthetic['code']}.");
            }
            if ($analytic['account_type'] !== $synthetic['account_type']) {
                throw new DimensionException('invalid_account', "Řádek {$n}: analytika {$analytic['code']} má jiný druh účtu než syntetika {$synthetic['code']}.");
            }
            if ($analytic['tax_deductibility'] !== $synthetic['tax_deductibility']) {
                throw new DimensionException(
                    'tax_deductibility_mismatch',
                    "Řádek {$n}: analytika {$analytic['code']} je " . self::deductibility($analytic['tax_deductibility'])
                        . ", syntetika {$synthetic['code']} " . self::deductibility($synthetic['tax_deductibility'])
                        . ' — dimenze nesmí měnit daňovou uznatelnost nákladu.',
                    422,
                );
            }
            $from = self::date($row['valid_from'] ?? null, "Řádek {$n}: platnost od");
            $to = self::date($row['valid_to'] ?? null, "Řádek {$n}: platnost do");
            if ($from !== null && $to !== null && $to < $from) {
                throw new DimensionException('validation_failed', "Řádek {$n}: platnost do nesmí být před platností od.");
            }
            foreach ($clean as $other) {
                if ($other['synthetic_account_id'] === $syntheticId
                    && ($other['valid_to'] === null || $from === null || $other['valid_to'] >= $from)
                    && ($to === null || $other['valid_from'] === null || $to >= $other['valid_from'])) {
                    throw new DimensionException(
                        'validation_failed',
                        "Řádek {$n}: syntetika {$synthetic['code']} už má v překrývající se platnosti jinou analytiku.",
                    );
                }
            }
            $clean[] = [
                'synthetic_account_id' => $syntheticId,
                'analytic_account_id' => (int) $analyticId,
                'valid_from' => $from,
                'valid_to' => $to,
            ];
        }
        $this->repo()->replaceForValue($supplierId, (int) $type['id'], $valueId, $clean, $userId);
        return $this->repo()->listForSupplier($supplierId, $valueId);
    }

    /**
     * Syntetiky v masce typu a jejich aktivní analytiky stejné daňové uznatelnosti —
     * nabídka výběru v nastavení dimenze (syntetika → analytika).
     *
     * @return list<array{id:int, code:string, name:string, analytics:list<array{id:int, code:string, name:string}>}>
     */
    public function candidates(int $supplierId, int $typeId): array
    {
        $type = (new DimensionRepository($this->db))->findType($supplierId, $typeId);
        if ($type === null) {
            throw new DimensionException('not_found', 'Typ dimenze nenalezen.', 404);
        }
        $mask = DimensionAccountMask::parse((string) ($type['drives_accounts_mask'] ?? self::DEFAULT_MASK));
        $accounts = $this->repo()->accounts($supplierId);
        $out = [];
        foreach ($accounts as $id => $a) {
            if ($a['parent_id'] !== null || !$a['is_active'] || !in_array($a['account_type'], ['expense', 'revenue'], true)
                || !$mask->matches($a['code'])) {
                continue;
            }
            $out[$id] = ['id' => $id, 'code' => $a['code'], 'name' => $a['name'], 'analytics' => []];
        }
        foreach ($accounts as $id => $a) {
            $parent = $a['parent_id'];
            if ($parent === null || !isset($out[$parent]) || !$a['is_active']
                || $a['tax_deductibility'] !== $accounts[$parent]['tax_deductibility']
                || $a['account_type'] !== $accounts[$parent]['account_type']) {
                continue;
            }
            $out[$parent]['analytics'][] = ['id' => $id, 'code' => $a['code'], 'name' => $a['name']];
        }
        foreach ($out as &$s) {
            usort($s['analytics'], static fn (array $x, array $y): int => strcmp($x['code'], $y['code']));
        }
        unset($s);
        usort($out, static fn (array $x, array $y): int => strcmp($x['code'], $y['code']));
        return array_values($out);
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,int> $byCode
     */
    private static function accountId(array $row, string $kind, array $byCode): ?int
    {
        $id = (int) ($row[$kind . '_account_id'] ?? 0);
        if ($id > 0) {
            return $id;
        }
        $code = strtoupper(trim((string) ($row[$kind . '_code'] ?? '')));
        if ($code === '') {
            return null;
        }
        return $byCode[$code] ?? -1;
    }

    private static function deductibility(string $value): string
    {
        return $value === 'non_deductible' ? 'daňově neuznatelná' : 'daňově uznatelná';
    }

    private static function date(mixed $raw, string $label): ?string
    {
        $raw = trim((string) ($raw ?? ''));
        if ($raw === '') {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        if ($d === false || $d->format('Y-m-d') !== $raw) {
            throw new DimensionException('validation_failed', $label . ' musí být datum (RRRR-MM-DD).');
        }
        return $raw;
    }

    private function repo(): DimensionAccountMapRepository
    {
        return new DimensionAccountMapRepository($this->db);
    }
}
