<?php
declare(strict_types=1);
namespace MyInvoice\Service\Migration\Abra;

use MyInvoice\Infrastructure\Config\RuntimePaths;

final class AbraRequestBudget
{
    private readonly int $dailyLimit;

    public function __construct(?int $dailyLimit = null)
    {
        if ($dailyLimit === null) {
            $configured = getenv('MYINVOICE_ABRA_DAILY_REQUEST_LIMIT');
            if ($configured !== false && $configured !== '') {
                $dailyLimit = filter_var($configured, FILTER_VALIDATE_INT);
                if ($dailyLimit === false) throw new \InvalidArgumentException('Invalid ABRA daily request limit.');
            } else {
                $dailyLimit = 1000;
            }
        }
        if ($dailyLimit < 1 || $dailyLimit > 50000) throw new \InvalidArgumentException('Invalid ABRA daily request limit.');
        $this->dailyLimit = $dailyLimit;
    }

    public function reserve(string $host): void
    {
        $this->update($host, function (array &$state): void {
            if (($state['blocked_until'] ?? 0) > time()) {
                throw new AbraException('rate_limited', 'ABRA Flexi dočasně omezila požadavky. Vyčkejte před dalším spuštěním.', [], 429);
            }
            $day = gmdate('Y-m-d');
            if (($state['day'] ?? '') !== $day) $state = ['day' => $day, 'count' => 0];
            if (($state['count'] ?? 0) >= $this->dailyLimit) {
                throw new AbraException('request_budget', 'Převody vyčerpaly místní denní rozpočet požadavků na tento server ABRA Flexi. Pokračujte další den.', [], 429);
            }
            $state['count'] = (int) ($state['count'] ?? 0) + 1;
        });
    }

    public function cooldown(string $host, int $seconds = 600): void
    {
        $this->update($host, static function (array &$state) use ($seconds): void {
            $state['blocked_until'] = max((int) ($state['blocked_until'] ?? 0), time() + max(60, min(86400, $seconds)));
        });
    }

    private function update(string $host, callable $change): void
    {
        $dir = RuntimePaths::storage('abra-flexi/request-budget');
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new AbraException('budget_storage', 'Nelze bezpečně evidovat počet požadavků ABRA Flexi.');
        }
        $file = @fopen($dir . '/' . hash('sha256', strtolower($host)) . '.json', 'c+');
        if ($file === false) throw new AbraException('budget_storage', 'Nelze bezpečně evidovat počet požadavků ABRA Flexi.');
        try {
            if (!flock($file, LOCK_EX)) throw new AbraException('budget_storage', 'Nelze bezpečně evidovat počet požadavků ABRA Flexi.');
            $raw = stream_get_contents($file);
            $state = $raw === '' ? [] : json_decode($raw, true);
            if (!is_array($state)) throw new AbraException('budget_storage', 'Evidence počtu požadavků ABRA Flexi je poškozená.');
            $change($state);
            $encoded = json_encode($state, JSON_THROW_ON_ERROR);
            rewind($file);
            if (!ftruncate($file, 0) || fwrite($file, $encoded) !== strlen($encoded) || !fflush($file)) {
                throw new AbraException('budget_storage', 'Nelze bezpečně evidovat počet požadavků ABRA Flexi.');
            }
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }
    }
}
