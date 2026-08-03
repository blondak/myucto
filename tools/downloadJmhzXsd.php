<?php

declare(strict_types=1);

use MyInvoice\Tooling\JmhzXsdDownloader;

require_once __DIR__ . '/JmhzXsdDownloader.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script can only run from the command line.\n");
    exit(1);
}

try {
    $packages = require __DIR__ . '/jmhz-xsd-packages.php';
    if (!is_array($packages)) {
        throw new RuntimeException('The JMHZ XSD package manifest is invalid.');
    }
    $downloader = new JmhzXsdDownloader(
        $packages,
        static fn (string $message): int|false => fwrite(STDOUT, $message . PHP_EOL),
    );
    $downloader->downloadAndInstall(dirname(__DIR__) . '/api/xsd/jmhz');
} catch (Throwable $e) {
    fwrite(STDERR, 'JMHZ XSD download failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
