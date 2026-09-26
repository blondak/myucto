<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Stav podání se z protokolu ČSSZ bere jedinou cestou,
 * `JmhzProtocolReport::payrollRemoteStatus()`. Ta ví, že protokol „originál je
 * u ČSSZ" (kontrola 22, shodné podání už existuje) podání nezamítá.
 *
 * Přímé `$report->status->payrollRemoteStatus()` by takový protokol vzalo
 * jako zamítnutí; účetní by pak podání zahodila a poslala znovu s novým GUID,
 * tedy opravdovou duplicitu. Mapa stavu je proto dovolená jen uvnitř reportu
 * a u výsledku jednotlivých součástí (verifier protokolu), ne u celého podání.
 */
final class JmhzProtocolRemoteStatusSsotTest extends TestCase
{
    public function testSubmissionRemoteStatusIsTakenOnlyFromTheReport(): void
    {
        $root = dirname(__DIR__, 2) . '/src';
        $offenders = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (str_contains($source, 'report->status->payrollRemoteStatus()')) {
                $offenders[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }

        self::assertSame([], $offenders, 'Stav podání z protokolu ber přes JmhzProtocolReport::payrollRemoteStatus().');
    }
}
