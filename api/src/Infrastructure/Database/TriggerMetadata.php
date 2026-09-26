<?php

declare(strict_types=1);

namespace MyInvoice\Infrastructure\Database;

use PDO;

final class TriggerMetadata
{
    public static function read(PDO $pdo, string $database): array
    {
        $rows = $pdo->query('SHOW TRIGGERS FROM `' . str_replace('`', '``', $database) . '`')->fetchAll(PDO::FETCH_ASSOC);
        $orders = [];
        $triggers = [];
        foreach ($rows as $row) {
            $group = [$row['Table'], $row['Timing'], $row['Event']];
            $key = json_encode($group, JSON_THROW_ON_ERROR);
            $orders[$key] = ($orders[$key] ?? 0) + 1;
            $triggers[] = [
                'TRIGGER_NAME' => $row['Trigger'],
                'EVENT_OBJECT_TABLE' => $row['Table'],
                'ACTION_TIMING' => $row['Timing'],
                'EVENT_MANIPULATION' => $row['Event'],
                'ACTION_ORDER' => $orders[$key],
                'ACTION_STATEMENT' => $row['Statement'],
                'SQL_MODE' => $row['sql_mode'],
                'CHARACTER_SET_CLIENT' => $row['character_set_client'],
                'COLLATION_CONNECTION' => $row['collation_connection'],
                'DATABASE_COLLATION' => $row['Database Collation'],
                'DEFINER' => $row['Definer'],
            ];
        }
        usort($triggers, static fn (array $a, array $b): int =>
            [$a['EVENT_OBJECT_TABLE'], $a['ACTION_TIMING'], $a['EVENT_MANIPULATION'], $a['ACTION_ORDER']]
            <=> [$b['EVENT_OBJECT_TABLE'], $b['ACTION_TIMING'], $b['EVENT_MANIPULATION'], $b['ACTION_ORDER']],
        );
        return $triggers;
    }
}
