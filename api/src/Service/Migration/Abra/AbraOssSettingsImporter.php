<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Abra;

use MyInvoice\Infrastructure\Database\Connection;

final class AbraOssSettingsImporter
{
    public function __construct(private readonly Connection $db) {}

    public static function sourceState(array $versions, array $years): array
    {
        $year = max($years);
        $today = date('Y-m-d');
        $asOf = min($today, sprintf('%04d-12-31', $year));
        $ordered = [];
        foreach ($versions as $version) {
            if (!is_array($version)) continue;
            $raw = trim((string) ($version['platiOdData'] ?? ''));
            $from = $raw === '' ? '' : AbraSource::date($raw);
            if ($from === null || $from > $asOf) continue;
            $ordered[] = ['from' => $from, 'settings' => $version];
        }
        usort($ordered, static fn (array $a, array $b): int => strcmp($a['from'], $b['from']));
        $settings = [];
        $activeFrom = null;
        foreach ($ordered as $entry) {
            $settings = $entry['settings'];
            $eu = AbraSource::bool($settings['ossEU'] ?? null);
            if ($eu === true && $activeFrom === null) $activeFrom = $entry['from'];
            if ($eu !== true) $activeFrom = null;
        }

        return [
            'eu' => AbraSource::bool($settings['ossEU'] ?? null),
            'non_eu' => AbraSource::bool($settings['ossMimoEU'] ?? null),
            'import' => AbraSource::bool($settings['ossDovoz'] ?? null),
            'valid_from' => $activeFrom !== '' ? $activeFrom : null,
        ];
    }

    public function apply(int $supplierId, array $state): bool
    {
        if (($state['eu'] ?? null) !== true) return false;
        $stmt = $this->db->pdo()->prepare('UPDATE supplier s
            LEFT JOIN countries c ON c.id = s.country_id
            SET s.oss_enabled = 1,
                s.oss_valid_from = ?,
                s.oss_valid_to = NULL,
                s.oss_identification_country = COALESCE(s.oss_identification_country, UPPER(c.iso2))
            WHERE s.id = ? AND s.oss_enabled = 0');
        $stmt->execute([$state['valid_from'] ?? null, $supplierId]);
        return $stmt->rowCount() === 1;
    }
}
