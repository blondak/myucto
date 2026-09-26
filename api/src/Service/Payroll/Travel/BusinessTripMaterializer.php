<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Travel;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollBusinessTripRepository;
use MyInvoice\Repository\Payroll\PayrollComponentRepository;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use PDOException;

/**
 * Promítne schválené vyúčtování pracovní cesty do mzdových vstupů.
 *
 * Nezdaňovaná část do zákonného limitu jde na složku `CESTOVNI_NAHRADA_LIMIT`
 * (osvobozeno od daně, mimo vyměřovací základy i exekuční základ), nadlimitní
 * část na `CESTOVNI_NAHRADA_NADLIMIT` (zdanitelný příjem ve všech základech).
 * Opakované volání nevytvoří duplicitu — dedupe drží unikátní external_id
 * mzdového vstupu a unikátní zdrojová reference v payroll_travel_compensation_links.
 *
 * ── Záloha ──────────────────────────────────────────────────────────────────
 * Vyúčtování podle § 183 zákoníku práce je nárok MINUS poskytnutá záloha. Dřív
 * šel do mzdy celý nárok a záloha zůstala jen ve snímku, takže ji zaměstnanec
 * dostal podruhé. Kde se rozdíl vypořádá, říká `advance_settlement` cesty:
 *
 *  - `payroll`: nárok jde do mzdy celý (daňové zařazení obou částí se tím
 *    nemění) a vedle něj vznikne záporný vstup `CESTOVNI_NAHRADA_ZALOHA`, který
 *    zálohu z výplaty odečte. Účetně MD závazek vůči zaměstnanci / D pohledávka
 *    za zaměstnancem (335), na které záloha visí od výplaty z pokladny.
 *    Odečte se nejvýš celý nárok: přeplatek zálohy (záloha > nárok) se ze mzdy
 *    nesráží, zaměstnanec ho vrací ({@see BusinessTripSettlement}).
 *  - `cash`: nezdaněná část se vyrovná pokladnou, takže do mzdy jde jen
 *    nadlimitní část. Nezdaněnou část zaúčtuje {@see BusinessTripCashPosting}.
 */
final class BusinessTripMaterializer
{
    public const COMPONENT_EXEMPT = 'CESTOVNI_NAHRADA_LIMIT';
    public const COMPONENT_TAXABLE = 'CESTOVNI_NAHRADA_NADLIMIT';
    public const COMPONENT_ADVANCE = 'CESTOVNI_NAHRADA_ZALOHA';
    private const SOURCE_SYSTEM = 'payroll_business_trip';

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollBusinessTripRepository $trips,
        private readonly PayrollComponentRepository $components,
        private readonly BusinessTripCashPosting $cashPosting,
    ) {}

    /** @return array<string,mixed> */
    public function materialize(int $supplierId, int $tripId, ?int $userId): array
    {
        $this->components->ensureDefaults($supplierId);
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $state = $this->trips->lock($supplierId, $tripId);
            if ($state === null) {
                $this->rollbackOwned($ownsTransaction);
                return ['status' => 'not_found'];
            }
            if (!in_array($state['status'], ['approved', 'settled'], true)) {
                throw new \DomainException(
                    'Do mzdy lze promítnout jen schválené vyúčtování pracovní cesty.',
                );
            }
            // Cesta vypořádaná dřív, než se záloha odečítala, dostala do mzdy
            // celý nárok a její období je typicky uzavřené. Opakované promítnutí
            // jí proto odpočet zálohy DODATEČNĚ nepřidá — jen přehraje, co už
            // existuje. Rozdíl se u takové cesty řeší opravnou revizí.
            $firstSettlement = $state['status'] === 'approved';
            $trip = $this->trips->find($supplierId, $tripId)
                ?? throw new \RuntimeException('Pracovní cestu se nepodařilo načíst.');
            $periodStart = PayrollTimeValue::string(
                $trip['settlement_period_start'] ?? null,
                'settlement_period_start',
            );
            $settlement = BusinessTripSettlement::fromTrip($trip);

            $created = [];
            $replayed = [];
            $parts = [
                'exempt' => [self::COMPONENT_EXEMPT, $settlement->payrollExemptMinor],
                'taxable' => [self::COMPONENT_TAXABLE, $settlement->taxableMinor],
                'advance' => [self::COMPONENT_ADVANCE, -$settlement->payrollAdvanceOffsetMinor],
            ];
            foreach ($parts as $part => [$code, $amount]) {
                if ($amount === 0) {
                    continue;
                }
                $componentId = $this->componentId($supplierId, $code, $periodStart);
                $result = $part === 'advance' && !$firstSettlement
                    ? $this->existingInput($supplierId, $trip, $periodStart, $part)
                    : $this->upsertInput(
                        $supplierId,
                        $trip,
                        $periodStart,
                        $componentId,
                        $part,
                        $amount,
                        $userId,
                    );
                if ($result === null) {
                    continue;
                }
                $this->linkInput($supplierId, $tripId, $result['input_id'], $part);
                $row = [
                    'part' => $part,
                    'component_code' => $code,
                    'input_id' => $result['input_id'],
                    'amount_minor' => $amount,
                ];
                if ($result['created']) {
                    $created[] = $row;
                } else {
                    $replayed[] = $row;
                }
            }
            $posting = $settlement->mode === BusinessTripSettlement::MODE_CASH
                ? $this->cashPosting->post($supplierId, $trip, $settlement, $userId)
                : null;
            $this->trips->markSettled($supplierId, $tripId);
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            $this->rollbackOwned($ownsTransaction);
            throw $e;
        }

        return [
            'status' => 'materialized',
            'trip_id' => $tripId,
            'period' => substr($periodStart, 0, 7),
            'created_count' => count($created),
            'replayed_count' => count($replayed),
            'created' => $created,
            'replayed' => $replayed,
            'settlement' => $settlement->toArray(),
            'posting' => $posting,
        ];
    }

    /**
     * @param array<string,mixed> $trip
     * @return array{input_id:int,created:bool}
     */
    private function upsertInput(
        int $supplierId,
        array $trip,
        string $periodStart,
        int $componentId,
        string $part,
        int $amount,
        ?int $userId,
    ): array {
        $tripId = PayrollTimeValue::int($trip['id'] ?? null, 'trip_id');
        $employmentId = PayrollTimeValue::int($trip['employment_id'] ?? null, 'employment_id');
        $externalId = "travel:{$tripId}:{$part}";
        $snapshot = [
            'business_trip_id' => $tripId,
            'classification' => $part,
            'country_code' => PayrollTimeValue::string(
                $trip['country_code'] ?? null,
                'country_code',
            ),
            // Ve snímku zůstává MÍSTNÍ čas pod původními klíči — je to to, co
            // uživatel zadal, a hash už zmaterializovaných vstupů se tím nemění.
            'departure_at' => PayrollTimeValue::string(
                $trip['departure_at_local'] ?? null,
                'departure_at_local',
            ),
            'arrival_at' => PayrollTimeValue::string(
                $trip['arrival_at_local'] ?? null,
                'arrival_at_local',
            ),
            'entitlement_total_minor' => (int) $trip['entitlement_total_minor'],
            'exempt_total_minor' => (int) $trip['exempt_total_minor'],
            'taxable_total_minor' => (int) $trip['taxable_total_minor'],
            'advance_minor' => (int) $trip['advance_minor'],
            'ruleset_id' => PayrollTimeValue::string($trip['ruleset_id'] ?? null, 'ruleset_id'),
            'calculation' => $trip['calculation'],
        ];
        // Způsob vypořádání se do snímku přidává jen tehdy, když se od dosavadního
        // chování liší — snímek cesty bez zálohy placené mzdou zůstává bajtově
        // stejný jako před zavedením vypořádání.
        if (($trip['advance_settlement'] ?? BusinessTripSettlement::MODE_PAYROLL)
            !== BusinessTripSettlement::MODE_PAYROLL
            || $part === 'advance'
        ) {
            $snapshot['advance_settlement'] = (string) $trip['advance_settlement'];
        }
        $json = CanonicalJson::encode($snapshot);
        $hash = hash('sha256', $json, true);

        $pdo = $this->db->pdo();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO payroll_inputs
                    (supplier_id, employee_id, employment_id, component_id,
                     period_start, amount_minor, source_kind, external_id,
                     source_snapshot_json, source_snapshot_hash, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, "travel", ?, ?, ?, ?)'
            );
            $stmt->execute([
                $supplierId,
                PayrollTimeValue::int($trip['employee_id'] ?? null, 'employee_id'),
                $employmentId,
                $componentId,
                $periodStart,
                $amount,
                $externalId,
                $json,
                $hash,
                $userId,
            ]);

            return ['input_id' => (int) $pdo->lastInsertId(), 'created' => true];
        } catch (PDOException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
            $existing = $this->existingInput($supplierId, $trip, $periodStart, $part);
            if ($existing === null) {
                throw $e;
            }

            return $existing;
        }
    }

    /**
     * @param array<string,mixed> $trip
     * @return array{input_id:int,created:bool}|null
     */
    private function existingInput(
        int $supplierId,
        array $trip,
        string $periodStart,
        string $part,
    ): ?array {
        $tripId = PayrollTimeValue::int($trip['id'] ?? null, 'trip_id');
        $employmentId = PayrollTimeValue::int($trip['employment_id'] ?? null, 'employment_id');
        $existing = $this->db->pdo()->prepare(
            'SELECT id
               FROM payroll_inputs
              WHERE supplier_id = ? AND employment_id = ?
                AND period_start = ? AND source_kind = "travel"
                AND external_id = ? AND status <> "cancelled"'
        );
        $existing->execute([$supplierId, $employmentId, $periodStart, "travel:{$tripId}:{$part}"]);
        $id = $existing->fetchColumn();

        return $id === false
            ? null
            : ['input_id' => PayrollTimeValue::int($id, 'input_id'), 'created' => false];
    }

    private function linkInput(int $supplierId, int $tripId, int $inputId, string $part): void
    {
        $this->db->pdo()->prepare(
            'INSERT IGNORE INTO payroll_travel_compensation_links
                (supplier_id, input_id, trip_id, source_system, source_reference,
                 classification_status)
             VALUES (?, ?, ?, ?, ?, "classified")'
        )->execute([
            $supplierId,
            $inputId,
            $tripId,
            self::SOURCE_SYSTEM,
            "trip:{$tripId}:{$part}",
        ]);
    }

    private function componentId(int $supplierId, string $code, string $periodStart): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id
               FROM payroll_component_definitions
              WHERE supplier_id = ? AND code = ? AND is_active = 1
                AND valid_from <= ?
                AND (valid_to IS NULL OR valid_to >= ?)
              ORDER BY valid_from DESC
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $code, $periodStart, $periodStart]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new \DomainException(
                "Mzdová složka {$code} není v období vyúčtování účinná.",
            );
        }

        return PayrollTimeValue::int($id, 'component_id');
    }

    private function rollbackOwned(bool $ownsTransaction): void
    {
        $pdo = $this->db->pdo();
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}
