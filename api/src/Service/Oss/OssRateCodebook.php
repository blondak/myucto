<?php

declare(strict_types=1);

namespace MyInvoice\Service\Oss;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Číselník sazeb DPH členských států pro OSS (§ 110 a násl. ZDPH).
 *
 * Systém žádný neměl: sazbu pro zemi spotřeby si uživatel zakládal ručně v obecné tabulce
 * `vat_rates` a jediná kontrola byla vnitřní konzistence `základ × sazba ≈ daň`. Použití
 * německých 19 % na rakouské plnění tedy prošlo bez varování — čísla si odpovídala, jen
 * mířila do nesprávného státu.
 *
 * ── Varuje, neblokuje ───────────────────────────────────────────────────────────────
 * Seed číselníku je platný ke dni migrace a nevyhnutelně zestárne. Tvrdé odmítnutí by po
 * první změně sazby v kterémkoli členském státě znemožnilo vystavit legitimní doklad —
 * to je horší než dnešní stav, protože rozbité je hůř obejitelné než chybějící. Kontrola
 * proto vrací VAROVÁNÍ a uživatel si sazbu může doplnit (`is_custom`).
 *
 * ── Platnost k datu ─────────────────────────────────────────────────────────────────
 * Sazby se mění a podání se opravuje zpětně, proto se hledá vždy sazba platná K DATU
 * PLNĚNÍ. Bez toho by oprava staršího období dostala dnešní sazbu a hlásila neexistující
 * chybu.
 *
 * ── Správa číselníku (migrace 1296) ─────────────────────────────────────────────────
 * Číselník je AUTORITA, proti které se ověřuje sazba na dokladu — na rozdíl od `vat_rates`,
 * což je uživatelský číselník sazeb PRO doklad. Autoritu, kterou si smí kdokoli přepsat,
 * není proti čemu ověřovat, proto zápis vyžaduje superadmina (viz
 * {@see \MyInvoice\Action\Codebook\OssMemberStateRatesAction}) a uživatelský zásah je vždy
 * poznat: buď `is_custom = 1` u vlastního řádku, nebo `valid_to_override` / `disabled_at`
 * jako překryv nad seedem. Seedovaná data se nikdy nepřepisují — viz komentář migrace 1296.
 */
final class OssRateCodebook
{
    /** Tolerance porovnání sazby v procentních bodech (DECIMAL(5,2) vs. float). */
    private const EPSILON = 0.005;

    /** Hodnoty ENUM `rate_type` — taxonomie číselníku, ne národní názvosloví. */
    public const RATE_TYPES = ['standard', 'reduced', 'second_reduced', 'parking'];

    private ?bool $manageable = null;

    public function __construct(private readonly Connection $db) {}

    public function isAvailable(): bool
    {
        return $this->db->hasTable('oss_member_state_rates');
    }

    /**
     * Umí instance číselník i SPRAVOVAT? Bez migrace 1296 chybí překryvné sloupce, takže
     * čtení funguje dál, ale CRUD se musí odmítnout — jinak by zápis spadl na neznámý sloupec.
     */
    public function isManageable(): bool
    {
        if ($this->manageable === null) {
            $this->manageable = $this->isAvailable()
                && $this->db->hasColumn('oss_member_state_rates', 'disabled_at')
                && $this->db->hasColumn('oss_member_state_rates', 'valid_to_override');
        }
        return $this->manageable;
    }

    /**
     * Sazby platné pro zemi k datu.
     *
     * @return list<array{rate_type:string, rate_percent:float}>
     */
    public function ratesFor(string $country, string $onDate): array
    {
        if (!$this->isAvailable()) {
            return [];
        }
        // Efektivní konec platnosti bere v potaz uživatelské zkrácení (migrace 1296);
        // vyřazený řádek se neověřuje vůbec. Bez migrace zůstávají holé seedované sloupce.
        [$validTo, $activeFilter] = $this->isManageable()
            ? ['COALESCE(valid_to_override, valid_to)', ' AND disabled_at IS NULL']
            : ['valid_to', ''];

        $stmt = $this->db->pdo()->prepare(
            "SELECT rate_type, rate_percent
               FROM oss_member_state_rates
              WHERE country = ?
                AND valid_from <= ?
                AND ({$validTo} IS NULL OR {$validTo} >= ?){$activeFilter}
           ORDER BY rate_percent DESC"
        );
        $stmt->execute([strtoupper($country), $onDate, $onDate]);

        return array_map(static fn ($r) => [
            'rate_type'    => (string) $r['rate_type'],
            'rate_percent' => (float) $r['rate_percent'],
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Členské státy EU, pro které číselník K ZADANÉMU DATU (výchozí dnešek) nemá žádnou
     * platnou sazbu — ani systémovou, ani vlastní.
     *
     * ── Proč tohle existuje ──────────────────────────────────────────────────────────
     * Hlášení zákazníka (viz migrace 1319): po upgradu měl číselník jen 23 řádků, skoro
     * výhradně historické, a chybělo Polsko úplně. `checkRate()` a `OssItemDeriver` na to
     * reagují SPRÁVNĚ per řádek — „stát v číselníku není" u KAŽDÉ položky zvlášť — ale
     * u 850 importovaných dokladů se z toho stane 850 stejných hlášek a nikdo nespojí
     * tečky k jediné příčině: neúplný seed. Tahle metoda dá tu souvislost NA JEDNO MÍSTO
     * (číselníková stránka), aby uživatel dostal jednu větu „doplňte seed", ne stovky
     * řádkových varování bez kontextu.
     *
     * Nerozlišuje „stát v tabulce vůbec není" od „má jen historické řádky" — z pohledu
     * uživatele je to totéž: dnešní doklad do/z téhle země skončí u ručního posouzení.
     *
     * Zdroj pravdy pro „který stát je členský" je tabulka `countries.is_eu` — stejná,
     * kterou už používá {@see \MyInvoice\Tests\Integration\Oss\OssRateCodebookTest::testEveryEuCountryHasStandardRate()},
     * ne pevný seznam v kódu: instance s upraveným číselníkem zemí (např. po Brexitu,
     * nebo budoucí rozšíření EU) se tím nerozejde s tím, co migrace skutečně seedují.
     *
     * @return list<string> ISO2 kódy, abecedně
     */
    public function countriesMissingCurrentRate(?string $onDate = null): array
    {
        if (!$this->isAvailable()) {
            return [];
        }
        $eu = $this->db->pdo()
            ->query('SELECT UPPER(iso2) FROM countries WHERE is_eu = 1 ORDER BY iso2')
            ->fetchAll(\PDO::FETCH_COLUMN);
        if ($eu === []) {
            return [];
        }
        $onDate ??= (new \DateTimeImmutable())->format('Y-m-d');

        $missing = [];
        foreach ($eu as $code) {
            if ($this->ratesFor((string) $code, $onDate) === []) {
                $missing[] = (string) $code;
            }
        }

        return $missing;
    }

    /**
     * Ověří sazbu použitou na OSS řádku proti číselníku. Vrací varování, nebo `null`,
     * je-li vše v pořádku (nebo nelze-li ověřit).
     *
     * @param ?string $rateType deklarovaný typ sazby (standard/reduced/…)
     */
    public function checkRate(string $country, float $rate, ?string $rateType, string $onDate): ?string
    {
        $country = strtoupper(trim($country));
        if ($country === '' || $country === '??') {
            return null; // Chybějící zemi hlásí jiné varování, nedubluj ho.
        }

        // CHYBĚJÍCÍ MIGRACE ≠ CHYBĚJÍCÍ STÁT. Bez tabulky vrací `ratesFor()` prázdno pro
        // KAŽDOU zemi, takže původní hláška tvrdila „stát není v číselníku" i o Německu.
        // To je nepravda a posílá uživatele hledat chybu na dokladu místo v instalaci.
        if (!$this->isAvailable()) {
            return sprintf(
                'Sazbu %s %% pro %s nelze ověřit — číselník sazeb členských států v databázi vůbec není. '
                    . 'Chybí migrace 1152: spusťte `php api/bin/migrate.php`. Do té doby se neověřuje žádný stát.',
                self::fmt($rate),
                $country,
            );
        }

        $known = $this->ratesFor($country, $onDate);
        if ($known === []) {
            // Stát v číselníku není (nový členský stát, neúplný seed). Mlčet by budilo
            // dojem, že sazba byla ověřena — a ona nebyla.
            return sprintf(
                'Sazbu %s %% pro %s nelze ověřit — stát není v číselníku sazeb členských států. '
                    . 'Doplňte jeho sazby, nebo ověřte plnění ručně.',
                self::fmt($rate),
                $country,
            );
        }

        $match = null;
        foreach ($known as $k) {
            if (abs($k['rate_percent'] - $rate) <= self::EPSILON) {
                $match = $k;
                break;
            }
        }

        if ($match === null) {
            return sprintf(
                'Sazba %s %% neodpovídá žádné sazbě platné v %s k %s (platné: %s). '
                    . 'Ověřte sazbu státu spotřeby — číselník nemusí být aktuální.',
                self::fmt($rate),
                $country,
                (new \DateTimeImmutable($onDate))->format('j. n. Y'),
                implode(', ', array_map(static fn ($k) => self::fmt($k['rate_percent']) . ' %', $known)),
            );
        }

        // Sazba sedí, ale je deklarovaná jako jiný typ — do podání jde typ, ne procento
        // ({@see OssXmlExporter::rateTypeCode()}), takže rozpor by skončil ve výkazu.
        if ($rateType !== null && $rateType !== '' && $rateType !== $match['rate_type']) {
            return sprintf(
                'Sazba %s %% je v %s vedena jako „%s", ale doklad ji deklaruje jako „%s" — '
                    . 'do podání se posílá TYP sazby, ne procento.',
                self::fmt($rate),
                $country,
                $match['rate_type'],
                $rateType,
            );
        }

        return null;
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Správa číselníku (OSS-9)
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Celý číselník pro správu — včetně vyřazených a s efektivní platností.
     *
     * @return list<array<string,mixed>>
     */
    public function listAll(?string $country = null): array
    {
        if (!$this->isAvailable()) {
            return [];
        }
        $manageable = $this->isManageable();
        $overlay = $manageable
            ? 'valid_to_override, disabled_at, created_by, updated_at, updated_by'
            : 'NULL AS valid_to_override, NULL AS disabled_at, NULL AS created_by, NULL AS updated_at, NULL AS updated_by';

        $where = '';
        $params = [];
        if ($country !== null && $country !== '') {
            $where = ' WHERE country = ?';
            $params[] = strtoupper(trim($country));
        }

        $stmt = $this->db->pdo()->prepare(
            "SELECT id, country, rate_type, rate_percent, valid_from, valid_to, is_custom, note, created_at, {$overlay}
               FROM oss_member_state_rates{$where}
           ORDER BY country, rate_type, valid_from DESC, rate_percent DESC"
        );
        $stmt->execute($params);

        return array_map(static function (array $r): array {
            $validTo = $r['valid_to'] !== null ? (string) $r['valid_to'] : null;
            $override = $r['valid_to_override'] !== null ? (string) $r['valid_to_override'] : null;
            return [
                'id'                => (int) $r['id'],
                'country'           => (string) $r['country'],
                'rate_type'         => (string) $r['rate_type'],
                'rate_percent'      => (float) $r['rate_percent'],
                'valid_from'        => (string) $r['valid_from'],
                'valid_to'          => $validTo,
                'valid_to_override' => $override,
                'effective_valid_to' => $override ?? $validTo,
                'is_custom'         => (bool) $r['is_custom'],
                'disabled'          => $r['disabled_at'] !== null,
                'disabled_at'       => $r['disabled_at'] !== null ? (string) $r['disabled_at'] : null,
                'note'              => $r['note'] !== null ? (string) $r['note'] : null,
                'created_at'        => (string) $r['created_at'],
                'updated_at'        => $r['updated_at'] !== null ? (string) $r['updated_at'] : null,
            ];
        }, $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare('SELECT * FROM oss_member_state_rates WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Založí VLASTNÍ sazbu (`is_custom = 1`). Seedovaná sazba se takhle nikdy nevytvoří —
     * jinak by ji další spuštění migrace přestalo poznávat jako svou.
     *
     * @param array{country:string, rate_type:string, rate_percent:float, valid_from:string,
     *              valid_to?:?string, note?:?string} $data
     *
     * @throws \InvalidArgumentException neplatný vstup
     * @throws \RuntimeException         číselník nelze spravovat / duplicita
     */
    public function createCustom(array $data, ?int $userId): int
    {
        $this->assertManageable();
        $v = self::validate($data);

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO oss_member_state_rates
                (country, rate_type, rate_percent, valid_from, valid_to, is_custom, note, created_by)
             VALUES (?, ?, ?, ?, ?, 1, ?, ?)'
        );
        try {
            $stmt->execute([
                $v['country'], $v['rate_type'], $v['rate_percent'],
                $v['valid_from'], $v['valid_to'], $v['note'], $userId,
            ]);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate') || str_contains($e->getMessage(), 'UNIQUE')) {
                throw new \RuntimeException(sprintf(
                    'Sazba %s %% typu „%s" pro %s s platností od %s už v číselníku je.',
                    self::fmt($v['rate_percent']), $v['rate_type'], $v['country'], $v['valid_from'],
                ), 409, $e);
            }
            throw $e;
        }

        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * Úprava řádku. VLASTNÍ řádek se mění celý; SEEDOVANÝ jen překryvem
     * (`valid_to_override`, `disabled_at`) — důvod je v komentáři migrace 1296.
     *
     * @param array<string,mixed> $data
     *
     * @throws \InvalidArgumentException neplatný vstup
     * @throws \RuntimeException         řádek nenalezen / zásah do identity seedu
     */
    public function update(int $id, array $data, ?int $userId): void
    {
        $this->assertManageable();
        $row = $this->find($id);
        if ($row === null) {
            throw new \RuntimeException('Sazba v číselníku neexistuje.', 404);
        }

        $sets = [];
        $params = [];

        if (array_key_exists('disabled', $data)) {
            $sets[] = 'disabled_at = ' . ($data['disabled'] ? 'CURRENT_TIMESTAMP' : 'NULL');
        }
        if (array_key_exists('valid_to_override', $data)) {
            $override = self::nullableDate($data['valid_to_override'], 'valid_to_override');
            if ($override !== null && $override < (string) $row['valid_from']) {
                throw new \InvalidArgumentException(
                    'Konec platnosti nesmí předcházet jejímu začátku — pro vyřazení řádku slouží „vyřadit".'
                );
            }
            $sets[] = 'valid_to_override = ?';
            $params[] = $override;
        }

        if (!(bool) $row['is_custom']) {
            // Seed má vlastní data nedotknutelná: kdyby se změnila čtveřice
            // (country, rate_type, rate_percent, valid_from), přestala by migrace řádek
            // poznávat a při dalším běhu by seed vložila ZNOVU vedle uživatelovy verze.
            foreach (['country', 'rate_type', 'rate_percent', 'valid_from', 'valid_to', 'note'] as $locked) {
                if (array_key_exists($locked, $data)) {
                    throw new \RuntimeException(
                        'Seedovaný řádek číselníku se needituje. Zkraťte mu platnost, nebo ho vyřaďte '
                            . 'a založte vlastní sazbu — seed se tím nerozbije a přežije další migrace.',
                        409,
                    );
                }
            }
        } else {
            $v = self::validate(array_merge([
                'country'      => (string) $row['country'],
                'rate_type'    => (string) $row['rate_type'],
                'rate_percent' => (float) $row['rate_percent'],
                'valid_from'   => (string) $row['valid_from'],
                'valid_to'     => $row['valid_to'],
                'note'         => $row['note'],
            ], array_intersect_key($data, array_flip(
                ['country', 'rate_type', 'rate_percent', 'valid_from', 'valid_to', 'note']
            ))));

            $sets[] = 'country = ?';      $params[] = $v['country'];
            $sets[] = 'rate_type = ?';    $params[] = $v['rate_type'];
            $sets[] = 'rate_percent = ?'; $params[] = $v['rate_percent'];
            $sets[] = 'valid_from = ?';   $params[] = $v['valid_from'];
            $sets[] = 'valid_to = ?';     $params[] = $v['valid_to'];
            $sets[] = 'note = ?';         $params[] = $v['note'];
        }

        if ($sets === []) {
            return;
        }
        $sets[] = 'updated_by = ?';
        $params[] = $userId;
        $params[] = $id;

        $stmt = $this->db->pdo()->prepare(
            'UPDATE oss_member_state_rates SET ' . implode(', ', $sets) . ' WHERE id = ?'
        );
        try {
            $stmt->execute($params);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate') || str_contains($e->getMessage(), 'UNIQUE')) {
                throw new \RuntimeException('Sazba se stejnou zemí, typem, procentem a začátkem platnosti už existuje.', 409, $e);
            }
            throw $e;
        }
    }

    /**
     * Smaže VLASTNÍ řádek. Seed se nemaže: příští migrace by ho stejně vrátila, takže
     * mazání by jen předstíralo účinek — pro seed slouží vyřazení (`disabled`).
     *
     * @throws \RuntimeException
     */
    public function delete(int $id): void
    {
        $this->assertManageable();
        $row = $this->find($id);
        if ($row === null) {
            throw new \RuntimeException('Sazba v číselníku neexistuje.', 404);
        }
        if (!(bool) $row['is_custom']) {
            throw new \RuntimeException(
                'Seedovaný řádek nelze smazat — další spuštění migrace by ho vrátilo. Vyřaďte ho.',
                409,
            );
        }
        $stmt = $this->db->pdo()->prepare('DELETE FROM oss_member_state_rates WHERE id = ? AND is_custom = 1');
        $stmt->execute([$id]);
    }

    private function assertManageable(): void
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException(
                'Číselník sazeb členských států v databázi není — chybí migrace 1152. '
                    . 'Spusťte `php api/bin/migrate.php`.',
                409,
            );
        }
        if (!$this->isManageable()) {
            throw new \RuntimeException(
                'Číselník sazeb členských států zatím nelze spravovat — chybí migrace 1296. '
                    . 'Spusťte `php api/bin/migrate.php`.',
                409,
            );
        }
    }

    /**
     * @param array<string,mixed> $data
     * @return array{country:string, rate_type:string, rate_percent:float, valid_from:string, valid_to:?string, note:?string}
     */
    private static function validate(array $data): array
    {
        $country = strtoupper(trim((string) ($data['country'] ?? '')));
        if (!preg_match('/^[A-Z]{2}$/', $country)) {
            throw new \InvalidArgumentException('country: dvoupísmenný ISO2 kód státu.');
        }
        $rateType = (string) ($data['rate_type'] ?? '');
        if (!in_array($rateType, self::RATE_TYPES, true)) {
            throw new \InvalidArgumentException('rate_type: ' . implode(' | ', self::RATE_TYPES) . '.');
        }
        if (!is_numeric($data['rate_percent'] ?? null)) {
            throw new \InvalidArgumentException('rate_percent: číslo v procentech.');
        }
        $ratePercent = round((float) $data['rate_percent'], 2);
        // Horní mez je 99,99 kvůli DECIMAL(5,2); nula je legitimní (osvobozená plnění
        // s nárokem na odpočet vedou některé státy jako sazbu 0 %).
        if ($ratePercent < 0 || $ratePercent > 99.99) {
            throw new \InvalidArgumentException('rate_percent: hodnota mimo rozsah 0–99,99.');
        }
        $validFrom = self::nullableDate($data['valid_from'] ?? null, 'valid_from');
        if ($validFrom === null) {
            throw new \InvalidArgumentException('valid_from: datum ve tvaru RRRR-MM-DD.');
        }
        $validTo = self::nullableDate($data['valid_to'] ?? null, 'valid_to');
        if ($validTo !== null && $validTo < $validFrom) {
            throw new \InvalidArgumentException('valid_to nesmí předcházet valid_from.');
        }
        $note = isset($data['note']) && trim((string) $data['note']) !== ''
            ? mb_substr(trim((string) $data['note']), 0, 190)
            : null;

        return [
            'country'      => $country,
            'rate_type'    => $rateType,
            'rate_percent' => $ratePercent,
            'valid_from'   => $validFrom,
            'valid_to'     => $validTo,
            'note'         => $note,
        ];
    }

    private static function nullableDate(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $s = (string) $value;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) || (new \DateTimeImmutable($s))->format('Y-m-d') !== $s) {
            throw new \InvalidArgumentException($field . ': datum ve tvaru RRRR-MM-DD.');
        }
        return $s;
    }

    private static function fmt(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 2, ',', ' '), '0'), ',');
    }
}
