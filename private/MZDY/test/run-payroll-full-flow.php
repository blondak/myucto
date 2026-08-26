<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Runner je dostupný pouze z příkazové řádky.\n");
    exit(2);
}

$usage = <<<'TXT'
Syntetický mzdový full-flow (výhradně lokální testovací DB)

Použití:
  php private/MZDY/test/run-payroll-full-flow.php
  php private/MZDY/test/run-payroll-full-flow.php --with-test-transport

Volba --with-test-transport spustí navíc pouze mockované kontraktní testy
ISDS/VREP pro prostředí TEST. Neprovádí síťové ani externí odeslání.
TXT;

$arguments = array_slice($argv, 1);
if (in_array('--help', $arguments, true) || in_array('-h', $arguments, true)) {
    fwrite(STDOUT, $usage . PHP_EOL);
    exit(0);
}

$allowed = ['--with-test-transport'];
$unknown = array_values(array_diff($arguments, $allowed));
if ($unknown !== []) {
    fwrite(STDERR, 'Odmítnuté argumenty: ' . implode(', ', $unknown) . PHP_EOL);
    fwrite(STDERR, "Runner nepodporuje produkční prostředí ani skutečné odeslání.\n");
    exit(2);
}

$root = dirname(__DIR__, 3);
$apiDirectory = $root . DIRECTORY_SEPARATOR . 'api';
$phpunit = $apiDirectory . DIRECTORY_SEPARATOR . 'vendor'
    . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'phpunit';
$fullFlowTest = 'tests/Integration/Payroll/PayrollSyntheticFullFlowTest.php';

foreach ([$apiDirectory, $phpunit, $apiDirectory . DIRECTORY_SEPARATOR . $fullFlowTest] as $required) {
    if (!file_exists($required)) {
        fwrite(STDERR, "Chybí požadovaná cesta: {$required}\n");
        exit(2);
    }
}

/** @param list<string> $testPaths */
$runSuite = static function (string $label, array $testPaths) use ($apiDirectory, $phpunit): int {
    fwrite(STDOUT, "\n=== {$label} ===\n");
    $command = [
        PHP_BINARY,
        $phpunit,
        ...$testPaths,
        '--colors=never',
        '--fail-on-skipped',
        '--fail-on-risky',
    ];
    $process = proc_open(
        $command,
        [0 => STDIN, 1 => STDOUT, 2 => STDERR],
        $pipes,
        $apiDirectory,
    );
    if (!is_resource($process)) {
        fwrite(STDERR, "PHPUnit se nepodařilo spustit.\n");
        return 2;
    }
    return proc_close($process);
};

fwrite(STDOUT, "BEZPEČNÝ REŽIM: pouze syntetická data, myucto_test a rollback.\n");
fwrite(STDOUT, "Externí odeslání: ZAKÁZÁNO.\n");
$exitCode = $runSuite('Mzdy HPP + DPČ + DPP a čistý HPP až po validní JMHZ TEST podání bez transportu', [
    $fullFlowTest,
]);
if ($exitCode !== 0) {
    exit($exitCode);
}

if (in_array('--with-test-transport', $arguments, true)) {
    fwrite(STDOUT, "\nTEST transport: pouze Guzzle MockHandler a čisté sestavení ISDS zprávy; síť se nepoužije.\n");
    $exitCode = $runSuite('Mockované transportní kontrakty TEST', [
        'tests/Unit/Payroll/Submission/JmhzVrepClientTest.php',
        'tests/Unit/Payroll/Submission/JmhzIsdsChannelTest.php',
        'tests/Unit/Payroll/Submission/JmhzTransportSweepServiceTest.php',
    ]);
}

if ($exitCode === 0) {
    fwrite(STDOUT, "\nFULL-FLOW OK. Pro ruční průchod pokračujte podle CHECKLIST.md.\n");
}
exit($exitCode);
