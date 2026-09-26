<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Security\PayrollKeyRotationService;
use MyInvoice\Service\Payroll\Security\PayrollKeyRotationStatusCache;
use Psr\Clock\ClockInterface;

/**
 * Kontroly šifrování mzdového archivu pro Systém → Diagnostika.
 *
 * Jsou oddělené od {@see \MyInvoice\Service\System\EnvironmentCheckService},
 * protože měří mzdová data, ne prostředí instalace; do reportu se ale
 * připojují ve stejném tvaru, takže je UI vykreslí mezi ostatními kontrolami
 * a započtou se do verdiktu. Stejně jako tam platí, že kontrola nesmí
 * spadnout: selhání měření je `skip`.
 *
 * - `payroll_archive_encryption`: počet nešifrovaných dokumentů z doby před
 *   šifrováním (jen čtení disku, levné).
 * - `payroll_key_rotation`: data pod klíčem, který konfigurace nezná, a
 *   rozpracovaná rotace master klíče. Ve třech vrstvách:
 *   1. VŽDY levně ({@see PayrollKeyRotationService::quickStatus()}): datové
 *      klíče dokumentů a nejstarší a nejnovější šifrovaný řádek každé tabulky.
 *      Chytí výměnu klíče bez předchozího i databázi z jiné instance, tedy
 *      přesně stavy, kdy `previous_keys` bývá prázdné a dřív se nic neměřilo.
 *   2. Za rotace (neprázdné `previous_keys`) plný průchod, výsledek se drží
 *      v cache ({@see PayrollKeyRotationStatusCache}) a obnovuje nejvýš
 *      jednou za hodinu.
 *   3. Ručně tlačítkem „Změřit úplně" ({@see measureRotation()}) kdykoli,
 *      výsledek do téže cache. Stáří měření je v `meta.measured_at`.
 */
final class PayrollArchiveDiagnostics
{
    public const CHECK_ARCHIVE = 'payroll_archive_encryption';
    public const CHECK_ROTATION = 'payroll_key_rotation';
    private const MANUAL = '999_Reseni_problemu';

    public function __construct(
        private readonly PayrollArchiveReencryptionService $archive,
        private readonly PayrollKeyRotationService $rotation,
        private readonly Config $config,
        private readonly Connection $db,
        private readonly PayrollKeyRotationStatusCache $rotationCache,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * Plné měření na žádost (Diagnostika, tlačítko „Změřit úplně"): projde
     * všechny šifrované hodnoty i soubory exportů a výsledek uloží do cache.
     *
     * @return array<string,mixed> kontrola ve tvaru reportu
     */
    public function measureRotation(): array
    {
        $this->rotationCache->put($this->rotation->status(), $this->now());

        return $this->rotationCheck();
    }

    /**
     * Připojí kontroly k reportu Diagnostiky a přepočítá souhrn.
     *
     * @param array{summary:array<string,mixed>,checks:list<array<string,mixed>>} $report
     * @return array{summary:array<string,mixed>,checks:list<array<string,mixed>>}
     */
    public function appendTo(array $report): array
    {
        foreach ($this->checks() as $check) {
            $report['checks'][] = $check;
            $status = (string) $check['status'];
            $report['summary'][$status] = (int) ($report['summary'][$status] ?? 0) + 1;
        }
        $report['summary']['status'] = ($report['summary']['fail'] ?? 0) > 0
            ? 'fail'
            : (($report['summary']['warn'] ?? 0) > 0 ? 'warn' : 'ok');

        return $report;
    }

    /** @return list<array<string,mixed>> */
    public function checks(): array
    {
        return [$this->archiveCheck(), $this->rotationCheck()];
    }

    /** @return array<string,mixed> */
    private function archiveCheck(): array
    {
        try {
            $legacy = $this->archive->countLegacy();
        } catch (\Throwable) {
            return self::check(self::CHECK_ARCHIVE, 'skip', '?', '0');
        }
        $names = $this->supplierNames(array_keys($legacy['suppliers']));
        $suppliers = [];
        foreach ($legacy['suppliers'] as $supplierId => $files) {
            $suppliers[] = [
                'supplier_id' => $supplierId,
                'name' => $names[$supplierId] ?? null,
                'files' => $files,
            ];
        }

        return self::check(
            self::CHECK_ARCHIVE,
            $legacy['total'] > 0 ? 'warn' : 'ok',
            (string) $legacy['total'],
            '0',
            ['legacy_files' => $legacy['total'], 'suppliers' => $suppliers],
        );
    }

    /** @return array<string,mixed> */
    private function rotationCheck(): array
    {
        try {
            $quick = $this->rotation->quickStatus();
        } catch (\Throwable) {
            return self::check(self::CHECK_ROTATION, 'skip', '?', '0');
        }
        $rotation = $this->rotationInProgress();
        $full = $this->rotationCache->get();
        if ($rotation && ($full === null || !PayrollKeyRotationStatusCache::isFresh($full, $this->now()))) {
            try {
                $full = $this->rotationCache->put($this->rotation->status(), $this->now());
            } catch (\Throwable) {
                $full = null;
            }
        }
        $status = $full['status'] ?? null;
        // Uložené měření platí jen pro klíče, které konfigurace pořád nezná.
        // Doplní-li správce chybějící klíč, starý nález nesmí dál svítit.
        $fullUnknown = 0;
        if (is_array($status)) {
            $stillUnknown = array_filter(
                array_map('strval', (array) ($status['unknown_key_ids'] ?? [])),
                fn (string $keyId): bool => !$this->rotation->isKnownKeyId($keyId),
            );
            $fullUnknown = $stillUnknown === [] ? 0 : (int) ($status['unknown_total'] ?? 0);
        }
        $stale = $rotation && is_array($status) ? (int) ($status['stale_total'] ?? 0) : 0;
        $meta = [
            'mode' => $rotation ? 'full' : 'quick',
            'measured_at' => $full['measured_at'] ?? null,
            'sampled' => $quick['sampled'],
            'quick_unknown' => $quick['unknown_total'],
            'stale_total' => $stale,
            'unknown_total' => max($fullUnknown, $quick['unknown_total']),
            'targets' => $rotation && is_array($status)
                ? array_values(array_filter(
                    (array) ($status['targets'] ?? []),
                    static fn (array $t): bool => (int) ($t['stale'] ?? 0) > 0,
                ))
                : [],
            'unknown_targets' => $quick['targets'],
        ];
        if ($quick['unknown_total'] > 0 || $fullUnknown > 0) {
            return self::check(
                self::CHECK_ROTATION,
                'fail',
                (string) max($fullUnknown, $quick['unknown_total']),
                '0',
                $meta,
                'unknown_key',
            );
        }
        if (!$rotation) {
            // Bez rotace stačí, že levná kontrola nenašla cizí klíč.
            return self::check(self::CHECK_ROTATION, 'ok', '0', '0', $meta, 'quick');
        }
        if ($status === null) {
            return self::check(self::CHECK_ROTATION, 'skip', '?', '0', $meta);
        }
        if ($stale > 0) {
            return self::check(self::CHECK_ROTATION, 'warn', (string) $stale, '0', $meta);
        }

        // Přebaleno je všechno, rotace ale skončí až odebráním starého klíče.
        return self::check(self::CHECK_ROTATION, 'warn', '0', '0', $meta, 'retire_old_key');
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }

    /**
     * Název firmy k jejímu id, ať správce pozná, čí archiv to je.
     *
     * @param list<int> $ids
     * @return array<int,string>
     */
    private function supplierNames(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        try {
            $stmt = $this->db->pdo()->prepare(
                'SELECT id, COALESCE(NULLIF(display_name, ""), company_name) FROM supplier WHERE id IN ('
                    . implode(',', array_fill(0, count($ids), '?')) . ')',
            );
            $stmt->execute($ids);

            return array_map('strval', $stmt->fetchAll(\PDO::FETCH_KEY_PAIR));
        } catch (\Throwable) {
            return [];
        }
    }

    private function rotationInProgress(): bool
    {
        $previous = $this->config->get('app.secret_encryption_previous_keys', []);
        if (is_string($previous)) {
            return trim($previous) !== '';
        }

        return is_array($previous) && array_filter($previous, static fn (mixed $v): bool => is_string($v) && trim($v) !== '') !== [];
    }

    /**
     * @param array<string,mixed> $meta
     * @return array<string,mixed>
     */
    private static function check(
        string $id,
        string $status,
        string $actual,
        string $expected,
        array $meta = [],
        string $variant = '',
    ): array {
        $check = [
            'id' => $id,
            'status' => $status,
            'actual' => $actual,
            'expected' => $expected,
            'info' => '',
            'manual' => self::MANUAL,
            'meta' => $meta,
        ];
        if ($variant !== '') {
            $check['variant'] = $variant;
        }

        return $check;
    }
}
