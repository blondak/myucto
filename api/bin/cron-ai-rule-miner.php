<?php

declare(strict_types=1);

define('MYINVOICE_CRON_SCRIPT', 'cron-ai-rule-miner');
if (!in_array('--dry-run', $argv, true) && !in_array('--apply', $argv, true)) {
    $argv[] = '--apply';
}
require __DIR__ . '/ai-rule-miner.php';
