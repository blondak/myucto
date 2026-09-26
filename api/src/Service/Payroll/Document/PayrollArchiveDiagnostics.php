<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Security\PayrollKeyRotationService;

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
 * - `payroll_key_rotation`: rozpracovaná rotace master klíče. Měří se jen
 *   tehdy, když je v konfiguraci nějaký předchozí klíč; bez rotace by šlo
 *   o zbytečný průchod všemi šifrovanými sloupci.
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
    ) {}

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
        if (!$this->rotationInProgress()) {
            return self::check(self::CHECK_ROTATION, 'skip', 'no_rotation', '0');
        }
        try {
            $status = $this->rotation->status();
        } catch (\Throwable) {
            return self::check(self::CHECK_ROTATION, 'skip', '?', '0');
        }
        $meta = [
            'stale_total' => $status['stale_total'],
            'unknown_total' => $status['unknown_total'],
            'targets' => array_values(array_filter(
                $status['targets'],
                static fn (array $t): bool => $t['stale'] > 0,
            )),
        ];
        if ($status['unknown_total'] > 0) {
            return self::check(self::CHECK_ROTATION, 'fail', (string) $status['unknown_total'], '0', $meta, 'unknown_key');
        }
        if ($status['stale_total'] > 0) {
            return self::check(self::CHECK_ROTATION, 'warn', (string) $status['stale_total'], '0', $meta);
        }

        // Přebaleno je všechno, rotace ale skončí až odebráním starého klíče.
        return self::check(self::CHECK_ROTATION, 'warn', '0', '0', $meta, 'retire_old_key');
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
